<?php

namespace App\Domain\Sales;

use App\Rules\DecimalAmount;
use Illuminate\Validation\ValidationException;

/** Pure cent-exact distribution; callers must supply current remaining balances. */
final class PaymentDistribution
{
    public static function allocate(string $payment, array $components): array
    {
        if (count($components) < 1 || count($components) > 100) {
            throw ValidationException::withMessages(['components' => 'Indica entre 1 y 100 componentes.']);
        }
        $paymentCents = self::cents($payment);
        $total = '0';
        $seen = [];
        $rows = [];
        foreach ($components as $component) {
            $key = $component['key'] ?? null;
            if (! is_string($key) || $key === '' || strlen($key) > 120 || isset($seen[$key]) || ! is_string($component['remaining'] ?? null)) {
                throw ValidationException::withMessages(['components' => 'Los componentes deben tener claves únicas y saldos decimales.']);
            }
            $seen[$key] = true;
            $remaining = self::cents($component['remaining']);
            $total = bcadd($total, $remaining, 0);
            $rows[] = ['key' => $key, 'remaining' => $remaining, 'allocated' => '0', 'remainder' => '0'];
        }
        if (bccomp($paymentCents, $total, 0) > 0) {
            throw ValidationException::withMessages(['payment' => 'El abono supera los saldos disponibles.']);
        }
        if (bccomp($paymentCents, '0', 0) === 0) {
            return array_map(fn ($row) => ['key' => $row['key'], 'amount' => '0.0000'], $rows);
        }
        $allocated = '0';
        foreach ($rows as &$row) {
            $product = bcmul($paymentCents, $row['remaining'], 0);
            $row['allocated'] = bcdiv($product, $total, 0);
            $row['remainder'] = bcmod($product, $total);
            $allocated = bcadd($allocated, $row['allocated'], 0);
        }
        unset($row);
        $missing = (int) bcsub($paymentCents, $allocated, 0);
        $order = array_keys($rows);
        usort($order, fn ($a, $b) => bccomp($rows[$b]['remainder'], $rows[$a]['remainder'], 0) ?: strcmp($rows[$a]['key'], $rows[$b]['key']));
        for ($i = 0; $i < $missing; $i++) {
            $index = $order[$i];
            $rows[$index]['allocated'] = bcadd($rows[$index]['allocated'], '1', 0);
        }

        return array_map(fn ($row) => ['key' => $row['key'], 'amount' => bcadd(bcdiv($row['allocated'], '100', 2), '0', 4)], $rows);
    }

    public static function commission(string $productAmount, string $rate): string
    {
        self::cents($productAmount);
        $rate = DecimalAmount::normalize($rate);
        if (bccomp($rate, '100', 4) > 0) {
            throw ValidationException::withMessages(['rate' => 'El porcentaje no puede superar 100.']);
        }

        return Money::round(bcdiv(bcmul($productAmount, $rate, 8), '100', 8));
    }

    private static function cents(string $amount): string
    {
        $normalized = DecimalAmount::normalize($amount);
        if (bccomp($normalized, bcadd($normalized, '0', 2), 4) !== 0) {
            throw ValidationException::withMessages(['amount' => 'El importe debe estar expresado en centavos.']);
        }

        return bcmul($normalized, '100', 0);
    }
}
