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

final class CreditApplicationReversals
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    private function scope(StoreContext $context, int $order, int $application, bool $lock = false): array
    {
        $q = SalesOrder::where('store_id', $context->store()->id);
        if ($lock) {
            $q->lockForUpdate();
        }$target = $q->findOrFail($order);
        $q = DB::table('credit_applications')->where('id', $application)->where('store_id', $context->store()->id)->where('target_order_id', $order);
        if ($lock) {
            $q->lockForUpdate();
        }$entry = $q->firstOrFail();
        $q = OrderInvoice::query($order);
        if ($lock) {
            $q->lockForUpdate();
        }$invoice = $q->firstOrFail();
        abort_unless((int) $invoice->id === (int) $entry->invoice_id, 409, 'La aplicación y su factura requieren conciliación.');

        return [$target, $entry, $invoice];
    }

    private function describe(SalesOrder $order, object $entry, object $invoice, bool $lock = false): array
    {
        $q = DB::table('credit_application_reversals')->where('credit_application_id', $entry->id);
        if ($lock) {
            $q->lockForUpdate();
        }$reversal = $q->first();
        $fund = OrderSettlement::payment($entry->payment_id, $lock);
        $balance = OrderSettlement::position($order, $invoice->id, $lock);
        $q = AdjustmentState::releases(DB::table('payment_allocation_releases')->where('payment_allocation_id', $entry->payment_allocation_id));
        if ($lock) {
            $q->lockForUpdate();
        }$released = (bool) $q->first();
        $q = DB::table('refunds')->where('original_payment_id', $entry->payment_id)->where('status', 'pending');
        if ($lock) {
            $q->lockForUpdate();
        }$uncertain = (bool) $q->first();
        $q = DB::table('commission_entries')->where('invoice_id', $invoice->id);
        if ($lock) {
            $q->lockForUpdate();
        }$commissions = (bool) $q->first();
        $held = ReturnReceiptState::pendingInvoice($invoice->id, $lock);
        $allowed = ! $reversal && ! $released && ! $uncertain && ! $commissions && ! $held && $fund['payment']->status === 'confirmed' && $order->status !== 'cancelled' && in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true) && bccomp($balance['paid_total'], (string) $entry->amount, 4) >= 0;
        $result = ['application_id' => (int) $entry->id, 'order_id' => $order->id, 'version' => $order->lock_version, 'currency' => $entry->currency, 'amount' => bcadd((string) $entry->amount, '0', 4), 'payment_id' => (int) $entry->payment_id, 'origin_order_id' => (int) $entry->origin_order_id, 'invoice_number' => $invoice->invoice_number, 'balance' => $balance, 'pending_after' => bcadd($balance['pending_total'], (string) $entry->amount, 4), 'allowed' => $allowed, 'already_reversed' => (bool) $reversal, 'released' => $released, 'return_pending' => $held, 'refund_pending' => $uncertain, 'commissions' => $commissions, 'fund_available' => $fund['available'], 'pools' => CreditPools::rows($entry->payment_id, $lock)];
        if ($reversal) {
            $result['pending_after'] = $balance['pending_total'];
        }
        $result['review_hash'] = hash('sha256', app(FinancialDocument::class)->canonical($result));

        return $result;
    }

    public function info(StoreContext $context, int $order, int $application): array
    {
        return DB::transaction(function () use ($context, $order, $application) {
            Store::whereKey($context->store()->id)->sharedLock()->firstOrFail();

            return $this->describe(...$this->scope($context, $order, $application));
        });
    }

    public function reverse(StoreContext $context, int $id, int $application, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'review_hash' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:255']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('credits.reverse', $key, [$actor->id, $context->store()->id, $id, $application], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'credits.reverse');
        }, function () use ($context, $id, $application, $actor, $data) {
            [$ref,$app] = $this->scope($context, $id, $application);
            $orders = DB::table('payment_allocation_releases')->where('payment_id', $app->payment_id)->pluck('order_id')->push($app->origin_order_id)->push($id)->unique()->sort()->values()->all();
            $variantIds = DB::table('order_items')->whereIn('order_id', $orders)->whereNull('removed_at')->pluck('variant_id')->unique();
            $variants = ProductVariant::withTrashed()->whereIn('id', $variantIds)->get();
            Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $current = DB::table('payment_allocation_releases')->where('payment_id', $app->payment_id)->orderBy('id')->lockForUpdate()->get()->pluck('order_id')->push($app->origin_order_id)->push($id)->unique()->all();
            abort_if(array_diff($current, $orders), 409, 'El origen del crédito cambió. Actualiza la revisión.');
            $locked = SalesOrder::whereIn('id', $orders)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($locked as $row) {
                abort_unless($row->store_id === $ref->store_id && $row->customer_id === $ref->customer_id && $row->currency === $ref->currency, 409, 'El crédito cruza cliente, moneda o sucursal.');
            }
            $map = DB::table('invoice_orders')->whereIn('order_id', $orders)->orderBy('order_id')->lockForUpdate()->get();
            DB::table('invoices')->whereIn('id', $map->pluck('invoice_id'))->orderBy('id')->lockForUpdate()->get();
            [$order,$entry,$invoice] = $this->scope($context, $id, $application, true);
            abort_unless($order->lock_version === $data['version'], 409, 'El pedido cambió. Actualiza la revisión.');
            $review = $this->describe($order, $entry, $invoice, true);
            abort_unless($review['allowed'], 409, 'Esta aplicación ya fue revertida, liberada o requiere conciliación adicional.');
            abort_unless(hash_equals($review['review_hash'], $data['review_hash']), 409, 'Los saldos cambiaron. Revisa la reversión.');
            $sources = DB::table('credit_application_sources as s')->join('payment_allocation_releases as r', 'r.id', '=', 's.release_id')->where('s.credit_application_id', $application)->orderBy('s.release_id')->lockForUpdate()->get(['s.release_id', 's.amount', 'r.order_id', 'r.payment_id']);
            abort_unless(bccomp(OrderSettlement::sum($sources->pluck('amount')), (string) $entry->amount, 4) === 0 && $sources->every(fn ($s) => (int) $s->payment_id === (int) $entry->payment_id), 409, 'La procedencia del crédito requiere conciliación.');
            $fund = OrderSettlement::payment($entry->payment_id, true);
            abort_unless(bccomp(bcadd(bcadd($fund['effective_allocated'], $fund['available'], 4), $fund['refunded'], 4), (string) $fund['payment']->amount, 4) === 0, 409, 'El pago requiere conciliación.');
            $snapshot = ['application' => (array) $entry, 'review' => $review, 'sources' => $sources->map(fn ($s) => (array) $s)->all(), 'reason' => $data['reason'], 'actor_name' => $actor->name];
            $json = app(FinancialDocument::class)->canonical($snapshot);
            $reversal = DB::table('credit_application_reversals')->insertGetId(['credit_application_id' => $application, 'amount' => $review['amount'], 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'snapshot' => $json, 'data_hash' => hash('sha256', $json), 'created_at' => now()]);
            $after = OrderSettlement::payment($entry->payment_id, true);
            abort_unless(bccomp($after['available'], bcadd($fund['available'], $review['amount'], 4), 4) === 0 && bccomp($after['effective_allocated'], bcsub($fund['effective_allocated'], $review['amount'], 4), 4) === 0, 409, 'La compensación no conserva el crédito del pago.');
            foreach (array_unique([...$sources->pluck('order_id')->all(), $id]) as $orderId) {
                $row = $locked[$orderId];
                $row->lock_version++;
                $row->save();
            }
            $this->sales->refreshInvoiceBalance($invoice->id);
            $this->audit->record('sales.credit.reversed', $order, after: ['credit_application_id' => $application, 'reversal_id' => $reversal, 'amount' => $review['amount'], 'reason' => $data['reason']], actor: $actor);

            return ['order_id' => $id, 'credit_application_id' => $application, 'reversal_id' => $reversal];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($id)), 'credit_application_id' => $application, 'reversal_id' => $result['reversal_id'], 'replay' => $result['replay']];
    }
}
