<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BasketGridPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_square_page_sizes_are_bounded_and_pagination_does_not_drop_or_duplicate_baskets(): void
    {
        $owner = User::factory()->create();
        app(InstallBusiness::class)->create($owner, ['name' => 'Grid QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $store = Store::firstOrFail();
        $warehouse = Warehouse::firstOrFail();
        $customer = Customer::factory()->create();
        // Read-query fixtures only, isolated by TestCase's SQLite :memory: guard.
        for ($i = 1; $i <= 37; $i++) {
            SalesOrder::create(['store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'order_kind' => 'reservation', 'status' => 'draft', 'currency' => 'USD', 'basket_number' => (string) $i, 'customer_snapshot' => ['full_name' => $customer->full_name]]);
        }
        $this->actingAs($owner);
        $url = '/api/v1/business/stores/'.$store->id.'/baskets';
        foreach ([4, 16, 36] as $size) {
            $first = $this->getJson($url.'?per_page='.$size)->assertOk()->assertJsonCount($size, 'data')->assertJsonPath('meta.per_page', $size)->assertJsonPath('meta.total', 37)->json('data');
            $second = $this->getJson($url.'?per_page='.$size.'&page=2')->assertOk()->json('data');
            $this->assertSame([], array_values(array_intersect(array_column($first, 'id'), array_column($second, 'id'))));
        }
        $this->getJson($url.'?per_page=36&page=2')->assertOk()->assertJsonCount(1, 'data');
        foreach (['0', '1000', '6', '36.5'] as $invalid) {
            $this->getJson($url.'?per_page='.$invalid)->assertUnprocessable();
        }
        $this->assertDatabaseCount('orders', 37);
    }
}
