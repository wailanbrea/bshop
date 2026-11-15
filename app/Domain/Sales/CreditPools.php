<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class CreditPools
{
    public static function allocations(int $orderId, int $invoiceId)
    {
        return DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->leftJoin('order_payments as op', 'op.payment_id', '=', 'a.payment_id')->leftJoin('credit_applications as c', 'c.payment_allocation_id', '=', 'a.id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_application_reversals as v')->whereColumn('v.credit_application_id', 'c.id'))
            ->where('a.invoice_id', $invoiceId)->where('p.status', 'confirmed')->where(fn ($q) => $q->where('c.target_order_id', $orderId)->orWhere(fn ($legacy) => $legacy->whereNull('c.id')->where('op.order_id', $orderId)))->select('a.*');
    }

    public static function used(int $releaseId, bool $lock = false): string
    {
        return OrderSettlement::total(DB::table('credit_application_sources')->where('release_id', $releaseId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_application_reversals as v')->whereColumn('v.credit_application_id', 'credit_application_sources.credit_application_id'))->orderBy('credit_application_id'), 'amount', $lock);
    }

    public static function rows(int $paymentId, bool $lock = false): array
    {
        $q = DB::table('payment_allocation_releases as r')->join('return_adjustments as a', 'a.id', '=', 'r.adjustment_id')->where('r.payment_id', $paymentId)->orderBy('r.id')->select('r.*', 'a.sales_return_id');
        if ($lock) {
            $q->lockForUpdate();
        }$rows = $q->get();
        $refunds = [];
        $result = [];
        foreach ($rows as $row) {
            $group = (int) $row->adjustment_id;
            if (! array_key_exists($group, $refunds)) {
                $refunds[$group] = OrderSettlement::total(DB::table('refunds')->where('sales_return_id', $row->sales_return_id)->where('original_payment_id', $paymentId)->where('status', 'confirmed')->orderBy('id'), 'amount', $lock);
            }
            $used = self::used($row->id, $lock);
            $remaining = bcsub((string) $row->amount, $used, 4);
            abort_if(bccomp($remaining, '0', 4) < 0, 409, 'El crédito consumido excede su liberación original.');
            $refund = bccomp($refunds[$group], $remaining, 4) > 0 ? $remaining : $refunds[$group];
            $refunds[$group] = bcsub($refunds[$group], $refund, 4);
            $result[] = ['release_id' => (int) $row->id, 'adjustment_id' => $group, 'order_id' => (int) $row->order_id, 'invoice_id' => (int) $row->invoice_id, 'sales_return_id' => (int) $row->sales_return_id, 'payment_id' => $paymentId, 'released' => bcadd((string) $row->amount, '0', 4), 'used' => $used, 'refunded' => $refund, 'available' => bcsub($remaining, $refund, 4)];
        }
        foreach ($refunds as $left) {
            abort_if(bccomp($left, '0', 4) > 0, 409, 'Los reembolsos y el crédito aplicado requieren conciliación.');
        }

        return $result;
    }

    public static function orderAvailable(int $orderId, bool $lock = false): string
    {
        $q = DB::table('payment_allocation_releases as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->where('r.order_id', $orderId)->where('p.status', 'confirmed')->orderBy('r.id');
        if ($lock) {
            $q->lockForUpdate();
        }$payments = $q->get(['r.payment_id'])->pluck('payment_id')->unique();
        $sum = '0.0000';
        foreach ($payments as $id) {
            foreach (self::rows($id, $lock) as $row) {
                if ($row['order_id'] === $orderId) {
                    $sum = bcadd($sum, $row['available'], 4);
                }
            }
        }

        return $sum;
    }

    public static function outgoing(int $orderId, bool $lock = false): string
    {
        return OrderSettlement::total(DB::table('credit_application_sources as s')->join('payment_allocation_releases as r', 'r.id', '=', 's.release_id')->where('r.order_id', $orderId)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_application_reversals as v')->whereColumn('v.credit_application_id', 's.credit_application_id'))->orderBy('s.credit_application_id')->orderBy('s.release_id'), 's.amount', $lock);
    }
}
