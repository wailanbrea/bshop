<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeContractsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-16T12:00:00Z'));
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Employees QA', 'default_currency' => 'USD', 'timezone' => 'America/Santo_Domingo', 'store_name' => 'Principal']);
        $this->store = Store::firstOrFail()->id;
        $this->actingAs($this->owner);
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store.'/employees';
    }

    private function employee(): int
    {
        return $this->postJson($this->root(), ['request_key' => (string) Str::uuid(), 'employee_code' => 'VEN-01', 'full_name' => 'Vendedora QA'])->assertCreated()->json('data.employee.id');
    }

    public function test_employee_creation_is_idempotent_and_assigned_to_the_authorized_store(): void
    {
        $data = ['request_key' => (string) Str::uuid(), 'employee_code' => 'ven-01', 'full_name' => 'Vendedora QA'];
        $id = $this->postJson($this->root(), $data)->assertCreated()->json('data.employee.id');
        $this->postJson($this->root(), $data)->assertOk()->assertJsonPath('data.replay', true)->assertJsonPath('data.employee.id', $id);
        $this->postJson($this->root(), [...$data, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->getJson($this->root())->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('employee_store_assignments', ['employee_id' => $id, 'store_id' => $this->store]);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.created', 'user_id' => $this->owner->id]);
    }

    public function test_salary_and_commission_contracts_are_immutable_nonoverlapping_and_idempotent(): void
    {
        $id = $this->employee();
        $url = $this->root()."/$id/plans";
        $salary = ['request_key' => (string) Str::uuid(), 'pay_type' => 'salary', 'fixed_salary' => '1234.5678', 'salary_period' => 'weekly', 'effective_from' => '2026-11-01', 'effective_to' => '2026-11-30'];
        $plan = $this->postJson($url, $salary)->assertCreated()->json('data.plan.id');
        $this->postJson($url, $salary)->assertOk()->assertJsonPath('data.replay', true)->assertJsonPath('data.plan.id', $plan);
        $commission = ['request_key' => (string) Str::uuid(), 'pay_type' => 'commission', 'commission_rate' => '12.5000', 'effective_from' => '2026-12-01'];
        $this->postJson($url, [...$commission, 'effective_from' => '2026-11-30'])->assertConflict();
        $this->postJson($url, [...$commission, 'commission_rate' => '100.0001'])->assertUnprocessable();
        $this->postJson($url, [...$commission, 'commission_rate' => 12.5])->assertUnprocessable();
        $this->postJson($url, $commission)->assertCreated()->assertJsonPath('data.plan.pay_type', 'commission');
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('employee_compensation_plans', ['id' => $plan, 'employee_id' => $id, 'pay_type' => 'salary', 'salary_period' => 'weekly']);
        $this->assertDatabaseHas('employee_compensation_plans', ['employee_id' => $id, 'pay_type' => 'commission', 'commission_base' => 'net_items', 'commission_trigger' => 'payment']);
        $this->assertDatabaseCount('commission_entries', 0);
        $this->assertDatabaseCount('payroll_disbursements', 0);
    }

    public function test_historical_contract_cannot_be_inserted_after_an_existing_plan(): void
    {
        $id = $this->employee();
        $url = $this->root()."/$id/plans";
        $base = ['request_key' => (string) Str::uuid(), 'pay_type' => 'commission', 'commission_rate' => '10', 'effective_from' => '2026-12-01', 'effective_to' => '2026-12-31'];
        $this->postJson($url, $base)->assertCreated();
        $this->postJson($url, [...$base, 'request_key' => (string) Str::uuid(), 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'])->assertConflict();
        $this->assertSame(1, DB::table('employee_compensation_plans')->count());
    }

    public function test_future_replacement_requires_explicit_confirmation_and_preserves_the_previous_rate(): void
    {
        $id = $this->employee();
        $url = $this->root()."/$id/plans";
        $first = ['request_key' => (string) Str::uuid(), 'pay_type' => 'commission', 'commission_rate' => '10.1250', 'effective_from' => '2026-11-01'];
        $old = $this->postJson($url, $first)->assertCreated()->json('data.plan.id');
        $next = ['request_key' => (string) Str::uuid(), 'pay_type' => 'commission', 'commission_rate' => '15', 'effective_from' => '2026-12-01'];
        $this->postJson($url, $next)->assertConflict();
        $next['request_key'] = (string) Str::uuid();
        $next['end_open_plan'] = true;
        $new = $this->postJson($url, $next)->assertCreated()->json('data.plan.id');
        $this->postJson($url, $next)->assertOk()->assertJsonPath('data.replay', true)->assertJsonPath('data.plan.id', $new);
        $oldRow = DB::table('employee_compensation_plans')->where('id', $old)->first();
        $this->assertSame('2026-11-30', $oldRow->effective_to);
        $this->assertSame('10.1250', bcadd((string) $oldRow->commission_rate, '0', 4));
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.plan.end_scheduled', 'user_id' => $this->owner->id]);
        $this->assertDatabaseCount('employee_compensation_plans', 2);
    }

    public function test_permissions_and_branch_scope_protect_employee_contracts(): void
    {
        $id = $this->employee();
        $other = Store::create(['name' => 'Otra sucursal QA', 'code' => 'OTRA']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/employees/'.$id.'/plans')->assertNotFound();
        $seller = User::factory()->create();
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        $this->actingAs($seller);
        $this->getJson($this->root())->assertForbidden();
        $this->postJson($this->root(), ['request_key' => (string) Str::uuid(), 'employee_code' => 'X', 'full_name' => 'Sin permiso'])->assertForbidden();
    }

    public function test_employee_can_be_updated_deactivated_and_assigned_to_multiple_authorized_stores(): void
    {
        $id = $this->employee();
        $other = Store::create(['name' => 'Sucursal Norte', 'code' => 'NORTE']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $payload = [
            'request_key' => (string) Str::uuid(),
            'employee_code' => 'ven-01-edit',
            'full_name' => 'Vendedora Actualizada',
            'status' => 'inactive',
            'store_ids' => [$this->store, $other->id],
        ];
        $this->patchJson($this->root()."/$id", $payload)
            ->assertOk()
            ->assertJsonPath('data.employee.employee_code', 'VEN-01-EDIT')
            ->assertJsonPath('data.employee.full_name', 'Vendedora Actualizada')
            ->assertJsonPath('data.employee.status', 'inactive');
        $this->patchJson($this->root()."/$id", $payload)
            ->assertOk()
            ->assertJsonPath('data.replay', true);
        $this->assertDatabaseHas('employee_store_assignments', ['employee_id' => $id, 'store_id' => $other->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/employees')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_sales_order_attributes_seller_snapshots_active_commission_plan_and_reports_commercial_summary(): void
    {
        $id = $this->employee();
        $url = $this->root()."/$id/plans";
        $plan = $this->postJson($url, [
            'request_key' => (string) Str::uuid(),
            'pay_type' => 'commission',
            'commission_rate' => '10.0000',
            'effective_from' => '2026-11-01',
        ])->assertCreated()->json('data.plan.id');

        $product = app(ProductService::class)->create([
            'name' => 'Blusa Comision',
            'product_type' => 'service',
            'track_stock' => false,
            'unit_code' => 'unit',
            'status' => 'active',
            'variants' => [['sku' => 'COM-01', 'cost_price' => '40', 'sale_price' => '100', 'attribute_value_ids' => []]],
        ], $this->owner);
        $variantId = $product->variants()->firstOrFail()->id;
        $warehouseId = Warehouse::firstOrFail()->id;
        $customerId = Customer::factory()->create(['full_name' => 'Cliente Comision'])->id;

        $this->getJson('/api/v1/business/stores/'.$this->store.'/sales/sellers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);

        $quoteData = [
            'warehouse_id' => $warehouseId,
            'customer_id' => $customerId,
            'lines' => [['variant_id' => $variantId, 'quantity' => '2']],
            'shipping' => '0',
            'discount' => '0',
        ];
        $hash = $this->postJson('/api/v1/business/stores/'.$this->store.'/sales/quote', $quoteData)->assertOk()->json('data.quote_hash');
        $order = $this->postJson('/api/v1/business/stores/'.$this->store.'/sales/orders', [
            ...$quoteData,
            'quote_hash' => $hash,
            'request_key' => (string) Str::uuid(),
            'mode' => 'sale',
            'initial_payment' => '150',
            'payment_method' => 'cash',
            'seller_employee_id' => $id,
        ])->assertCreated()->json('data.order');

        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'seller_employee_id' => $id,
        ]);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order['id'],
            'seller_employee_id' => $id,
            'commission_plan_id' => $plan,
            'commission_rate_snapshot' => '10.0000',
        ]);

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('summary.orders_count', 1)
            ->assertJsonPath('summary.sales_net', '200.0000')
            ->assertJsonPath('summary.collected_net', '150.0000')
            ->assertJsonPath('summary.earned_commission', '15.0000');
    }
}
