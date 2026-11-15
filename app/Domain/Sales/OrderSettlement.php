<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class OrderSettlement
{
    public static function sum($values): string
    {
        $sum = '0.0000';
        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, 4);
        }

        return $sum;
    }

    public static function total($query, string $column, bool $lock = false): string
    {
        if ($lock) {
            $query->lockForUpdate();
        }

        return self::sum($query->pluck($column));
    }

    public static function credited(int $orderId, bool $lock = false): string
    {
        return self::total(DB::table('return_adjustments')->where('order_id', $orderId)->orderBy('id'), 'amount', $lock);
    }

    public static function payment(int $id, bool $lock = false): array
    {
        $query = DB::table('payments')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }$payment = $query->firstOrFail();
        $allocated = self::total(DB::table('payment_allocations')->where('payment_id', $id)->orderBy('id'), 'amount', $lock);
        $released = self::total(DB::table('payment_allocation_releases')->where('payment_id', $id)->orderBy('id'), 'amount', $lock);
        $refunded = self::total(DB::table('refunds')->where('original_payment_id', $id)->where('status', 'confirmed')->orderBy('id'), 'amount', $lock);
        $legacy = self::total(DB::table('customer_credit_movements')->where('payment_id', $id)->orderBy('id'), 'amount_delta', $lock);
        $reversed = self::total(DB::table('credit_application_reversals as v')->join('credit_applications as c', 'c.id', '=', 'v.credit_application_id')->where('c.payment_id', $id)->orderBy('v.id'), 'v.amount', $lock);

        return ['payment' => $payment, 'allocated' => $allocated, 'released' => $released, 'refunded' => $refunded, 'legacy_credit' => $legacy, 'reversed_credit' => $reversed, 'available' => bcadd(bcadd($legacy, $released, 4), $reversed, 4), 'effective_allocated' => bcsub(bcsub($allocated, $released, 4), $reversed, 4)];
    }

    public static function position(object $order, ?int $invoiceId, bool $lock = false): array
    {
        $credit = self::credited($order->id, $lock);
        $net = bcsub((string) $order->grand_total, $credit, 4);
        abort_if(bccomp($net, '0', 4) < 0, 409, 'El documento requiere conciliar sus ajustes.');
        $query = DB::table('order_payments as op')->join('payments as p', 'p.id', '=', 'op.payment_id')->where('op.order_id', $order->id)->where('p.status', 'confirmed')->orderBy('p.id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $gross = '0.0000';
        $refundQuery = DB::table('refunds as r')->leftJoin('sales_return_receipts as c', 'c.sales_return_id', '=', 'r.sales_return_id')->leftJoin('order_payments as op', 'op.payment_id', '=', 'r.original_payment_id')->where('r.status', 'confirmed')->where(fn ($q) => $q->where('c.order_id', $order->id)->orWhere(fn ($unused) => $unused->whereNull('r.sales_return_id')->where('op.order_id', $order->id)))->orderBy('r.id');
        $refunds = self::total($refundQuery, 'r.amount', $lock);
        $paid = '0.0000';
        $available = $invoiceId ? CreditPools::orderAvailable($order->id, $lock) : '0.0000';
        foreach ($query->get(['p.id', 'p.amount']) as $payment) {
            $gross = bcadd($gross, (string) $payment->amount, 4);
            if ($invoiceId) {
                $legacyAllocated = self::total(DB::table('payment_allocations as a')->leftJoin('credit_applications as c', 'c.payment_allocation_id', '=', 'a.id')->where('a.payment_id', $payment->id)->whereNull('c.id')->orderBy('a.id'), 'a.amount', $lock);
                $unusedRefund = self::total(DB::table('refunds')->where('original_payment_id', $payment->id)->where('refund_kind', 'unused_credit')->where('status', 'confirmed')->orderBy('id'), 'amount', $lock);
                $legacyAvailable = bcsub(bcsub((string) $payment->amount, $legacyAllocated, 4), $unusedRefund, 4);
                abort_if(bccomp($legacyAvailable, '0', 4) < 0, 409, 'El crédito original requiere conciliación.');
                $available = bcadd($available, $legacyAvailable, 4);
            }
        }
        if (! $invoiceId) {
            $paid = bcsub($gross, $refunds, 4);
        } else {
            $allocated = self::total(CreditPools::allocations($order->id, $invoiceId)->orderBy('a.id'), 'a.amount', $lock);
            $released = self::total(DB::table('payment_allocation_releases as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->where('r.order_id', $order->id)->where('r.invoice_id', $invoiceId)->where('p.status', 'confirmed')->orderBy('r.id'), 'r.amount', $lock);
            $paid = bcsub($allocated, $released, 4);
        }
        $incoming = self::total(DB::table('credit_applications as c')->join('payments as p', 'p.id', '=', 'c.payment_id')->where('c.target_order_id', $order->id)->where('p.status', 'confirmed')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_application_reversals as v')->whereColumn('v.credit_application_id', 'c.id'))->orderBy('c.id'), 'c.amount', $lock);
        $outgoing = CreditPools::outgoing($order->id, $lock);
        $pending = bcsub($net, $paid, 4);

        return ['original_total' => bcadd((string) $order->grand_total, '0', 4), 'return_credit_total' => $credit, 'net_total' => $net, 'gross_received' => $gross, 'refunded_total' => $refunds, 'paid_total' => $paid, 'credit_available' => $available, 'credit_received_total' => $incoming, 'credit_applied_total' => $outgoing, 'pending_total' => bccomp($pending, '0', 4) > 0 ? $pending : '0.0000'];
    }

    public static function invoice(object $invoice, bool $lock = false): array
    {
        $credit = self::total(DB::table('return_adjustments')->where('invoice_id', $invoice->id)->orderBy('id'), 'amount', $lock);
        $net = bcsub((string) $invoice->grand_total, $credit, 4);
        $paid = self::total(DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('a.invoice_id', $invoice->id)->where('p.status', 'confirmed')->orderBy('a.id'), 'a.amount', $lock);
        $released = self::total(DB::table('payment_allocation_releases as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->where('r.invoice_id', $invoice->id)->where('p.status', 'confirmed')->orderBy('r.id'), 'r.amount', $lock);
        $paid = bcsub($paid, $released, 4);
        $reversed = self::total(DB::table('credit_application_reversals as v')->join('credit_applications as c', 'c.id', '=', 'v.credit_application_id')->join('payments as p', 'p.id', '=', 'c.payment_id')->where('c.invoice_id', $invoice->id)->where('p.status', 'confirmed')->orderBy('v.id'), 'v.amount', $lock);
        $paid = bcsub($paid, $reversed, 4);
        $due = bcsub($net, $paid, 4);
        abort_if(bccomp($net, '0', 4) < 0 || bccomp($paid, '0', 4) < 0 || bccomp($due, '0', 4) < 0, 409, 'La factura requiere conciliación de sus asignaciones.');

        return ['original_total' => bcadd((string) $invoice->grand_total, '0', 4), 'return_credit_total' => $credit, 'net_total' => $net, 'paid_total' => $paid, 'pending_total' => $due];
    }
}
