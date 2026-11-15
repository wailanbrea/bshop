<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\CsvCatalogReader;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $csv = "name,sku,sale_price\nJabón QA,IMP-A,125.50\nPerfume QA,IMP-B,500.0001\n";

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

    private function preview(?string $csv = null)
    {
        return $this->post('/api/v1/business/catalog/imports/preview', ['file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv ?? $this->csv)], ['Accept' => 'application/json']);
    }

    private function url(string $id, string $action = ''): string
    {
        return '/api/v1/business/catalog/imports/'.$id.($action !== '' ? '/'.$action : '');
    }

    public function test_preview_is_dry_run_and_payload_is_encrypted_at_rest(): void
    {
        $response = $this->preview()->assertCreated()->assertJsonPath('data.row_count', 2)->assertJsonPath('data.product_count', 2)->assertJsonPath('data.error_count', 0)->assertJsonPath('data.can_confirm', true);
        $id = $response->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'catalog_imports', 'entity_id' => $id, 'event' => 'catalog.import.previewed']);
        $this->assertDatabaseCount('products', 0);
        $raw = DB::table('catalog_imports')->where('id', $id)->value('payload');
        $this->assertStringNotContainsString('Jabón QA', $raw);
        $this->assertStringNotContainsString('125.50', $raw);
        $this->getJson($this->url($id))->assertOk()->assertJsonCount(2, 'data.rows');
        $this->getJson($this->url($id))->assertOk();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_confirm_is_atomic_idempotent_and_does_not_accept_replaced_payload(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $this->postJson($this->url($id, 'confirm'), ['products' => [['name' => 'Attack']]])->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
        $first = $this->postJson($this->url($id, 'confirm'))->assertOk()->assertJsonPath('data.status', 'committed')->json('data.products');
        $this->postJson($this->url($id, 'confirm'))->assertOk()->assertJsonPath('data.products', $first);
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertSame('500.0001', ProductVariant::where('sku', 'IMP-B')->firstOrFail()->sale_price);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'catalog.import.committed')->count());
    }

    public function test_duplicates_and_invalid_prices_are_reported_for_each_source_row(): void
    {
        $id = $this->preview("name,sku,sale_price\nUno,same,-1\nDos,SAME,12.34567\n")->assertCreated()->assertJsonPath('data.error_count', 2)->assertJsonPath('data.can_confirm', false)->json('data.id');
        $rows = $this->getJson($this->url($id))->json('data.rows');
        $this->assertSame([2, 3], array_column($rows, 'row'));
        $this->assertNotEmpty($rows[0]['errors']);
        $this->assertNotEmpty($rows[1]['errors']);
        $this->postJson($this->url($id, 'confirm'))->assertUnprocessable();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_utf8_bom_semicolon_quotes_multiline_and_decimal_comma(): void
    {
        $csv = "\xEF\xBB\xBFname;sku;sale_price;description\r\n\"Jabón, azul\";MULTI;125,50;\"Línea uno\nLínea \"\"dos\"\"\"\r\nServicio;SECOND;500;Sin detalle\r\n";
        $id = $this->preview($csv)->assertCreated()->assertJsonPath('data.error_count', 0)->json('data.id');
        $this->getJson($this->url($id))->assertJsonPath('data.rows.0.row', 2)->assertJsonPath('data.rows.1.row', 4);
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        $this->assertSame('125.5000', ProductVariant::where('sku', 'MULTI')->firstOrFail()->sale_price);
        $this->assertSame("Línea uno\nLínea \"dos\"", Product::where('name', 'Jabón, azul')->firstOrFail()->description);
    }

    public function test_grouped_rows_create_one_product_with_canonical_variants(): void
    {
        $attribute = ProductAttribute::create(['name' => 'Talla', 'is_active' => true]);
        $m = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'M', 'is_active' => true]);
        $l = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'L', 'is_active' => true]);
        $id = $this->preview("product_key,name,sku,sale_price,attribute_value_ids\nshirt,Camiseta,M,100,$m->id\nshirt,Camiseta,L,100,$l->id\n")->assertCreated()->assertJsonPath('data.product_count', 1)->assertJsonPath('data.error_count', 0)->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('product_variants', 2);
        $this->assertDatabaseCount('variant_attribute_values', 2);
    }

    public function test_group_metadata_mismatch_and_repeated_default_combination_are_rejected(): void
    {
        $this->preview("product_key,name,sku,sale_price\ng,One,A,100\ng,Two,B,100\n")->assertCreated()->assertJsonPath('data.error_count', 2);
        $this->preview("product_key,name,sku,sale_price\ng,One,A,100\ng,One,B,100\n")->assertCreated()->assertJsonPath('data.error_count', 2);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_confirm_revalidates_catalog_changes_without_importing_the_other_rows(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        ProductVariant::factory()->create(['sku' => 'IMP-B']);
        $this->postJson($this->url($id, 'confirm'))->assertUnprocessable()->assertJsonPath('data.error_count', 1)->assertJsonPath('data.status', 'preview');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseMissing('products', ['name' => 'Jabón QA']);
    }

    public function test_unexpected_failure_after_the_first_product_rolls_back_the_whole_lot(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $event = 'eloquent.created: '.Product::class;
        Event::listen($event, function (Product $product) {
            if ($product->name === 'Perfume QA') {
                throw new \RuntimeException('Synthetic database workflow failure');
            }
        });
        try {
            $this->postJson($this->url($id, 'confirm'))->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_variants', 0);
        $this->assertDatabaseHas('catalog_imports', ['id' => $id, 'status' => 'preview']);
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'catalog.product.created')->count());
        $this->postJson($this->url($id, 'confirm'))->assertOk();
    }

    public function test_expired_preview_and_other_users_cannot_confirm_it(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('code', 'owner')->value('id'));
        $this->actingAs($other)->getJson($this->url($id))->assertNotFound();
        $this->postJson($this->url($id, 'confirm'))->assertNotFound();
        $this->getJson('/api/v1/business/catalog/imports')->assertJsonCount(0, 'data');
        $this->actingAs($this->owner);
        $this->travel(25)->hours();
        $this->getJson($this->url($id))->assertJsonPath('data.is_expired', true);
        $this->postJson($this->url($id, 'confirm'))->assertGone();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_cost_permission_is_rechecked_and_seller_cannot_use_import_routes(): void
    {
        $id = $this->preview("name,sku,sale_price,cost_price\nItem,COST,100,20\n")->assertCreated()->json('data.id');
        $role = Role::where('code', 'owner')->firstOrFail();
        $role->update(['permissions' => array_values(array_diff($role->permissions, ['catalog.costs']))]);
        $this->actingAs($this->owner->fresh())->getJson($this->url($id))->assertForbidden();
        $this->postJson($this->url($id, 'confirm'))->assertForbidden();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        $this->actingAs($seller)->getJson('/api/v1/business/catalog/imports/template')->assertForbidden();
    }

    public function test_editor_without_cost_permission_gets_row_errors_without_cost_values(): void
    {
        $role = Role::create(['code' => 'import-editor', 'label' => 'Editor', 'permissions' => ['catalog.view', 'catalog.manage']]);
        $editor = User::factory()->create();
        $editor->roles()->attach($role->id);
        $this->actingAs($editor);
        $this->preview("name,sku,sale_price,cost_price\nItem,COST,100,20\n")->assertCreated()->assertJsonPath('data.error_count', 1)->assertJsonMissingPath('data.rows.0.cost_price');
        $this->preview("name,sku,sale_price,cost_price\nItem,NO-COST,100,\n")->assertCreated()->assertJsonPath('data.error_count', 0);
    }

    public function test_revert_archives_only_this_lot_and_is_idempotent(): void
    {
        $existing = Product::factory()->create(['name' => 'Existing']);
        $id = $this->preview()->assertCreated()->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        $this->postJson($this->url($id, 'revert'))->assertOk()->assertJsonPath('data.status', 'reverted');
        $this->postJson($this->url($id, 'revert'))->assertOk();
        $this->assertDatabaseCount('products', 3);
        $this->assertSame('active', $existing->fresh()->status);
        $this->assertSame(2, Product::where('status', 'archived')->count());
        $this->postJson($this->url($id, 'confirm'))->assertConflict();
    }

    public function test_revert_refuses_any_edited_product_without_archiving_the_others(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        Product::where('name', 'Perfume QA')->update(['name' => 'Edited outside import']);
        $this->postJson($this->url($id, 'revert'))->assertConflict();
        $this->assertSame(0, Product::where('status', 'archived')->count());
    }

    public function test_revert_refuses_stock_usage_without_touching_any_product(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        DB::table('stock_balances')->insert(['warehouse_id' => DB::table('warehouses')->value('id'), 'variant_id' => ProductVariant::firstOrFail()->id, 'on_hand' => '1.0000']);
        $this->postJson($this->url($id, 'revert'))->assertConflict();
        $this->assertSame(0, Product::where('status', 'archived')->count());
    }

    public function test_structural_errors_limits_and_unknown_columns_never_create_preview_or_products(): void
    {
        foreach (["name,sku,qty\nA,A,1", "name,sku,sale_price\n\"Unclosed,A,1", "name,sku,sale_price\nBad\0,A,1"] as $csv) {
            $this->preview($csv)->assertUnprocessable();
        }
        $large = "name,sku,sale_price\n".implode('', array_map(fn ($i) => "Item $i,SKU-$i,1\n", range(1, 501)));
        $this->preview($large)->assertUnprocessable();
        $this->assertDatabaseCount('catalog_imports', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_errors_csv_neutralizes_spreadsheet_formulas(): void
    {
        $id = $this->preview("name,sku,sale_price\nItem,\"=SUM(1,1)\",-1\n")->assertCreated()->json('data.id');
        $content = $this->get($this->url($id, 'errors'))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=SUM(1,1)", $content);
        $this->get('/api/v1/business/catalog/imports/template')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_template_round_trip_imports_physical_and_service_without_stock(): void
    {
        $template = $this->get('/api/v1/business/catalog/imports/template')->assertOk()->streamedContent();
        $id = $this->preview($template)->assertCreated()->assertJsonPath('data.error_count', 0)->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        $this->assertFalse(Product::where('product_type', 'service')->firstOrFail()->track_stock);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_preview_pagination_keeps_physical_row_numbers(): void
    {
        $csv = "name,sku,sale_price\n".implode('', array_map(fn ($i) => "Item $i,SKU-$i,1\n", range(1, 30)));
        $id = $this->preview($csv)->assertCreated()->assertJsonCount(25, 'data.rows')->assertJsonPath('data.pagination.last_page', 2)->json('data.id');
        $this->getJson($this->url($id).'?page=2')->assertOk()->assertJsonCount(5, 'data.rows')->assertJsonPath('data.rows.0.row', 27);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_revalidation_uses_the_current_reference_status(): void
    {
        $category = Category::create(['name' => 'Inactiva', 'slug' => 'inactive', 'is_active' => false]);
        $id = $this->preview("name,sku,sale_price,category_id\nItem,REF,1,$category->id\n")->assertCreated()->assertJsonPath('data.error_count', 1)->json('data.id');
        $category->update(['is_active' => true]);
        $this->postJson($this->url($id, 'revalidate'))->assertOk()->assertJsonPath('data.error_count', 0)->assertJsonPath('data.can_confirm', true);
        $this->postJson($this->url($id, 'confirm'))->assertOk();
    }

    public function test_upload_size_and_rate_limit_are_enforced(): void
    {
        $this->post('/api/v1/business/catalog/imports/preview', ['file' => UploadedFile::fake()->create('large.csv', 2049, 'text/csv')], ['Accept' => 'application/json'])->assertUnprocessable();
        for ($i = 0; $i < 4; $i++) {
            $this->preview()->assertCreated();
        }
        $this->preview()->assertTooManyRequests();
        $this->assertDatabaseCount('catalog_imports', 4);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_preview_and_audit_are_atomic_even_if_the_audit_write_fails(): void
    {
        $event = 'eloquent.creating: '.AuditLog::class;
        Event::listen($event, function (AuditLog $log) {
            if ($log->event === 'catalog.import.previewed') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->preview()->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertDatabaseCount('catalog_imports', 0);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_audit_rollback_refuses_to_truncate_opaque_entity_ids(): void
    {
        $id = $this->preview()->assertCreated()->json('data.id');
        $migration = require database_path('migrations/2026_11_12_001200_allow_opaque_audit_entity_ids.php');
        try {
            $migration->down();
            $this->fail('Lossy audit narrowing was allowed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Refusing a lossy rollback', $e->getMessage());
        }
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'entity_type' => 'catalog_imports']);
    }

    public function test_literal_zero_is_not_silently_replaced_with_a_default(): void
    {
        $this->preview("name,sku,sale_price,product_type,status\nInvalid,ZERO-BAD,0,0,0\n")->assertCreated()->assertJsonPath('data.error_count', 1);
        $id = $this->preview("name,sku,sale_price,description\nZero,ZERO-GOOD,0,0\n")->assertCreated()->assertJsonPath('data.error_count', 0)->json('data.id');
        $this->postJson($this->url($id, 'confirm'))->assertOk();
        $this->assertSame('0', Product::where('name', 'Zero')->firstOrFail()->description);
        $this->assertSame('0.0000', ProductVariant::where('sku', 'ZERO-GOOD')->firstOrFail()->sale_price);
    }

    public function test_pathological_cell_and_column_counts_are_rejected_without_allocating_a_huge_preview(): void
    {
        foreach (["name,sku,sale_price\n".str_repeat('A', 65537).',SKU,1', str_repeat('name,', 70)."sku,sale_price\nA,SKU,1"] as $csv) {
            try {
                app(CsvCatalogReader::class)->read($csv);
                $this->fail('Pathological CSV accepted.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('file', $e->errors());
            }
        }
        $this->assertDatabaseCount('catalog_imports', 0);
    }
}
