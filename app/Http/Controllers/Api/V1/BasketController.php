<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\BasketService;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BasketController extends Controller
{
    public function index(Request $request, StoreContext $context)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'filter' => ['nullable', 'in:all,open,without_deposit,invoiced,invoice_sent,paid,shipped'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'in:4,16,36']]);
        $paid = "(SELECT COALESCE(SUM(p.amount),0) FROM order_payments op JOIN payments p ON p.id=op.payment_id WHERE op.order_id=orders.id AND p.status='confirmed')";
        $paid = "(CASE WHEN source.invoice_id IS NULL THEN $paid ELSE (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id LEFT JOIN order_payments op ON op.payment_id=p.id LEFT JOIN credit_applications ca ON ca.payment_allocation_id=a.id WHERE (ca.target_order_id=orders.id OR (ca.id IS NULL AND op.order_id=orders.id)) AND NOT EXISTS(SELECT 1 FROM credit_application_reversals cv WHERE cv.credit_application_id=ca.id) AND a.invoice_id=source.invoice_id AND p.status='confirmed')-(SELECT COALESCE(SUM(r.amount),0) FROM payment_allocation_releases r JOIN payments p ON p.id=r.payment_id WHERE r.order_id=orders.id AND p.status='confirmed' AND NOT EXISTS(SELECT 1 FROM return_adjustment_reversals av WHERE av.adjustment_id=r.adjustment_id)) END)";
        $net = '(orders.grand_total-(SELECT COALESCE(SUM(c.amount),0) FROM return_adjustments c WHERE c.order_id=orders.id AND NOT EXISTS(SELECT 1 FROM return_adjustment_reversals av WHERE av.adjustment_id=c.id)))';
        $query = SalesOrder::where('orders.store_id', $context->store()->id)->where('order_kind', 'reservation')
            ->leftJoin('customers as c', 'c.id', '=', 'orders.customer_id')->leftJoin('invoice_orders as source', 'source.order_id', '=', 'orders.id')->leftJoin('invoices as i', 'i.id', '=', 'source.invoice_id')
            ->select('orders.*', 'c.full_name as live_name', 'c.phone as live_phone', 'i.id as invoice_id', 'i.status as invoice_status')->selectRaw($paid.' as paid_amount')->withCount('items')->withSum('items as quantity_count', 'quantity');
        if (! empty($data['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("c.full_name LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("c.phone LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("basket_number LIKE ? ESCAPE '!'", [$pattern]));
        }
        $filter = $data['filter'] ?? 'open';
        if ($filter !== 'all') {
            $query->where('orders.status', '!=', 'cancelled');
        }
        if ($filter === 'open') {
            $query->whereNull('closed_at');
        }
        if ($filter === 'without_deposit') {
            $query->whereRaw($paid.' = 0');
        }
        if ($filter === 'paid') {
            $query->where(fn ($q) => $q->where('i.status', 'paid')->orWhere(fn ($p) => $p->whereRaw('ROUND('.$paid.',4) >= ROUND('.$net.',4)')->where(fn ($kind) => $kind->whereRaw($net.' > 0')->orWhereNotNull('i.id'))));
        }
        if ($filter === 'shipped') {
            $query->whereNotNull('shipped_at');
        }
        if ($filter === 'invoiced') {
            $query->whereNotNull('i.id');
        }
        if ($filter === 'invoice_sent') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('invoice_deliveries')->whereColumn('invoice_id', 'i.id')->whereIn('status', ['sent', 'delivered', 'read']));
        }
        $page = $query->orderByDesc('orders.id')->paginate($data['per_page'] ?? 24);
        $sends = DB::table('invoice_deliveries')->whereIn('invoice_id', $page->getCollection()->pluck('invoice_id')->filter())->orderByDesc('created_at')->orderByDesc('id')->get()->unique('invoice_id')->keyBy('invoice_id');
        $cards = $page->getCollection()->map(function ($o) use ($sends) {
            $position = OrderSettlement::position($o, $o->invoice_id);
            $paid = $position['paid_total'];
            $isPaid = $o->status !== 'cancelled' && $position['pending_total'] === '0.0000' && ((bool) $o->invoice_id || bccomp($o->grand_total, '0', 4) > 0);

            return ['id' => $o->id, 'number' => $o->basket_number ?: (string) $o->id, 'created_at' => $o->created_at?->toIso8601String(), 'customer_id' => $o->customer_id, 'customer_name' => $o->live_name ?: ($o->customer_snapshot['full_name'] ?? 'Cliente'), 'phone' => $o->live_phone, 'items_count' => $o->items_count, 'quantity_count' => bcadd((string) ($o->quantity_count ?? '0'), '0', 4), 'total' => $position['net_total'], 'original_total' => $o->grand_total, 'return_credit_total' => $position['return_credit_total'], 'credit_available' => $position['credit_available'], 'deposit' => $paid, 'pending' => $o->status === 'cancelled' ? '0.0000' : $position['pending_total'], 'currency' => $o->currency, 'status' => $o->status, 'closed' => (bool) $o->closed_at, 'invoice_generated' => (bool) $o->invoice_id, 'whatsapp_status' => $sends->get($o->invoice_id)?->status, 'paid' => $isPaid, 'shipped' => (bool) $o->shipped_at, 'version' => $o->lock_version];
        });

        return response()->json(['data' => $cards, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()]]);
    }

    public function create(Request $request, StoreContext $context, BasketService $service)
    {
        $result = $service->create($context, $request->all(), $request->user());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201);
    }

    public function preview(Request $request, StoreContext $context, BasketService $service)
    {
        return response()->json(['data' => $service->preview($context, (int) $request->route('order'), $request->all(), $request->user())]);
    }

    public function save(Request $request, StoreContext $context, BasketService $service)
    {
        return response()->json(['data' => $service->save($context, (int) $request->route('order'), $request->all(), $request->user())]);
    }

    public function number(Request $request, StoreContext $context, BasketService $service)
    {
        return response()->json(['data' => $service->number($context, (int) $request->route('order'), $request->all(), $request->user())]);
    }

    public function dispatch(Request $request, StoreContext $context, BasketService $service)
    {
        return response()->json(['data' => $service->dispatch($context, (int) $request->route('order'), $request->all(), $request->user())]);
    }

    public function recovery(Request $request, StoreContext $context, SalesService $sales)
    {
        $data = $request->validate(['operation' => ['required', 'in:create,save,number,dispatch']]);
        $record = DB::table('idempotency_requests')->where('operation', 'baskets.'.$data['operation'])->where('request_key', $request->route('key'))->where('status', 'completed')->firstOrFail();
        $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($result['_identity'][0] ?? null) === $request->user()->id && ($result['_identity'][1] ?? null) === $context->store()->id, 404);

        return response()->json(['data' => ['order' => $sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($result['order']['id'])), 'replay' => true]]);
    }
}
