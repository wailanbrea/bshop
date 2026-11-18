<?php

namespace Tests\Unit;

use App\Domain\Sales\PaymentDistribution;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentDistributionTest extends TestCase
{
    public function test_partial_payment_includes_tax_and_shipping_but_only_products_generate_commission(): void
    {
        $rows = PaymentDistribution::allocate('64.00', [
            ['key' => 'product-a', 'remaining' => '60.00'], ['key' => 'product-b', 'remaining' => '40.00'],
            ['key' => 'tax', 'remaining' => '18.00'], ['key' => 'shipping', 'remaining' => '10.00'],
        ]);
        $this->assertSame(['30.0000', '20.0000', '9.0000', '5.0000'], array_column($rows, 'amount'));
        $this->assertSame('3.0000', PaymentDistribution::commission($rows[0]['amount'], '10'));
        $this->assertSame('2.5000', PaymentDistribution::commission($rows[1]['amount'], '12.5'));
    }

    public function test_residual_cents_are_deterministic_and_never_create_or_exceed_balances(): void
    {
        $remaining = ['a' => '0.01', 'b' => '0.01', 'c' => '0.01'];
        foreach (['a', 'b', 'c'] as $expected) {
            $rows = PaymentDistribution::allocate('0.01', array_map(fn ($key) => ['key' => $key, 'remaining' => $remaining[$key]], array_keys($remaining)));
            $total = '0';
            foreach ($rows as $row) {
                $this->assertLessThanOrEqual(0, bccomp($row['amount'], $remaining[$row['key']], 4));
                if ($row['amount'] === '0.0100') {
                    $this->assertSame($expected, $row['key']);
                }
                $total = bcadd($total, $row['amount'], 4);
                $remaining[$row['key']] = bcsub($remaining[$row['key']], $row['amount'], 4);
            }
            $this->assertSame('0.0100', $total);
        }
        $this->assertSame(['0.0000', '0.0000', '0.0000'], array_values($remaining));
        $this->assertSame('0.0100', PaymentDistribution::commission('0.05', '10'));
    }

    public function test_large_decimal_amounts_are_distributed_without_floating_point(): void
    {
        $rows = PaymentDistribution::allocate('999999999999999.98', [['key' => 'item', 'remaining' => '999999999999999.98'], ['key' => 'tax', 'remaining' => '0.01']]);
        $this->assertSame('999999999999999.9800', bcadd($rows[0]['amount'], $rows[1]['amount'], 4));
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        PaymentDistribution::allocate('1.01', [['key' => 'item', 'remaining' => '1.00']]);
    }

    public function test_fractional_cent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        PaymentDistribution::allocate('0.001', [['key' => 'item', 'remaining' => '1.00']]);
    }

    public function test_commission_over_one_hundred_percent_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        PaymentDistribution::commission('10', '100.0001');
    }
}
