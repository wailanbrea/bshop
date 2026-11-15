<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreContext;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class RefundReversals
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function scope(StoreContext $context, int $id, int $receiptId, int $refundId, bool $lock = false): array
    {
        $q = SalesOrder::where('store_id', $context->store()->id);
        if ($lock) {
            $q->lockForUpdate();
        }$order = $q->findOrFail($id);
        $q = OrderInvoice::query($id);
        if ($lock) {
            $q->lockForUpdate();
        }$invoice = $q->firstOrFail();
        $q = DB::table('sales_return_receipts as c')->join('sales_returns as r', 'r.id', '=', 'c.sales_return_id')->where('c.sales_return_id', $receiptId)->where('c.order_id', $id)->where('c.store_id', $context->store()->id)->select('c.*', 'r.status');
        if ($lock) {
            $q->lockForUpdate();
        }$receipt = $q->firstOrFail();
        $q = DB::table('refunds as r')->join('refund_confirmations as c', 'c.refund_id', '=', 'r.id')->where('r.id', $refundId)->where('r.sales_return_id', $receiptId)->where('r.store_id', $context->store()->id)->select('r.*', 'c.reason', 'c.actor_name', 'c.adjustment_id');
        if ($lock) {
            $q->lockForUpdate();
        }$refund = $q->firstOrFail();
        abort_unless((int) $refund->invoice_id === (int) $invoice->id && (int) $receipt->invoice_id === (int) $invoice->id && (int) $refund->customer_id === (int) $order->customer_id && $refund->currency === $order->currency, 409, 'El reembolso requiere conciliación de su contexto.');

        return [$order, $invoice, $receipt, $refund];
    }

    private function describe(SalesOrder $order, object $invoice, object $receipt, object $refund, bool $lock = false): array
    {
        $q = DB::table('refund_reversals')->where('refund_id', $refund->id);
        if ($lock) {
            $q->lockForUpdate();
        }$reversed = (bool) $q->first();
        $q = DB::table('refunds')->where('original_payment_id', $refund->original_payment_id)->where('status', 'pending');
        if ($lock) {
            $q->lockForUpdate();
        }$pending = (bool) $q->first();
        $q = DB::table('commission_entries')->where('invoice_id', $invoice->id);
        if ($lock) {
            $q->lockForUpdate();
        }$commission = (bool) $q->first();
        $q = DB::table('customer_credit_movements')->where('refund_id', $refund->id)->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }$ledger = $q->get();
        $entry = $ledger->first();
        $valid = $ledger->count() === 1 && $entry->event_type === 'unused_refund' && (int) $entry->payment_id === (int) $refund->original_payment_id && bccomp((string) $entry->amount_delta, bcsub('0', (string) $refund->amount, 4), 4) === 0;
        $fund = OrderSettlement::payment($refund->original_payment_id, $lock);
        $balance = OrderSettlement::position($order, $invoice->id, $lock);
        $allowed = ! $reversed && ! $pending && ! $commission && $valid && $refund->refund_kind === 'return' && $refund->status === 'confirmed' && $receipt->status === 'adjusted' && $fund['payment']->status === 'confirmed' && $order->status !== 'cancelled' && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true);
        $result = ['refund_id' => (int) $refund->id, 'order_id' => $order->id, 'version' => $order->lock_version, 'invoice_number' => $invoice->invoice_number, 'currency' => $order->currency, 'amount' => bcadd((string) $refund->amount, '0', 4), 'payment_id' => (int) $refund->original_payment_id, 'method' => $refund->method, 'reference' => $refund->external_reference, 'balance' => $balance, 'credit_after' => $reversed ? $balance['credit_available'] : bcadd($balance['credit_available'], (string) $refund->amount, 4), 'allowed' => $allowed, 'already_reversed' => $reversed, 'refund_pending' => $pending, 'ledger_valid' => $valid, 'fund_available' => $fund['available']];
        $result['review_hash'] = hash('sha256', app(FinancialDocument::class)->canonical($result));

        return $result;
    }

    public function info(StoreContext $context, int $order, int $receipt, int $refund): array
    {
        return DB::transaction(function () use ($context, $order, $receipt, $refund) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();

            return $this->describe(...$this->scope($context, $order, $receipt, $refund));
        });
    }

    public function reverse(StoreContext $context, int $id, int $receiptId, int $refundId, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }$data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:255'], 'confirmed_no_money' => ['required', 'accepted']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $data['confirmed_no_money'] = true;
        $result = $this->once->run('refunds.reverse', $key, [$actor->id, $context->store()->id, $id, $receiptId, $refundId], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'refunds.reverse');
        }, function () use ($context, $id, $receiptId, $refundId, $actor, $data) {
            [$ref,,,$source] = $this->scope($context, $id, $receiptId, $refundId);
            $paymentId = $source->original_payment_id;
            $origin = DB::table('order_payments')->where('payment_id', $paymentId)->firstOrFail();
            $ids = DB::table('payment_allocation_releases')->where('payment_id', $paymentId)->pluck('order_id')->push($origin->order_id)->push($id)->unique()->sort()->values()->all();
            $variantIds = DB::table('order_items')->whereIn('order_id', $ids)->whereNull('removed_at')->pluck('variant_id')->unique();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
            Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('payment_allocation_releases')->where('payment_id', $paymentId)->orderBy('id')->lockForUpdate()->get()->pluck('order_id')->push($origin->order_id)->push($id)->unique()->all();
            abort_if(array_diff($current, $ids), 409, 'La procedencia del saldo cambió. Actualiza la revisión.');
            $locked = SalesOrder::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($locked as $row) {
                abort_unless($row->store_id === $ref->store_id && $row->customer_id === $ref->customer_id && $row->currency === $ref->currency, 409, 'El saldo cruza cliente, moneda o sucursal.');
            }
            $map = DB::table('invoice_orders')->whereIn('order_id', $ids)->orderBy('order_id')->lockForUpdate()->get();
            DB::table('invoices')->whereIn('id', $map->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get();
            [$order,$invoice,$receipt,$refund] = $this->scope($context, $id, $receiptId, $refundId, true);
            abort_unless($order->lock_version === $data['version'], 409, 'El pedido cambió. Actualiza la revisión.');
            $review = $this->describe($order, $invoice, $receipt, $refund, true);
            abort_unless($review['allowed'], 409, 'El reembolso ya fue corregido, está pendiente o requiere conciliación adicional.');
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'Los saldos cambiaron. Actualiza la revisión.');
            $fund = OrderSettlement::payment($paymentId, true);
            abort_unless(bccomp(bcadd(bcadd($fund['effective_allocated'], $fund['available'], 4), $fund['refunded'], 4), (string) $fund['payment']->amount, 4) === 0 && bccomp($fund['refunded'], $review['amount'], 4) >= 0, 409, 'El pago requiere conciliación.');
            $snapshot = ['refund' => (array) $refund, 'review' => $review, 'reason' => $data['reason'], 'actor_name' => $actor->name, 'confirmed_no_money' => true];
            $json = app(FinancialDocument::class)->canonical($snapshot);
            $reversal = DB::table('refund_reversals')->insertGetId(['refund_id' => $refundId, 'amount' => $review['amount'], 'confirmed_no_money' => true, 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'snapshot' => $json, 'data_hash' => hash('sha256', $json), 'created_at' => now()]);
            $after = OrderSettlement::payment($paymentId, true);
            abort_unless(bccomp($after['available'], bcadd($fund['available'], $review['amount'], 4), 4) === 0 && bccomp($after['refunded'], bcsub($fund['refunded'], $review['amount'], 4), 4) === 0 && bccomp($after['effective_allocated'], $fund['effective_allocated'], 4) === 0, 409, 'La corrección no conserva el saldo del pago.');
            $order->lock_version++;
            $order->save();
            $this->audit->record('sales.refund.reversed', $order, after: ['refund_id' => $refundId, 'reversal_id' => $reversal, 'amount' => $review['amount'], 'reason' => $data['reason'], 'confirmed_no_money' => true], actor: $actor);

            return ['order_id' => $id, 'sales_return_id' => $receiptId, 'refund_id' => $refundId, 'reversal_id' => $reversal];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($id)), 'refund_id' => $refundId, 'reversal_id' => $result['reversal_id'], 'replay' => $result['replay']];
    }
}
