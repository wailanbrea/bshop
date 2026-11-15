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

final class ReturnAdjustmentReversals
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function scope(StoreContext $context, int $id, int $receiptId, int $adjustmentId, bool $lock = false): array
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
        $q = DB::table('return_adjustments')->where('id', $adjustmentId)->where('sales_return_id', $receiptId)->where('order_id', $id)->where('store_id', $context->store()->id);
        if ($lock) {
            $q->lockForUpdate();
        }$adjustment = $q->firstOrFail();
        abort_unless((int) $adjustment->invoice_id === (int) $invoice->id && (int) $receipt->invoice_id === (int) $invoice->id && $order->currency === $adjustment->currency, 409, __('adjustment_reversal.context'));

        return [$order, $invoice, $receipt, $adjustment];
    }

    private function describe(SalesOrder $order, object $invoice, object $receipt, object $adjustment, bool $lock = false): array
    {
        $q = DB::table('return_adjustment_reversals')->where('adjustment_id', $adjustment->id);
        if ($lock) {
            $q->lockForUpdate();
        }$reversed = (bool) $q->first();
        $q = AdjustmentState::effective(DB::table('return_adjustments')->where('order_id', $order->id)->where('id', '>', $adjustment->id));
        if ($lock) {
            $q->lockForUpdate();
        }$later = (bool) $q->first();
        $q = DB::table('return_adjustment_heads')->where('sales_return_id', $receipt->sales_return_id);
        if ($lock) {
            $q->lockForUpdate();
        }$head = $q->first();
        $q = DB::table('commission_entries')->where('invoice_id', $invoice->id);
        if ($lock) {
            $q->lockForUpdate();
        }$commissions = (bool) $q->first();
        $q = RefundState::effective(DB::table('refunds')->where('sales_return_id', $receipt->sales_return_id)->whereIn('status', ['pending', 'confirmed']));
        if ($lock) {
            $q->lockForUpdate();
        }$refunded = (bool) $q->first();
        $q = DB::table('payment_allocation_releases')->where('adjustment_id', $adjustment->id)->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }$releases = $q->get();
        $payments = [];
        $poolRows = [];
        $used = false;
        $pending = false;
        $trace = bccomp(OrderSettlement::sum($releases->pluck('amount')), (string) $adjustment->credit_released, 4) === 0;
        foreach ($releases->groupBy('payment_id') as $payment => $rows) {
            $fund = OrderSettlement::payment((int) $payment, $lock);
            $restore = OrderSettlement::sum($rows->pluck('amount'));
            $trace = $trace && $fund['payment']->status === 'confirmed' && bccomp($fund['available'], $restore, 4) >= 0 && bccomp(bcadd(bcadd($fund['effective_allocated'], $fund['available'], 4), $fund['refunded'], 4), (string) $fund['payment']->amount, 4) === 0;
            $q = DB::table('refunds')->where('original_payment_id', $payment)->where('status', 'pending');
            if ($lock) {
                $q->lockForUpdate();
            }$pending = $pending || (bool) $q->first();
            $pools = collect(CreditPools::rows((int) $payment, $lock))->keyBy('release_id');
            foreach ($rows as $row) {
                $pool = $pools->get($row->id);
                if (! $reversed) {
                    $trace = $trace && $pool !== null;
                    $used = $used || ! $pool || bccomp($pool['used'], '0', 4) > 0 || bccomp($pool['refunded'], '0', 4) > 0 || bccomp($pool['available'], (string) $row->amount, 4) !== 0;
                }$poolRows[] = ['release_id' => (int) $row->id, 'payment_id' => (int) $payment, 'amount' => bcadd((string) $row->amount, '0', 4), 'pool' => $pool];
            }
            $payments[] = ['payment_id' => (int) $payment, 'restore' => $restore, 'available' => $fund['available'], 'effective_allocated' => $fund['effective_allocated'], 'refunded' => $fund['refunded']];
        }
        $before = OrderSettlement::position($order, $invoice->id, $lock);
        $amount = bcadd((string) $adjustment->amount, '0', 4);
        $restored = bcadd((string) $adjustment->credit_released, '0', 4);
        $trace = $trace && bccomp(bcadd((string) $adjustment->merchandise_credit, (string) $adjustment->shipping_credit, 4), $amount, 4) === 0;
        $allowed = ! $reversed && ! $later && ! $used && ! $pending && ! $refunded && ! $commissions && $trace && $head && (int) $head->active_adjustment_id === (int) $adjustment->id && $receipt->status === 'adjusted' && $order->status !== 'cancelled' && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true);
        $result = ['adjustment_id' => (int) $adjustment->id, 'receipt_id' => (int) $receipt->sales_return_id, 'order_id' => $order->id, 'version' => $order->lock_version, 'invoice_number' => $invoice->invoice_number, 'currency' => $order->currency, 'amount' => $amount, 'credit_to_reallocate' => $restored, 'balance' => $before, 'net_after' => $reversed ? $before['net_total'] : bcadd($before['net_total'], $amount, 4), 'pending_after' => $reversed ? $before['pending_total'] : bcadd($before['pending_total'], bcsub($amount, $restored, 4), 4), 'allowed' => (bool) $allowed, 'already_reversed' => $reversed, 'later_adjustment' => $later, 'credit_consumed' => $used, 'refund_pending' => $pending, 'refund_effective' => $refunded, 'trace_valid' => $trace, 'pools' => $poolRows, 'payments' => $payments];
        $result['review_hash'] = hash('sha256', app(FinancialDocument::class)->canonical($result));

        return $result;
    }

    public function info(StoreContext $context, int $order, int $receipt, int $adjustment): array
    {
        return DB::transaction(function () use ($context, $order, $receipt, $adjustment) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();

            return $this->describe(...$this->scope($context, $order, $receipt, $adjustment));
        });
    }

    public function reverse(StoreContext $context, int $id, int $receiptId, int $adjustmentId, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }$data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('adjustments.reverse', $key, [$actor->id, $context->store()->id, $id, $receiptId, $adjustmentId], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'adjustments.reverse');
        }, function () use ($context, $id, $receiptId, $adjustmentId, $actor, $data) {
            [$ref,,,$source] = $this->scope($context, $id, $receiptId, $adjustmentId);
            $paymentIds = DB::table('payment_allocation_releases')->where('adjustment_id', $source->id)->pluck('payment_id')->unique();
            $ids = DB::table('payment_allocation_releases')->whereIn('payment_id', $paymentIds)->pluck('order_id')->merge(DB::table('order_payments')->whereIn('payment_id', $paymentIds)->pluck('order_id'))->push($id)->unique()->sort()->values()->all();
            $variantIds = DB::table('order_items')->whereIn('order_id', $ids)->whereNull('removed_at')->pluck('variant_id')->unique();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
            Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('payment_allocation_releases')->whereIn('payment_id', $paymentIds)->orderBy('id')->lockForUpdate()->get()->pluck('order_id')->merge(DB::table('order_payments')->whereIn('payment_id', $paymentIds)->pluck('order_id'))->push($id)->unique()->all();
            abort_if(array_diff($current, $ids), 409, __('adjustment_reversal.origins_changed'));
            $locked = SalesOrder::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($locked as $row) {
                abort_unless($row->store_id === $ref->store_id && $row->customer_id === $ref->customer_id && $row->currency === $ref->currency, 409, __('adjustment_reversal.cross_context'));
            }$map = DB::table('invoice_orders')->whereIn('order_id', $ids)->orderBy('order_id')->lockForUpdate()->get();
            DB::table('invoices')->whereIn('id', $map->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get();
            [$order,$invoice,$receipt,$adjustment] = $this->scope($context, $id, $receiptId, $adjustmentId, true);
            abort_unless($order->lock_version === $data['version'], 409, __('adjustment_reversal.order_changed'));
            $review = $this->describe($order, $invoice, $receipt, $adjustment, true);
            abort_unless($review['allowed'], 409, __('adjustment_reversal.blocked'));
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, __('adjustment_reversal.stale'));
            $snapshot = ['adjustment' => (array) $adjustment, 'review' => $review, 'reason' => $data['reason'], 'actor_name' => $actor->name];
            $json = app(FinancialDocument::class)->canonical($snapshot);
            $reversal = DB::table('return_adjustment_reversals')->insertGetId(['adjustment_id' => $adjustmentId, 'amount' => $review['amount'], 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'snapshot' => $json, 'data_hash' => hash('sha256', $json), 'created_at' => now()]);
            DB::table('return_adjustment_heads')->where('sales_return_id', $receiptId)->update(['active_adjustment_id' => null]);
            // Mutable receipt projection only; immutable revision lines/snapshots remain untouched.
            DB::table('sales_return_items')->where('sales_return_id', $receiptId)->update(['refund_amount' => '0.0000']);
            DB::table('sales_returns')->where('id', $receiptId)->update(['status' => 'received', 'updated_at' => now()]);
            $after = OrderSettlement::position($order, $invoice->id, true);
            abort_unless(bccomp($after['net_total'], $review['net_after'], 4) === 0 && bccomp($after['pending_total'], $review['pending_after'], 4) === 0 && bccomp($after['paid_total'], bcadd($review['balance']['paid_total'], $review['credit_to_reallocate'], 4), 4) === 0, 409, __('adjustment_reversal.order_conservation'));
            foreach ($review['payments'] as $before) {
                $fund = OrderSettlement::payment($before['payment_id'], true);
                abort_unless(bccomp($fund['available'], bcsub($before['available'], $before['restore'], 4), 4) === 0 && bccomp($fund['effective_allocated'], bcadd($before['effective_allocated'], $before['restore'], 4), 4) === 0 && bccomp($fund['refunded'], $before['refunded'], 4) === 0, 409, __('adjustment_reversal.payment_conservation'));
            }
            $order->lock_version++;
            $order->save();
            $this->sales->refreshInvoiceBalance($invoice->id);
            $this->audit->record('sales.return.adjustment_reversed', $order, after: ['adjustment_id' => $adjustmentId, 'sales_return_id' => $receiptId, 'reversal_id' => $reversal, 'amount' => $review['amount'], 'reason' => $data['reason']], actor: $actor);

            return ['order_id' => $id, 'sales_return_id' => $receiptId, 'adjustment_id' => $adjustmentId, 'reversal_id' => $reversal];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($id)), 'adjustment_id' => $adjustmentId, 'reversal_id' => $result['reversal_id'], 'replay' => $result['replay']];
    }
}
