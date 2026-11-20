<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Media\VariantPhoto;
use App\Domain\Sales\InvoiceDocument;
use App\Domain\Sales\OrderInvoice;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\SalesService;
use App\Domain\Sales\WhatsAppTemplateDispatcher;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductImageResource;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesController extends Controller
{
    public function requestStatus(Request $request, StoreContext $context, SalesService $service)
    {
        $data = $request->validate(['action' => ['nullable', 'in:create,invoice,payment,deliver,cancel,remove']]);
        $record = DB::table('idempotency_requests')->where('operation', 'sales.'.($data['action'] ?? 'create'))->where('request_key', $request->route('key'))->where('status', 'completed')->firstOrFail();
        $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($result['_identity'][0] ?? null) === $request->user()->id && ($result['_identity'][1] ?? null) === $context->store()->id, 404);
        $order = SalesOrder::where('store_id', $context->store()->id)->findOrFail($result['order']['id']);
        $invoice = OrderInvoice::query($order->id)->first();

        return response()->json(['data' => ['order' => $service->present($order), 'replay' => true, 'document' => $invoice ? ['status' => DB::table('invoice_documents')->where('invoice_id', $invoice->id)->where('version', 1)->value('status')] : null]]);
    }

    public function sellers(Request $request, StoreContext $context)
    {
        $sellers = DB::table('employees as e')
            ->join('employee_store_assignments as esa', 'esa.employee_id', '=', 'e.id')
            ->where('esa.store_id', $context->store()->id)
            ->where('e.status', 'active')
            ->where('e.can_sell', true)
            ->orderBy('e.full_name')
            ->orderBy('e.id')
            ->get(['e.id', 'e.employee_code', 'e.full_name']);

        return response()->json(['data' => $sellers]);
    }

    public function catalog(Request $request, StoreContext $context)
    {
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'q' => ['nullable', 'string', 'max:120'], 'category_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1'], 'sort' => ['nullable', 'in:newest,name']]);
        $warehouse = Warehouse::where('store_id', $context->store()->id)->where('is_active', true)->findOrFail($data['warehouse_id']);
        $query = Product::with(['category', 'images.asset.renditions', 'variants' => fn ($q) => $q->where('is_active', true)->with('attributeValues.attribute')->orderBy('id')])->where('store_id', $context->store()->id)->where('status', 'active')->whereHas('variants', fn ($q) => $q->where('is_active', true));
        if (! empty($data['category_id'])) {
            $query->where('category_id', $data['category_id']);
        }
        if (! empty($data['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])->orWhereHas('category', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))->orWhereHas('variants', fn ($v) => $v->where('is_active', true)->where(fn ($codes) => $codes->whereRaw("sku LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("barcode LIKE ? ESCAPE '!'", [$pattern]))));
        }
        $page = ($data['sort'] ?? 'newest') === 'name' ? $query->orderBy('name')->paginate(24) : $query->orderByDesc('id')->paginate(24);
        $balances = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->whereIn('variant_id', $page->getCollection()->flatMap(fn ($p) => $p->variants->pluck('id')))->get()->keyBy('variant_id');
        $products = $page->getCollection()->map(function ($product) use ($balances, $request) {
            $primary = $product->images->firstWhere('is_primary', true);

            return ['id' => $product->id, 'name' => $product->name, 'category_id' => $product->category_id, 'track_stock' => $product->track_stock, 'unit_code' => $product->unit_code, 'image' => $primary ? (new ProductImageResource($primary))->resolve($request) : null,
                'variants' => $product->variants->map(function ($variant) use ($product, $balances) {
                    $balance = $balances->get($variant->id);

                    return ['id' => $variant->id, 'sku' => $variant->sku, 'description' => $variant->description, 'price' => $variant->sale_price, 'image_url' => app(VariantPhoto::class)->url($product, $variant, 'card'), 'thumb_url' => app(VariantPhoto::class)->url($product, $variant, 'thumb'), 'available' => $product->track_stock ? bcsub((string) ($balance->on_hand ?? '0'), (string) ($balance->reserved ?? '0'), 4) : null, 'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all()];
                })->all()];
        });

        return response()->json(['data' => $products, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function quote(Request $request, StoreContext $context, SalesService $service)
    {
        return response()->json(['data' => $service->quote($context, $request->all(), $request->user())]);
    }

    public function create(Request $request, StoreContext $context, SalesService $service, InvoiceDocument $documents, WhatsAppTemplateDispatcher $whatsapp)
    {
        $result = $service->create($context, $request->all(), $request->user());
        $presented = $this->documentState($result, $documents);
        if (! ($result['replay'] ?? false) && ! empty($presented['order']['id'])) {
            $this->dispatchPostSaleNotifications($presented['order'], (string) $request->input('initial_payment', '0'), $whatsapp);
        }

        return response()->json(['data' => $presented], $result['replay'] ? 200 : 201);
    }

    public function index(Request $request, StoreContext $context, SalesService $service)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'customer_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:open,all']]);
        $orders = SalesOrder::where('store_id', $context->store()->id)->when(isset($data['customer_id']), fn ($q) => $q->where('customer_id', $data['customer_id']))->when(($data['status'] ?? 'open') === 'open', fn ($q) => $q->where(fn ($open) => $open->whereIn('status', ['reserved', 'confirmed'])->orWhere(fn ($delivered) => $delivered->where('status', 'fulfilled')->whereNotExists(fn ($invoice) => $invoice->selectRaw('1')->from('invoice_orders')->whereColumn('invoice_orders.order_id', 'orders.id')))))->orderByDesc('id')->paginate(15);

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => $service->present($order)), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'total' => $orders->total()]]);
    }

    public function show(Request $request, StoreContext $context, SalesService $service)
    {
        return response()->json(['data' => $service->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($request->route('order')))]);
    }

    public function change(Request $request, StoreContext $context, SalesService $service, InvoiceDocument $documents, WhatsAppTemplateDispatcher $whatsapp)
    {
        $action = (string) $request->route('action');
        $result = $service->change($context, (int) $request->route('order'), $action, $request->all(), $request->user());
        $presented = $this->documentState($result, $documents);
        if (! ($result['replay'] ?? false) && ! empty($presented['order']['id'])) {
            try {
                $orderModel = SalesOrder::find($presented['order']['id']);
                if ($orderModel) {
                    if ($action === 'payment') {
                        $amount = (string) $request->input('amount', '0');
                        if (bccomp($amount, '0', 4) > 0) {
                            $whatsapp->notifyPaymentReceipt($orderModel, $amount, (string) ($presented['order']['pending_total'] ?? '0'));
                        }
                    } elseif ($action === 'deliver') {
                        $whatsapp->notifyOrderDispatched($orderModel, (string) ($presented['order']['pending_total'] ?? '0'));
                    }
                }
            } catch (\Throwable) {
                // Never disrupt committed financial operations if WhatsApp notification fails.
            }
        }

        return response()->json(['data' => $presented]);
    }

    private function dispatchPostSaleNotifications(array $orderData, string $initialPayment, WhatsAppTemplateDispatcher $whatsapp): void
    {
        try {
            $orderModel = SalesOrder::find($orderData['id']);
            if (! $orderModel) {
                return;
            }
            if (is_numeric($initialPayment) && bccomp($initialPayment, '0', 4) > 0) {
                $whatsapp->notifyPaymentReceipt($orderModel, $initialPayment, (string) ($orderData['pending_total'] ?? '0'));
            }
            foreach ($orderModel->items()->get(['variant_id']) as $item) {
                $bal = DB::table('stock_balances')->where('warehouse_id', $orderModel->warehouse_id)->where('variant_id', $item->variant_id)->first();
                if ($bal) {
                    $avail = bcsub((string) $bal->on_hand, (string) $bal->reserved, 4);
                    $whatsapp->notifyLowStockIfNeeded((int) $orderModel->store_id, (int) $orderModel->warehouse_id, (int) $item->variant_id, $avail);
                }
            }
        } catch (\Throwable) {
            // Non-blocking notification.
        }
    }

    private function documentState(array $result, InvoiceDocument $documents): array
    {
        if (! empty($result['order']['invoice'])) {
            try {
                $invoice = (array) $result['order']['invoice'];
                $result['document'] = $documents->ensure((int) $invoice['id']);
            } catch (\Throwable) {
                $result['document'] = ['status' => 'failed', 'message' => 'La factura está emitida. Reintenta su PDF sin volver a facturar.'];
            }
        }

        return $result;
    }

    public function summary(Request $request, StoreContext $context, SalesService $service)
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->summary($request, $context, $service));
        }
        $customer = Customer::where('store_id', $context->store()->id)->findOrFail($request->route('customer'));
        $orders = SalesOrder::where('store_id', $context->store()->id)->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->orderByDesc('id')->get();
        $due = '0.0000';
        $positions = [];
        $credit = '0.0000';
        $refunded = '0.0000';
        foreach ($orders as $order) {
            $invoice = OrderInvoice::query($order->id)->first();
            $position = OrderSettlement::position($order, $invoice?->id);
            $positions[$order->id] = $position;
            $due = bcadd($due, $position['pending_total'], 4);
            $credit = bcadd($credit, $position['credit_available'], 4);
            $refunded = bcadd($refunded, $position['refunded_total'], 4);
        }

        $invoiced = DB::table('invoice_orders')->whereIn('order_id', $orders->pluck('id'))->pluck('order_id')->all();
        $open = $orders->filter(fn ($order) => in_array($order->status, ['reserved', 'confirmed'], true) || ($order->status === 'fulfilled' && ! in_array($order->id, $invoiced, true)));

        return response()->json(['data' => ['customer' => $customer, 'currency' => BusinessProfile::current()->default_currency, 'credit_available' => $credit, 'refunded_total' => $refunded, 'pending_returns' => DB::table('sales_returns')->where('store_id', $context->store()->id)->where('customer_id', $customer->id)->where('status', 'received')->count(), 'pending_total' => $due, 'purchase_count' => $orders->whereIn('status', ['fulfilled', 'confirmed'])->count(), 'last_purchase_at' => $orders->firstWhere('status', 'fulfilled')?->created_at?->toIso8601String(), 'open_baskets' => $open->map(fn ($o) => ['id' => $o->id, 'status' => $o->status, 'total' => $positions[$o->id]['net_total']])->values()]]);
    }
}
