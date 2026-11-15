<?php

namespace App\Domain\Sales;

use Illuminate\Validation\ValidationException;

final class Money
{
    /** Positive amounts rounded half-up to USD cents, persisted as DECIMAL(19,4). */
    public static function round(string $amount): string
    {
        $rounded = bcadd(bcadd($amount, '0.005', 8), '0', 2);
        if (bccomp($rounded, '999999999999999.9999', 4) > 0 || bccomp($rounded, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => 'El importe excede los límites permitidos.']);
        }

        return bcadd($rounded, '0', 4);
    }
}
