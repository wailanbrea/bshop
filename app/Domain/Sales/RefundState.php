<?php

namespace App\Domain\Sales;

final class RefundState
{
    /** Exclude corrected registrations without deleting their original financial evidence. */
    public static function effective($query, string $alias = 'refunds')
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('refund_reversals as rv')->whereColumn('rv.refund_id', $alias.'.id'));
    }
}
