<?php

namespace App\Domain\Sales;

final class RefundState
{
    /** Exclude corrected registrations without deleting their original financial evidence. */
    public static function effective($query, string $alias = 'refunds', bool $lock = false)
    {
        return $query->whereNotExists(function ($q) use ($alias, $lock) {
            $q->selectRaw('1')->from('refund_reversals as rv')->whereColumn('rv.refund_id', $alias.'.id');
            if ($lock) {
                $q->lockForUpdate();
            }
        });
    }
}
