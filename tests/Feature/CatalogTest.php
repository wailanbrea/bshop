<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    public function actingAs($user, $guard = null)
    {
        Auth::forgetGuards();
        $this->app['session']->flush();

        return parent::actingAs($user, $guard);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Negocio QA', 'default_currency' => 'DOP', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->actingAs($this->owner);
    }

    private function payload(string $sku = 'QA-001'): array
    {
        return ['name' => 'Camiseta QA', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => $sku, 'barcode' => null, 'sale_price' => '1250.50', 'cost_price' => '700', 'attribute_value_ids' => []]]];
    }

    private function create(string $sku = 'QA-001'): int
    {
        return $this->postJson('/api/v1/business/products', $this->payload($sku))->assertCreated()->json('data.id');
    }

    public function test_product_with_default_variant_exact_strings_and_audit(): void
    {
        $id = $this->create();
        $this->getJson("/api/v1/business/products/$id")->assertOk()->assertJsonPath('data.product.min_sale_price', '1250.5000')->assertJsonPath('data.product.version', 0)
            ->assertJsonPath('data.variants.data.0.sku', 'QA-001')->assertJsonPath('data.variants.data.0.cost_price', '700.0000')->assertJsonPath('data.variants.data.0.version', 0);
        $this->assertDatabaseHas('product_variants', ['product_id' => $id, 'combination_key' => 'default']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog.product.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog.variant.created']);
    }

    public function test_decimal_validation_rejects_float_negative_exponent_and_excess_precision(): void
    {
        foreach ([12.34, '-1', '1e3', '1,250.00', '0.00001', '1000000000000000', []] as $price) {
            $payload = $this->payload();
            $payload['variants'][0]['sale_price'] = $price;
            $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable()->assertJsonValidationErrors('variants.0.sale_price');
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_duplicate_sku_barcode_and_variant_batch_roll_back(): void
    {
        $this->create();
        $this->postJson('/api/v1/business/products', $this->payload('qa-001'))->assertUnprocessable();
        $payload = $this->payload('SECOND');
        $payload['variants'][] = [...$payload['variants'][0], 'sku' => 'THIRD'];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_canonical_combination_is_independent_of_value_order(): void
    {
        $size = ProductAttribute::create(['name' => 'Talla', 'is_active' => true]);
        $color = ProductAttribute::create(['name' => 'Color', 'is_active' => true]);
        $m = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'M', 'is_active' => true]);
        $l = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'L', 'is_active' => true]);
        $black = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Negro', 'is_active' => true]);
        $payload = $this->payload();
        $payload['variants'][0]['attribute_value_ids'] = [$m->id, $black->id];
        $id = $this->postJson('/api/v1/business/products', $payload)->assertCreated()->json('data.id');
        $variant = [...$payload['variants'][0], 'sku' => 'SECOND', 'version' => 0, 'is_active' => true];
        $this->postJson("/api/v1/business/products/$id/variants", [...$variant, 'attribute_value_ids' => [$black->id, $m->id]])->assertUnprocessable();
        $this->postJson("/api/v1/business/products/$id/variants", [...$variant, 'attribute_value_ids' => [$black->id, $l->id]])->assertCreated()->assertJsonPath('meta.product_version', 1);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_two_values_of_same_attribute_and_inactive_values_are_rejected(): void
    {
        $attribute = ProductAttribute::create(['name' => 'Talla', 'is_active' => true]);
        $m = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'M', 'is_active' => true]);
        $l = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'L', 'is_active' => false]);
        $payload = $this->payload();
        $payload['variants'][0]['attribute_value_ids'] = [$m->id, $l->id];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $l->update(['is_active' => true]);
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $l->update(['is_active' => false]);
        $payload['variants'][0]['attribute_value_ids'] = [$l->id];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_seller_can_read_but_cannot_see_costs_manage_or_read_archived(): void
    {
        $id = $this->create();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        $this->actingAs($seller)->getJson("/api/v1/business/products/$id")->assertOk()->assertJsonMissingPath('data.variants.data.0.cost_price');
        $this->getJson('/api/v1/business/products')->assertOk()->assertJsonMissingPath('data.0.cost_price');
        $this->postJson('/api/v1/business/products', $this->payload('ATTACK'))->assertForbidden();
        $this->getJson('/api/v1/business/products?status=all')->assertForbidden();
        $this->actingAs($this->owner);
        $metadata = $this->payload();
        unset($metadata['variants']);
        $this->patchJson("/api/v1/business/products/$id", [...$metadata, 'status' => 'archived', 'version' => 0])->assertOk();
        $this->actingAs($seller)->getJson("/api/v1/business/products/$id")->assertNotFound();
    }

    public function test_stale_product_or_variant_versions_do_not_overwrite_changes(): void
    {
        $id = $this->create();
        $variant = ProductVariant::firstOrFail();
        $change = [...$this->payload()['variants'][0], 'is_active' => true, 'version' => 0];
        unset($change['attribute_value_ids']);
        $this->patchJson("/api/v1/business/products/$id/variants/$variant->id", [...$change, 'sale_price' => '1400'])->assertOk()->assertJsonPath('data.version', 1);
        $this->patchJson("/api/v1/business/products/$id/variants/$variant->id", [...$change, 'sale_price' => '1500'])->assertConflict();
        $metadata = $this->payload();
        unset($metadata['variants']);
        $this->patchJson("/api/v1/business/products/$id", [...$metadata, 'version' => 0])->assertConflict();
        $this->assertSame('1400.0000', $variant->fresh()->sale_price);
    }

    public function test_variant_cannot_be_modified_through_another_product(): void
    {
        $first = $this->create();
        $second = $this->create('SECOND');
        $variant = ProductVariant::where('product_id', $first)->firstOrFail();
        $change = [...$this->payload()['variants'][0], 'is_active' => true, 'version' => 0];
        unset($change['attribute_value_ids']);
        $this->patchJson("/api/v1/business/products/$second/variants/$variant->id", $change)->assertNotFound();
    }

    public function test_service_cannot_track_stock_and_type_change_with_stock_is_blocked(): void
    {
        $payload = [...$this->payload(), 'product_type' => 'service', 'track_stock' => true];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $id = $this->create();
        $variant = ProductVariant::firstOrFail();
        DB::table('stock_balances')->insert(['warehouse_id' => DB::table('warehouses')->value('id'), 'variant_id' => $variant->id, 'on_hand' => '1.0000']);
        unset($payload['variants']);
        $this->patchJson("/api/v1/business/products/$id", [...$payload, 'track_stock' => false, 'version' => 0])->assertConflict();
        $this->assertSame('physical', Product::findOrFail($id)->product_type);
    }

    public function test_search_pagination_and_sql_wildcards_are_literal(): void
    {
        $this->create('MATCH_001');
        $this->create('OTHER-002');
        $this->getJson('/api/v1/business/products?q=MATCH_001')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/business/products?q=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/business/products?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_category_hierarchy_rejects_cycles_and_lookup_is_audited(): void
    {
        $root = $this->postJson('/api/v1/business/catalog/categories', ['name' => 'Ropa', 'is_active' => true])->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/business/catalog/categories', ['name' => 'Camisetas', 'parent_id' => $root, 'is_active' => true])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/business/catalog/categories/$root", ['name' => 'Ropa', 'parent_id' => $child, 'is_active' => true])->assertUnprocessable();
        $this->patchJson("/api/v1/business/catalog/categories/$root", ['name' => 'Ropa', 'parent_id' => (string) $root, 'is_active' => true])->assertUnprocessable();
        $this->getJson('/api/v1/business/catalog/options')->assertOk()->assertJsonCount(2, 'data.categories');
        $this->assertDatabaseHas('audit_logs', ['event' => 'catalog.categories.created']);
        $this->postJson('/api/v1/business/catalog/categories', ['name' => 'Camisetas', 'is_active' => true])->assertCreated();
        $this->patchJson("/api/v1/business/catalog/categories/$child", ['name' => 'Camisetas', 'is_active' => false])->assertOk()->assertJsonPath('data.parent_id', $root);
    }

    public function test_barcode_is_unique_and_archiving_does_not_free_the_sku(): void
    {
        $payload = $this->payload();
        $payload['variants'][0]['barcode'] = 'abc123';
        $id = $this->postJson('/api/v1/business/products', $payload)->assertCreated()->json('data.id');
        $second = $this->payload('SECOND');
        $second['variants'][0]['barcode'] = 'ABC123';
        $this->postJson('/api/v1/business/products', $second)->assertUnprocessable()->assertJsonValidationErrors('variants.0.barcode');
        unset($payload['variants']);
        $this->patchJson("/api/v1/business/products/$id", [...$payload, 'status' => 'archived', 'version' => 0])->assertOk();
        $this->postJson('/api/v1/business/products', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('products', 1);
    }

    public function test_malformed_sku_does_not_raise_server_errors(): void
    {
        $payload = $this->payload();
        $payload['variants'][0]['sku'] = ['invalid'];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_manage_without_cost_permission_cannot_clear_existing_cost_with_null(): void
    {
        $id = $this->create();
        $variant = ProductVariant::firstOrFail();
        $role = Role::create(['code' => 'catalog-editor', 'label' => 'Editor sin costos', 'permissions' => ['catalog.view', 'catalog.manage']]);
        $editor = User::factory()->create();
        $editor->roles()->attach($role->id);
        $this->actingAs($editor)->patchJson("/api/v1/business/products/$id/variants/$variant->id", ['sku' => $variant->sku, 'sale_price' => '1250.50', 'cost_price' => null, 'version' => 0, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('cost_price');
        $this->assertSame('700.0000', $variant->fresh()->cost_price);
        $this->getJson("/api/v1/business/products/$id")->assertJsonMissingPath('data.variants.data.0.cost_price');
    }

    public function test_brand_and_attribute_value_management_preserves_reference_identity(): void
    {
        $brand = $this->postJson('/api/v1/business/catalog/brands', ['name' => 'Marca QA', 'is_active' => true])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/business/catalog/brands/$brand", ['name' => 'Marca QA', 'is_active' => false])->assertOk();
        $payload = [...$this->payload(), 'brand_id' => $brand];
        $this->postJson('/api/v1/business/products', $payload)->assertUnprocessable();
        $attribute = $this->postJson('/api/v1/business/catalog/attributes', ['name' => 'Color', 'is_active' => true])->assertCreated()->json('data.id');
        $value = $this->postJson('/api/v1/business/catalog/values', ['value' => 'Negro', 'attribute_id' => $attribute, 'is_active' => true])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/business/catalog/values/$value", ['value' => 'Negro', 'attribute_id' => null, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('attribute_id');
        $this->patchJson("/api/v1/business/catalog/values/$value", ['value' => 'Negro intenso', 'is_active' => true])->assertOk();
        $this->assertDatabaseHas('attribute_values', ['id' => $value, 'attribute_id' => $attribute, 'value' => 'Negro intenso']);
    }

    public function test_price_edit_preserves_omitted_barcode_and_cost(): void
    {
        $payload = $this->payload();
        $payload['variants'][0]['barcode'] = 'B001';
        $id = $this->postJson('/api/v1/business/products', $payload)->assertCreated()->json('data.id');
        $variant = ProductVariant::firstOrFail();
        $this->patchJson("/api/v1/business/products/$id/variants/$variant->id", ['sku' => $variant->sku, 'sale_price' => '1300', 'is_active' => true, 'version' => 0])->assertOk();
        $this->assertSame('B001', $variant->fresh()->barcode);
        $this->assertSame('700.0000', $variant->fresh()->cost_price);
    }
}
