<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoreTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoTenants(): array
    {
        BusinessProfile::create([
            'id' => 1,
            'name' => 'KeylaShop',
            'default_currency' => 'USD',
            'currency_decimals' => 2,
            'timezone' => 'America/Santo_Domingo',
            'locale' => 'es_DO',
        ]);
        DB::table('business_settings')->insert([
            'id' => 1,
            'weekly_close_weekday' => 4,
            'sales_tax_rate' => '0.0000',
            'invoice_prefix' => 'FAC',
        ]);

        $ownerRole = Role::create([
            'code' => 'owner',
            'label' => 'Propietario',
            'permissions' => [
                'business.manage', 'stores.view', 'stores.manage', 'warehouses.manage',
                'members.manage', 'catalog.view', 'catalog.manage', 'catalog.costs',
                'inventory.view', 'inventory.manage', 'customers.view', 'customers.manage',
                'sales.view', 'sales.create', 'invoices.view', 'payments.record',
            ],
        ]);

        $storeA = Store::create(['name' => 'KeylaShop', 'code' => 'KEYLA', 'is_active' => true]);
        $whA = Warehouse::create(['store_id' => $storeA->id, 'name' => 'Principal Keyla', 'is_default' => true, 'is_active' => true]);
        $userA = User::create(['name' => 'Keyla Owner', 'email' => 'keyla@example.com', 'password' => bcrypt('password123'), 'is_active' => true]);
        $userA->roles()->attach($ownerRole->id);
        DB::table('store_user')->insert(['user_id' => $userA->id, 'store_id' => $storeA->id]);

        $storeB = Store::create(['name' => 'Bsolutions', 'code' => 'BSOLUTIONS', 'is_active' => true]);
        $whB = Warehouse::create(['store_id' => $storeB->id, 'name' => 'Almacén Bsolutions', 'is_default' => true, 'is_active' => true]);
        $userB = User::create(['name' => 'Bsolutions Owner', 'email' => 'bsolutions@example.com', 'password' => bcrypt('password123'), 'is_active' => true]);
        $userB->roles()->attach($ownerRole->id);
        DB::table('store_user')->insert(['user_id' => $userB->id, 'store_id' => $storeB->id]);

        return [$storeA, $whA, $userA, $storeB, $whB, $userB];
    }

    public function test_users_cannot_see_or_modify_other_stores_catalog_customers_inventory_or_team(): void
    {
        [$storeA, $whA, $userA, $storeB, $whB, $userB] = $this->seedTwoTenants();

        // Tenant A creates a product and a customer
        $prodA = $this->actingAs($userA)->postJson('/api/v1/business/products', [
            'store_id' => $storeA->id,
            'name' => 'Vestido Keyla',
            'product_type' => 'physical',
            'unit_code' => 'unit',
            'track_stock' => true,
            'status' => 'active',
            'variants' => [[
                'sku' => 'KEY-VEST-01',
                'sale_price' => '45.00',
                'cost_price' => '20.00',
                'attribute_value_ids' => [],
                'is_active' => true,
            ]],
        ])->assertCreated()->json('data');

        $custA = $this->actingAs($userA)->postJson("/api/v1/business/stores/{$storeA->id}/customers", [
            'request_key' => (string) Str::uuid(),
            'full_name' => 'Cliente Keyla',
            'phone' => '8095550001',
        ])->assertCreated()->json('data.customer');

        // Tenant B cannot see Tenant A's catalog, product detail, customers, inventory, sales catalog, or team
        $this->actingAs($userB)->getJson('/api/v1/business/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userB)->getJson("/api/v1/business/products?store_id={$storeA->id}")
            ->assertNotFound();

        $this->actingAs($userB)->getJson("/api/v1/business/products/{$prodA['id']}")
            ->assertNotFound();

        $this->actingAs($userB)->deleteJson("/api/v1/business/products/{$prodA['id']}")
            ->assertNotFound();

        $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/customers")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/customers/{$custA['id']}")
            ->assertNotFound();

        $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/warehouses/{$whB->id}/stock")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/sales/catalog?warehouse_id={$whB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $teamB = $this->actingAs($userB)->getJson('/api/v1/business/team')
            ->assertOk()
            ->json('data.members');
        $this->assertCount(1, $teamB);
        $this->assertSame('bsolutions@example.com', $teamB[0]['email']);

        // Tenant B creates a Service (no cost_price, track_stock = false)
        $serviceB = $this->actingAs($userB)->postJson('/api/v1/business/products', [
            'store_id' => $storeB->id,
            'name' => 'Landing Page',
            'category_name' => 'Servicios',
            'product_type' => 'service',
            'unit_code' => 'service',
            'track_stock' => false,
            'status' => 'active',
            'variants' => [[
                'sku' => 'BSOL-LANDING',
                'sale_price' => '200.00',
                'cost_price' => null,
                'attribute_value_ids' => [],
                'is_active' => true,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame($storeB->id, $serviceB['store_id']);
        $this->assertSame('service', $serviceB['product_type']);
        $this->assertFalse($serviceB['track_stock']);

        // Tenant B sees ONLY its own service
        $this->actingAs($userB)->getJson("/api/v1/business/products?store_id={$storeB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Landing Page');

        // Tenant A still sees ONLY its own product
        $this->actingAs($userA)->getJson("/api/v1/business/products?store_id={$storeA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vestido Keyla');
    }
}
