<?php

namespace Tests\Feature;

use App\Models\ProductVariant;
use Database\Seeders\BusinessFixtureSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SingleBusinessSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixtures_create_one_business_with_two_branches_and_exact_prices(): void
    {
        $this->seed(BusinessFixtureSeeder::class);
        $this->assertDatabaseCount('business_profile', 1);
        $this->assertDatabaseCount('stores', 2);
        $this->assertDatabaseCount('warehouses', 2);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseCount('product_variants', 3);
        $this->assertSame('1250.0000', ProductVariant::firstOrFail()->sale_price);
    }

    public function test_salary_check_cannot_be_bypassed_by_null_period(): void
    {
        $this->seed(BusinessFixtureSeeder::class);
        $this->expectException(QueryException::class);
        DB::table('employee_compensation_plans')->insert(['employee_id' => DB::table('employees')->value('id'), 'pay_type' => 'salary', 'currency' => 'DOP', 'fixed_salary' => '100.0000', 'effective_from' => '2026-01-01']);
    }
}
