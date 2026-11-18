<?php

namespace Tests\Unit;

use App\Domain\Sales\SalaryProration;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalaryProrationTest extends TestCase
{
    public function test_salary_is_prorated_by_inclusive_contract_days_and_rounded_to_cents(): void
    {
        $this->assertSame(['amount' => '800.0000', 'eligible_days' => 8, 'period_days' => 15], SalaryProration::calculate('1500', 'biweekly', '2026-01-01', '2026-01-15', '2026-01-08', null));
        $this->assertSame(['amount' => '300.0000', 'eligible_days' => 3, 'period_days' => 7], SalaryProration::calculate('700', 'weekly', '2026-12-28', '2027-01-03', '2026-12-31', '2027-01-02'));
        $this->assertSame(['amount' => '333.3300', 'eligible_days' => 10, 'period_days' => 30], SalaryProration::calculate('1000', 'monthly', '2026-04-01', '2026-04-30', '2026-04-21', null));
    }

    public function test_quincenas_follow_calendar_months_including_leap_years(): void
    {
        $this->assertSame(['amount' => '140.0000', 'eligible_days' => 14, 'period_days' => 14], SalaryProration::calculate('140', 'biweekly', '2028-02-16', '2028-02-29', '2028-01-01', null));
        $this->assertSame(['amount' => '0.0000', 'eligible_days' => 0, 'period_days' => 15], SalaryProration::calculate('100', 'biweekly', '2026-02-01', '2026-02-15', '2026-03-01', null));
    }

    public function test_invalid_quincena_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        SalaryProration::calculate('100', 'biweekly', '2026-02-16', '2026-02-27', '2026-02-01', null);
    }

    public function test_impossible_dates_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        SalaryProration::calculate('100', 'monthly', '2026-02-01', '2026-02-30', '2026-02-01', null);
    }
}
