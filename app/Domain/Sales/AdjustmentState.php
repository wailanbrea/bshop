<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class AdjustmentState
{
    public static function effective($query, string $alias = 'return_adjustments')
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('return_adjustment_reversals as av')->whereColumn('av.adjustment_id', $alias.'.id'));
    }

    public static function releases($query, string $alias = 'payment_allocation_releases')
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('return_adjustment_reversals as av')->whereColumn('av.adjustment_id', $alias.'.adjustment_id'));
    }

    public static function current(int $receipt, bool $lock = false): ?object
    {
        $q = self::effective(DB::table('return_adjustments')->where('sales_return_id', $receipt))->orderByDesc('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }
}
