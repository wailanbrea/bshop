<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\StockLedger;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ReturnReceiptReversals
{
    public function __construct(private SalesService $sales, private StockLedger $ledger, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function scope(StoreContext $context, int $orderId, int $receiptId, bool $lock = false): array
    {
        $q = SalesOrder::where('store_id', $context->store()->id);
        if ($lock) {
            $q->lockForUpdate();
        }$order = $q->findOrFail($orderId);
        $q = OrderInvoice::query($orderId);
        if ($lock) {
            $q->lockForUpdate();
        }$invoice = $q->firstOrFail();
        $q = DB::table('sales_return_receipts as c')->join('sales_returns as r', 'r.id', '=', 'c.sales_return_id')->where('c.sales_return_id', $receiptId)->where('c.order_id', $orderId)->where('c.store_id', $context->store()->id)->select('c.*', 'r.status', 'r.reason');
        if ($lock) {
            $q->lockForUpdate();
        }$receipt = $q->firstOrFail();
        abort_unless((int) $receipt->invoice_id === (int) $invoice->id && (int) $receipt->warehouse_id === (int) $order->warehouse_id, 409, 'La recepción requiere conciliación de su contexto.');

        return [$order, $invoice, $receipt];
    }

    private function describe(SalesOrder $order, object $invoice, object $receipt, bool $lock = false): array
    {
        $q = DB::table('sales_return_items as r')->join('invoice_items as i', 'i.id', '=', 'r.invoice_item_id')->where('r.sales_return_id', $receipt->sales_return_id)->orderBy('r.id')->select('r.*', 'i.description');
        if ($lock) {
            $q->lockForUpdate();
        }$items = $q->get();
        $lines = [];
        $required = [];
        $trace = true;
        foreach ($items as $item) {
            $q = DB::table('inventory_movements')->where('return_item_id', $item->id)->where('movement_type', 'customer_return')->orderBy('id');
            if ($lock) {
                $q->lockForUpdate();
            }$movements = $q->get();
            $movement = $movements->first();
            $valid = $item->restock ? ($movements->count() === 1 && (int) $movement->warehouse_id === (int) $receipt->warehouse_id && (int) $movement->variant_id === (int) $item->variant_id && bccomp((string) $movement->on_hand_delta, (string) $item->quantity, 4) === 0 && bccomp((string) $movement->reserved_delta, '0', 4) === 0) : $movements->isEmpty();
            $trace = $trace && $valid && bccomp((string) $item->refund_amount, '0', 4) === 0;
            if ($item->restock) {
                $required[$item->variant_id] = bcadd($required[$item->variant_id] ?? '0', (string) $item->quantity, 4);
            }
            $lines[] = ['return_item_id' => (int) $item->id, 'variant_id' => (int) $item->variant_id, 'name' => $item->description, 'quantity' => bcadd((string) $item->quantity, '0', 4), 'restock' => (bool) $item->restock, 'original_movement_id' => $movement ? (int) $movement->id : null];
        }
        $stock = [];
        $enough = true;
        foreach ($required as $variant => $quantity) {
            $q = DB::table('stock_balances')->where('warehouse_id', $receipt->warehouse_id)->where('variant_id', $variant);
            if ($lock) {
                $q->lockForUpdate();
            }$balance = $q->first();
            $available = bcsub((string) ($balance->on_hand ?? '0'), (string) ($balance->reserved ?? '0'), 4);
            $enough = $enough && $balance && bccomp($available, $quantity, 4) >= 0;
            $stock[] = ['variant_id' => (int) $variant, 'required' => $quantity, 'available' => $available, 'version' => (int) ($balance->version ?? 0)];
        }
        $q = DB::table('return_adjustments')->where('sales_return_id', $receipt->sales_return_id);
        if ($lock) {
            $q->lockForUpdate();
        }$adjusted = (bool) $q->first();
        $q = DB::table('refunds')->where('sales_return_id', $receipt->sales_return_id);
        if ($lock) {
            $q->lockForUpdate();
        }$refunded = (bool) $q->first();
        $q = DB::table('return_receipt_reversals')->where('sales_return_id', $receipt->sales_return_id);
        if ($lock) {
            $q->lockForUpdate();
        }$reversed = (bool) $q->first();
        $warehouse = Warehouse::findOrFail($receipt->warehouse_id);
        $store = Store::findOrFail($order->store_id);
        $allowed = $receipt->status === 'received' && ! $adjusted && ! $refunded && ! $reversed && $trace && $enough && $items->isNotEmpty() && $order->status !== 'cancelled' && $store->is_active && $warehouse->is_active && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true);
        $result = ['receipt_id' => (int) $receipt->sales_return_id, 'order_id' => $order->id, 'version' => $order->lock_version, 'invoice_number' => $invoice->invoice_number, 'warehouse_name' => $warehouse->name, 'lines' => $lines, 'stock' => $stock, 'allowed' => $allowed, 'stock_available' => $enough, 'economic_history' => $adjusted || $refunded, 'already_reversed' => $reversed, 'trace_valid' => $trace];
        $result['review_hash'] = hash('sha256', app(FinancialDocument::class)->canonical($result));

        return $result;
    }

    public function info(StoreContext $context, int $order, int $receipt): array
    {
        return DB::transaction(function () use ($context, $order, $receipt) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();

            return $this->describe(...$this->scope($context, $order, $receipt));
        });
    }

    public function reverse(StoreContext $context, int $id, int $receiptId, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }$data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('returns.reverse', $key, [$actor->id, $context->store()->id, $id, $receiptId], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'returns.reverse');
        }, function () use ($context, $id, $receiptId, $actor, $data) {
            [$ref] = $this->scope($context, $id, $receiptId);
            $ids = $ref->items()->pluck('variant_id')->all();
            [$warehouse,$variants,$balances,$products] = $this->sales->lockStock($context, $ids, $ref->warehouse_id);
            [$order,$invoice,$receipt] = $this->scope($context, $id, $receiptId, true);
            abort_unless($order->lock_version === $data['version'], 409, 'El pedido cambió. Actualiza la revisión.');
            abort_if(array_diff($order->items()->orderBy('id')->lockForUpdate()->pluck('variant_id')->all(), $ids), 409, 'Los artículos cambiaron. Actualiza la revisión.');
            $review = $this->describe($order, $invoice, $receipt, true);
            abort_unless($review['allowed'], 409, 'La recepción ya fue conciliada/anulada, su stock no está disponible o requiere revisión.');
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'La recepción o el stock cambiaron. Actualiza la revisión.');
            $snapshot = ['receipt' => (array) $receipt, 'review' => $review, 'reason' => $data['reason'], 'actor_name' => $actor->name];
            $json = app(FinancialDocument::class)->canonical($snapshot);
            $reversal = DB::table('return_receipt_reversals')->insertGetId(['sales_return_id' => $receiptId, 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'snapshot' => $json, 'data_hash' => hash('sha256', $json), 'created_at' => now()]);
            foreach ($review['lines'] as $line) {
                $inverse = null;
                if ($line['restock']) {
                    abort_unless($variants->has($line['variant_id']), 409, 'El artículo cambió.');
                    $balance = $balances[$line['variant_id']];
                    $movement = $this->ledger->record($warehouse, $line['variant_id'], $balance, bcsub('0', $line['quantity'], 4), '0.0000', 'customer_return_reversal', 'return-receipt-reversal:'.$line['return_item_id'], $data['reason'], $actor, null, $line['return_item_id']);
                    $inverse = $movement['id'];
                    $balance->on_hand = $movement['on_hand_after'];
                    $balance->reserved = $movement['reserved_after'];
                    $balance->version = $movement['balance_version'];
                }
                DB::table('return_receipt_reversal_items')->insert(['return_item_id' => $line['return_item_id'], 'reversal_id' => $reversal, 'original_movement_id' => $line['original_movement_id'], 'inverse_movement_id' => $inverse, 'quantity' => $line['quantity']]);
            }
            DB::table('sales_returns')->where('id', $receiptId)->update(['status' => 'void', 'updated_at' => now()]);
            foreach ($products as $product) {
                $product->increment('lock_version');
            }$order->lock_version++;
            $order->save();
            $this->audit->record('sales.return.receipt_reversed', $order, after: ['sales_return_id' => $receiptId, 'reversal_id' => $reversal, 'reason' => $data['reason'], 'lines' => $review['lines']], actor: $actor);

            return ['order_id' => $id, 'sales_return_id' => $receiptId, 'reversal_id' => $reversal];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($id)), 'sales_return_id' => $receiptId, 'reversal_id' => $result['reversal_id'], 'replay' => $result['replay']];
    }
}
