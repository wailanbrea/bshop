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

final class PaymentReversals
{
    public function __construct(private SalesService $sales, private IdempotentAction $once, private AuditTrailService $audit) {}

    public function history(StoreContext $context, int $orderId): array
    {
        SalesOrder::where('store_id', $context->store()->id)->findOrFail($orderId);

        return DB::table('order_payments as op')->join('payments as p', 'p.id', '=', 'op.payment_id')
            ->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')->where('op.store_id', $context->store()->id)->where('op.order_id', $orderId)
            ->orderByDesc('p.id')->get(['p.id', 'p.amount', 'p.currency', 'p.status', 'p.payment_method', 'p.paid_at', 'r.id as reversal_id', 'r.reason', 'r.actor_name', 'r.reversed_at'])
            ->map(fn ($p) => ['id' => (int) $p->id, 'amount' => bcadd((string) $p->amount, '0', 4), 'currency' => $p->currency, 'status' => $p->status,
                'payment_method' => $p->payment_method, 'paid_at' => $p->paid_at, 'reversal' => $p->reversal_id ? ['id' => (int) $p->reversal_id, 'reason' => $p->reason, 'actor_name' => $p->actor_name, 'reversed_at' => $p->reversed_at] : null])->all();
    }

    public function reverse(StoreContext $context, int $orderId, int $paymentId, User $actor, array $input): array
    {
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = Validator::make($input, ['request_key' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:5', 'max:500']])->validate();
        $key = $data['request_key'];
        unset($data['request_key']);
        $data['version'] = (int) $data['version'];
        $result = $this->once->run('payments.reverse', $key, [$actor->id, $context->store()->id, $orderId, $paymentId], $data, function () use ($context, $actor) {
            $this->sales->authorize($context, $actor, 'sales.view');
            $this->sales->authorize($context, $actor, 'payments.reverse');
        }, function () use ($context, $orderId, $paymentId, $actor, $data) {
            $reference = SalesOrder::where('store_id', $context->store()->id)->findOrFail($orderId);
            $ids = $reference->items()->pluck('variant_id')->all();
            $variants = ProductVariant::withTrashed()->whereIn('id', $ids)->get();
            Product::withTrashed()->whereIn('id', $variants->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $order = SalesOrder::where('store_id', $context->store()->id)->lockForUpdate()->findOrFail($orderId);
            abort_unless($order->lock_version === $data['version'] && $order->status !== 'cancelled', 409, 'El pedido cambió o está cancelado. Actualiza su historial.');
            $current = $order->items()->orderBy('id')->lockForUpdate()->get()->pluck('variant_id')->all();
            abort_if(array_diff($current, $ids), 409, 'Los artículos cambiaron durante la operación.');
            $invoice = OrderInvoice::query($orderId)->lockForUpdate()->first();
            abort_if($invoice && ! in_array($invoice->status, ['issued', 'partially_paid', 'paid'], true), 409, 'La factura tiene ajustes que requieren conciliación antes de revertir.');
            DB::table('order_payments')->where('order_id', $orderId)->where('payment_id', $paymentId)->where('store_id', $context->store()->id)->lockForUpdate()->firstOrFail();
            $payment = DB::table('payments')->where('id', $paymentId)->where('store_id', $context->store()->id)->lockForUpdate()->firstOrFail();
            abort_unless($payment->customer_id === $order->customer_id && $payment->currency === $order->currency, 409, 'El contexto del pago requiere conciliación.');
            abort_unless($payment->status === 'confirmed' && ! DB::table('payment_reversals')->where('payment_id', $paymentId)->lockForUpdate()->first(), 409, 'El pago ya no está confirmado o ya fue revertido.');
            abort_if(DB::table('refunds')->where('original_payment_id', $paymentId)->whereIn('status', ['pending', 'confirmed'])->lockForUpdate()->first(), 409, 'El pago tiene un reembolso pendiente o confirmado.');
            if ($invoice) {
                abort_if(DB::table('sales_returns')->where('invoice_id', $invoice->id)->lockForUpdate()->first(), 409, 'La factura tiene una devolución; requiere su flujo de conciliación.');
            }
            $allocated = '0.0000';
            foreach (DB::table('payment_allocations')->where('payment_id', $paymentId)->orderBy('id')->lockForUpdate()->get() as $allocation) {
                abort_if(DB::table('commission_entries')->where('payment_allocation_id', $allocation->id)->lockForUpdate()->first(), 409, 'El pago generó comisiones que requieren su flujo de reversión.');
                abort_unless($invoice && $allocation->invoice_id === $invoice->id && $allocation->store_id === $order->store_id && $allocation->customer_id === $order->customer_id && $allocation->currency === $order->currency, 409, 'El pago tiene asignaciones fuera de este pedido.');
                $allocated = bcadd($allocated, (string) $allocation->amount, 4);
            }
            $credit = '0.0000';
            foreach (DB::table('customer_credit_movements')->where('payment_id', $paymentId)->orderBy('id')->lockForUpdate()->pluck('amount_delta') as $delta) {
                $credit = bcadd($credit, (string) $delta, 4);
            }
            abort_unless(bccomp($allocated, (string) $payment->amount, 4) <= 0 && bccomp($credit, '0', 4) >= 0 && bccomp(bcadd($credit, $allocated, 4), (string) $payment->amount, 4) === 0, 409, 'El crédito y las asignaciones del pago requieren conciliación.');
            $id = DB::table('payment_reversals')->insertGetId(['payment_id' => $paymentId, 'order_id' => $orderId, 'store_id' => $order->store_id, 'customer_id' => $order->customer_id, 'currency' => $order->currency,
                'amount' => $payment->amount, 'allocated_amount' => $allocated, 'credit_voided' => $credit, 'reason' => $data['reason'], 'created_by_user_id' => $actor->id, 'actor_name' => $actor->name, 'reversed_at' => now()]);
            // Historical allocations remain; invoice balances exclude reversed receipts.
            // Only the unallocated remainder changes available credit.
            if (bccomp($credit, '0', 4) > 0) {
                DB::table('customer_credit_movements')->insert(['payment_id' => $paymentId, 'customer_id' => $order->customer_id, 'currency' => $order->currency,
                    'event_type' => 'reversal', 'amount_delta' => bcsub('0', $credit, 4), 'event_key' => 'payment-reversal:'.$id, 'occurred_at' => now(), 'created_at' => now()]);
            }
            DB::table('payments')->where('id', $paymentId)->update(['status' => 'reversed', 'updated_at' => now()]);
            if ($invoice) {
                $this->sales->refreshInvoiceBalance($invoice->id);
            }
            $order->lock_version++;
            $order->save();
            $this->audit->record('sales.payment.reversed', $order, after: ['reversal_id' => $id, 'payment_id' => $paymentId, 'amount' => (string) $payment->amount, 'reason' => $data['reason']], actor: $actor);

            return ['order_id' => $orderId, 'reversal_id' => $id];
        });

        return ['order' => $this->sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($result['order_id'])), 'reversal_id' => $result['reversal_id'], 'replay' => $result['replay']];
    }
}
