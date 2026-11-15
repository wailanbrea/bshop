<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\StockLedger;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SalesReturnReceipts
{
    public function __construct(private SalesService $sales, private StockLedger $ledger, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function sum($values): string
    {
        $sum = '0.0000';
        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, 4);
        }

return $sum;
    }

    private function delivered(SalesOrder $order, object $item, bool $locked): string
    {
        if ($order->order_kind === 'sale') {
            $query = DB::table('inventory_movements')->where('store_id', $order->store_id)->where('warehouse_id', $order->warehouse_id)->where('variant_id', $item->variant_id)
                ->where('movement_type', 'sale')->where('event_key', 'like', 'sale:%:'.$item->order_item_id)->orderBy('id');
            if ($locked) {
                $query->lockForUpdate();
            }

            return bcsub('0', $this->sum($query->pluck('on_hand_delta')), 4);
        }
        $query = DB::table('stock_reservations')->where('order_item_id', $item->order_item_id)->orderBy('id');
        if ($locked) {
            $query->lockForUpdate();
        } $reservations = $query->get();
        $movements = DB::table('inventory_movements')->where('store_id', $order->store_id)->where('warehouse_id', $order->warehouse_id)->whereIn('reservation_id', $reservations->pluck('id'))->where('movement_type', 'fulfill')->orderBy('id');
        if ($locked) {
            $movements->lockForUpdate();
        }
        $delivered = bcsub('0', $this->sum($movements->pluck('on_hand_delta')), 4);
        abort_unless(bccomp($delivered, $this->sum($reservations->pluck('fulfilled_quantity')), 4) === 0, 409, 'El historial de entrega requiere conciliación de inventario.');

        return $delivered;
    }

    private function lines(SalesOrder $order, object $invoice, bool $locked): array
    {
        $query = DB::table('invoice_items')->where('invoice_id', $invoice->id)->where('order_id', $order->id)->orderBy('id');
        if ($locked) {
            $query->lockForUpdate();
        } $rows = [];
        foreach ($query->get() as $item) {
            $variant = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])->find($item->variant_id);
            $tracked = (bool) $variant?->product?->track_stock;
            $delivered = $tracked ? $this->delivered($order, $item, $locked) : '0.0000';
            $returns = DB::table('sales_return_items')->where('invoice_item_id', $item->id)->orderBy('id');
            if ($locked) {
                $returns->lockForUpdate();
            }
            $received = $this->sum($returns->pluck('quantity'));
            abort_unless(! $tracked || (bccomp($delivered, (string) $item->quantity, 4) <= 0 && bccomp($received, $delivered, 4) <= 0), 409, 'Las cantidades entregadas/devueltas requieren conciliación.');
            $rows[] = ['invoice_item_id' => (int) $item->id, 'variant_id' => (int) $item->variant_id, 'name' => $item->description, 'sku' => $item->sku_snapshot,
                'invoiced_quantity' => bcadd((string) $item->quantity, '0', 4), 'delivered_quantity' => $delivered, 'received_quantity' => $received,
                'available_quantity' => $tracked ? bcsub($delivered, $received, 4) : '0.0000', 'track_stock' => $tracked];
        }

        return $rows;
    }

    private function describe(SalesOrder $order, object $invoice, bool $locked = false): array
    {
        $lines = $this->lines($order, $invoice, $locked);

        return ['order_id' => $order->id, 'version' => $order->lock_version, 'invoice' => ['id' => $invoice->id, 'number' => $invoice->invoice_number],
            'warehouse_name' => Warehouse::find($order->warehouse_id)?->name, 'lines' => $lines,
            'return_pending' => ReturnReceiptState::pendingInvoice($invoice->id, $locked),
            'review_hash' => hash('sha256', json_encode([$order->id, $order->lock_version, $invoice->id, $lines], JSON_THROW_ON_ERROR))];
    }

    public function info(StoreContext $context, int $id): array
    {
        return DB::transaction(function () use ($context, $id) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();
            $order = SalesOrder::where('store_id', $context->store()->id)->sharedLock()->findOrFail($id);
            $invoice = OrderInvoice::query($id)->sharedLock()->first();
            abort_unless($invoice, 409, 'La recepción de devoluciones requiere una factura del pedido.');
            $result = $this->describe($order, $invoice);
            $result['history'] = DB::table('sales_return_receipts as c')->join('sales_returns as r', 'r.id', '=', 'c.sales_return_id')->where('c.order_id', $id)->where('c.store_id', $context->store()->id)->orderByDesc('c.sales_return_id')->limit(20)->get(['c.sales_return_id as id', 'c.received_at', 'c.actor_name', 'r.reason', 'r.status'])->map(function ($receipt) {
                $receipt->items = DB::table('sales_return_items as ri')->join('invoice_items as i', 'i.id', '=', 'ri.invoice_item_id')->where('sales_return_id', $receipt->id)->orderBy('ri.id')->get(['i.description as name', 'ri.quantity', 'ri.restock'])->map(fn ($i) => ['name' => $i->name, 'quantity' => bcadd((string) $i->quantity, '0', 4), 'restock' => (bool) $i->restock])->all();

                return $receipt;
            });

            return $result;
        });
    }

    public function receive(StoreContext $context, int $id, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:255'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:50'], 'lines.*.invoice_item_id' => ['required', 'integer', 'min:1', 'distinct'], 'lines.*.quantity' => ['required', new DecimalAmount], 'lines.*.restock' => ['required', 'boolean']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        foreach ($data['lines'] as &$line) {
            $line = ['invoice_item_id' => (int) $line['invoice_item_id'], 'quantity' => DecimalAmount::normalize($line['quantity']), 'restock' => (bool) $line['restock']];
            abort_unless(bccomp($line['quantity'], '0', 4) > 0, 422, 'Las cantidades recibidas deben ser positivas.');
        }unset($line);
        $result = $this->once->run('returns.receive', $key, [$actor->id, $context->store()->id, $id], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'returns.receive');
        }, function () use ($context, $id, $actor, $data) {
            $reference = SalesOrder::where('store_id', $context->store()->id)->findOrFail($id);
            abort_unless($reference->warehouse_id, 409, 'El pedido no tiene almacén histórico.');
            [$warehouse,$variants,$balances,$products] = $this->sales->lockStock($context, $reference->items()->pluck('variant_id')->all(), $reference->warehouse_id);
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($id);
            abort_unless($order->lock_version === $data['version'] && $order->status !== 'cancelled', 409, 'El pedido cambió o está cancelado. Actualiza la revisión.');
            $invoice = OrderInvoice::query($id)->lockForUpdate()->first();
            abort_unless($invoice && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 409, 'La factura requiere conciliación antes de recibir mercancía.');
            $review = $this->describe($order, $invoice, true);
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'Las entregas o devoluciones cambiaron. Revisa las cantidades.');
            $available = collect($review['lines'])->keyBy('invoice_item_id');
            $plan = [];
            foreach ($data['lines'] as $line) {
                $item = $available->get($line['invoice_item_id']);
                abort_unless($item, 404, 'El artículo no pertenece a la factura de este pedido.');
                abort_unless($item['track_stock'] && bccomp($line['quantity'], $item['available_quantity'], 4) <= 0, 422, 'La devolución excede la mercancía entregada pendiente de recibir.');
                abort_unless($variants->has($item['variant_id']), 409, 'El artículo cambió durante la operación.');
                $plan[] = [...$line, 'variant_id' => $item['variant_id']];
            }
            $return = DB::table('sales_returns')->insertGetId(['invoice_id' => $invoice->id, 'customer_id' => $order->customer_id, 'store_id' => $order->store_id, 'currency' => $order->currency, 'status' => 'received', 'reason' => $data['reason'], 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_return_receipts')->insert(['sales_return_id' => $return, 'invoice_id' => $invoice->id, 'order_id' => $id, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'warehouse_id' => $warehouse->id, 'currency' => $order->currency, 'received_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'received_at' => now()]);
            foreach ($plan as $line) {
                $itemId = DB::table('sales_return_items')->insertGetId(['sales_return_id' => $return, 'invoice_id' => $invoice->id, 'invoice_item_id' => $line['invoice_item_id'], 'quantity' => $line['quantity'], 'refund_amount' => '0.0000', 'restock' => $line['restock'], 'store_id' => $order->store_id, 'variant_id' => $line['variant_id'], 'created_at' => now()]);
                if ($line['restock']) {
                    $balance = $balances[$line['variant_id']];
                    $movement = $this->ledger->record($warehouse, $line['variant_id'], $balance, $line['quantity'], '0.0000', 'customer_return', 'return-receipt:'.$itemId, 'Recepción de devolución #'.$return.' · Pedido #'.$id, $actor, null, $itemId);
                    $balance->on_hand = $movement['on_hand_after'];
                    $balance->reserved = $movement['reserved_after'];
                    $balance->version = $movement['balance_version'];
                }
            }
            foreach ($products as $product) {
                $product->increment('lock_version');
            }
            $order->lock_version++;
            $order->save();
            $this->audit->record('sales.return.received', $order, after: ['sales_return_id' => $return, 'invoice_id' => $invoice->id, 'lines' => $plan, 'reason' => $data['reason'], 'economic_status' => 'pending_adjustment'], actor: $actor);

            return ['order_id' => $id, 'sales_return_id' => $return];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id',$context->store()->id)->findOrFail($id)), 'sales_return_id' => $result['sales_return_id'], 'economic_status' => 'pending_adjustment', 'replay' => $result['replay']];
    }
}
