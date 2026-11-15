<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class AdjustmentState
{
    public static function effective($query, string $alias = 'return_adjustments', bool $lock = false)
    {
        return $query->whereNotExists(function ($q) use ($alias, $lock) {
            $q->selectRaw('1')->from('return_adjustment_reversals as av')->whereColumn('av.adjustment_id', $alias.'.id');
            if ($lock) {
                $q->lockForUpdate();
            }
        });
    }

    public static function releases($query, string $alias = 'payment_allocation_releases', bool $lock = false)
    {
        // An outer FOR UPDATE does not make a nested SELECT a current read in InnoDB.
        return $query->whereNotExists(function ($q) use ($alias, $lock) {
            $q->selectRaw('1')->from('return_adjustment_reversals as av')->whereColumn('av.adjustment_id', $alias.'.adjustment_id');
            if ($lock) {
                $q->lockForUpdate();
            }
        });
    }

    public static function current(int $receipt, bool $lock = false): ?object
    {
        $q = self::effective(DB::table('return_adjustments')->where('sales_return_id', $receipt), 'return_adjustments', $lock)->orderByDesc('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }
}
