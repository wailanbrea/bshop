<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductBuyersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $productId;

    private int $variantId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, [
            'name' => 'KeylaShop QA',
            'default_currency' => 'USD',
            'timezone' => 'UTC',
            'store_name' => 'Principal',
        ]);
        $this->store = Store::firstOrFail()->id;
        $this->warehouse = Warehouse::firstOrFail()->id;
        $this->actingAs($this->owner);

        $context = app(StoreContext::class);
        $context->useStore(Store::findOrFail($this->store));
        $product = app(ProductService::class)->create([
            'name' => 'Vestido Satinado Rosa',
            'product_type' => 'physical',
            'track_stock' => true,
            'unit_code' => 'unit',
            'status' => 'active',
            'variants' => [['sku' => 'VES-ROSA-M', 'sale_price' => '75', 'attribute_value_ids' => []]],
        ], $this->owner);
        $variant = $product->variants()->firstOrFail();
        $this->productId = $product->id;
        $this->variantId = $variant->id;

        app(InventoryService::class)->move($context, [
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variantId,
            'operation' => 'receipt',
            'quantity' => '15',
            'version' => 0,
            'request_key' => (string) Str::uuid(),
            'note' => 'Stock QA',
        ], $this->owner);
        $context->clear();
    }

    private function root(): string
    {
        return "/api/v1/business/stores/{$this->store}";
    }

    public function test_can_search_products_and_list_buyers_with_totals_and_scope_filter(): void
    {
        $c1 = $this->postJson($this->root().'/customers', [
            'request_key' => (string) Str::uuid(),
            'full_name' => 'Ana Gómez',
            'phone' => '+1 809-555-0111',
        ])->assertCreated()->json('data.customer.id');

        $c2 = $this->postJson($this->root().'/customers', [
            'request_key' => (string) Str::uuid(),
            'full_name' => 'Sofía Pérez',
            'phone' => '+1 809-555-0222',
        ])->assertCreated()->json('data.customer.id');

        // Ana buys 2 units (direct sale -> invoiced)
        $base1 = [
            'warehouse_id' => $this->warehouse,
            'customer_id' => $c1,
            'lines' => [['variant_id' => $this->variantId, 'quantity' => '2']],
            'discount' => '0',
            'shipping' => '0',
        ];
        $q1 = $this->postJson($this->root().'/sales/quote', $base1)->assertOk()->json('data.quote_hash');
        $this->postJson($this->root().'/sales/orders', [
            ...$base1,
            'request_key' => (string) Str::uuid(),
            'quote_hash' => $q1,
            'mode' => 'sale',
            'initial_payment' => '150',
            'payment_method' => 'zelle',
        ])->assertCreated();

        // Sofía reserves 1 unit (open apartado -> not invoiced)
        $base2 = [
            'warehouse_id' => $this->warehouse,
            'customer_id' => $c2,
            'lines' => [['variant_id' => $this->variantId, 'quantity' => '1']],
            'discount' => '0',
            'shipping' => '0',
        ];
        $q2 = $this->postJson($this->root().'/sales/quote', $base2)->assertOk()->json('data.quote_hash');
        $this->postJson($this->root().'/sales/orders', [
            ...$base2,
            'request_key' => (string) Str::uuid(),
            'quote_hash' => $q2,
            'mode' => 'reservation',
            'initial_payment' => '20',
            'payment_method' => 'cash',
        ])->assertCreated();

        // 1. Search products by name/SKU
        $search = $this->getJson($this->root().'/products/buyers/search?q=VES-ROSA');
        $search->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->productId)
            ->assertJsonPath('data.0.buyers_count', 2)
            ->assertJsonPath('data.0.units_sold', '3.0000');

        // 2. All buyers
        $all = $this->getJson($this->root()."/products/{$this->productId}/buyers");
        $all->assertOk()
            ->assertJsonPath('data.summary.unique_customers', 2)
            ->assertJsonPath('data.summary.total_units', '3.0000')
            ->assertJsonPath('data.summary.total_revenue', '225.0000');

        // 3. Scope = invoiced only (Ana)
        $invoiced = $this->getJson($this->root()."/products/{$this->productId}/buyers?scope=invoiced");
        $invoiced->assertOk()
            ->assertJsonPath('data.summary.unique_customers', 1)
            ->assertJsonPath('data.buyers.0.full_name', 'Ana Gómez');

        // 4. Scope = open only (Sofía)
        $open = $this->getJson($this->root()."/products/{$this->productId}/buyers?scope=open");
        $open->assertOk()
            ->assertJsonPath('data.summary.unique_customers', 1)
            ->assertJsonPath('data.buyers.0.full_name', 'Sofía Pérez');
    }
}
