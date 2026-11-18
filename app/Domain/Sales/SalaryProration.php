<?php

namespace App\Domain\Sales;

use App\Rules\DecimalAmount;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Pure calendar calculation; it does not create payroll, accounting entries or payments. */
final class SalaryProration
{
    public static function calculate(string $salary, string $period, string $periodStart, string $periodEnd, string $effectiveFrom, ?string $effectiveTo): array
    {
        $salary = DecimalAmount::normalize($salary);
        $start = self::date($periodStart);
        $end = self::date($periodEnd);
        $contractStart = self::date($effectiveFrom);
        $contractEnd = $effectiveTo === null ? null : self::date($effectiveTo);
        if ($end->lessThan($start) || ($contractEnd && $contractEnd->lessThan($contractStart))) {
            throw ValidationException::withMessages(['period' => 'El período o la vigencia tienen fechas inválidas.']);
        }
        $sameMonth = $start->format('Y-m') === $end->format('Y-m');
        $valid = match ($period) {
            'weekly' => $start->addDays(6)->isSameDay($end),
            'biweekly' => $sameMonth && (($start->day === 1 && $end->day === 15) || ($start->day === 16 && $end->isSameDay($start->endOfMonth()))),
            'monthly' => $sameMonth && $start->day === 1 && $end->isSameDay($start->endOfMonth()),
            default => false,
        };
        if (! $valid) {
            throw ValidationException::withMessages(['period' => 'El período no coincide con la modalidad del sueldo.']);
        }
        $periodDays = (int) $start->diffInDays($end) + 1;
        $first = $contractStart->greaterThan($start) ? $contractStart : $start;
        $last = $contractEnd && $contractEnd->lessThan($end) ? $contractEnd : $end;
        $eligibleDays = $last->lessThan($first) ? 0 : (int) $first->diffInDays($last) + 1;
        $calculated = bcdiv(bcmul($salary, (string) $eligibleDays, 8), (string) $periodDays, 8);

        return ['amount' => Money::round($calculated), 'eligible_days' => $eligibleDays, 'period_days' => $periodDays];
    }

    private static function date(string $value): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            if ($date && $date->format('Y-m-d') === $value) {
                return $date;
            }
        } catch (\Throwable) {
            // Return a validation error without exposing parser details.
        }
        throw ValidationException::withMessages(['period' => 'Indica fechas válidas en formato AAAA-MM-DD.']);
    }
}
