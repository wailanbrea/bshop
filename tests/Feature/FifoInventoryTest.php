<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FifoInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'KeylaShop FIFO', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::firstOrFail()->id;
        $this->warehouse = Warehouse::firstOrFail()->id;
        $product = app(ProductService::class)->create([
            'name' => 'Tenis Blanco',
            'product_type' => 'physical',
            'unit_code' => 'unit',
            'track_stock' => true,
            'status' => 'active',
            'variants' => [[
                'sku' => 'TENIS-38',
                'sale_price' => '50.0000',
                'cost_price' => '20.0000',
                'is_active' => true,
                'attribute_value_ids' => [],
            ]],
        ], $this->owner);
        $this->variant = $product->variants()->firstOrFail()->id;
    }

    public function test_fifo_lots_track_multi_price_receipts_sales_cogs_and_immutability(): void
    {
        $this->actingAs($this->owner);

        // Lot 1: 2 pairs at $20.00
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variant,
            'operation' => 'receipt',
            'quantity' => '2',
            'unit_cost' => '20.00',
            'version' => 0,
            'note' => 'Compra lote 1',
        ])->assertCreated();

        // Lot 2: 3 pairs at $25.00
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variant,
            'operation' => 'receipt',
            'quantity' => '3',
            'unit_cost' => '25.00',
            'version' => 1,
            'note' => 'Compra lote 2',
        ])->assertCreated();

        // Inventory valuation must be (2*20 + 3*25) = 115.0000 with 2 active lots
        $stock = $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/stock")
            ->assertOk()
            ->json();
        $this->assertSame('115.0000', $stock['meta']['valuation']['invested_cost']);
        $this->assertSame('115.0000', $stock['data'][0]['invested_total']);
        $this->assertSame(2, $stock['data'][0]['lots_count']);

        $lots = $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/variants/{$this->variant}/lots")
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $lots);
        $this->assertSame('20.0000', $lots[0]['unit_cost']);
        $this->assertSame('2.0000', $lots[0]['remaining_quantity']);
        $this->assertSame('25.0000', $lots[1]['unit_cost']);
        $this->assertSame('3.0000', $lots[1]['remaining_quantity']);

        // Sell 3 pairs at $50.00 => total $150.00; FIFO COGS = 2*20 + 1*25 = $65.00; profit = $85.00
        $customer = Customer::create(['full_name' => 'Cliente FIFO', 'phone' => '8095550101', 'status' => 'active']);
        $quote = $this->postJson("/api/v1/business/stores/{$this->store}/sales/quote", [
            'warehouse_id' => $this->warehouse,
            'lines' => [['variant_id' => $this->variant, 'quantity' => '3']],
            'discount' => '0',
            'shipping' => '0',
        ])->assertOk()->json('data');

        $this->postJson("/api/v1/business/stores/{$this->store}/sales/orders", [
            'request_key' => (string) Str::uuid(),
            'mode' => 'sale',
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse,
            'lines' => [['variant_id' => $this->variant, 'quantity' => '3']],
            'discount' => '0',
            'shipping' => '0',
            'quote_hash' => $quote['quote_hash'],
            'initial_payment' => '150.00',
            'payment_method' => 'cash',
        ])->assertCreated();

        // Remaining stock: 2 pairs in Lot 2 at $25.00 => invested $50.00
        $afterSaleStock = $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/stock")
            ->assertOk()
            ->json();
        $this->assertSame('50.0000', $afterSaleStock['meta']['valuation']['invested_cost']);
        $this->assertSame(1, $afterSaleStock['data'][0]['lots_count']);

        // SalesControl must report COGS = 65.0000 and gross_profit = 85.0000
        $control1 = $this->getJson("/api/v1/business/stores/{$this->store}/sales/control")
            ->assertOk()
            ->json('summary.0');
        $this->assertSame('65.0000', $control1['cost_of_goods']);
        $this->assertSame('85.0000', $control1['gross_profit']);

        // Buying a 3rd lot at $40.00 must NOT alter historical COGS or gross profit
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variant,
            'operation' => 'receipt',
            'quantity' => '4',
            'unit_cost' => '40.00',
            'version' => 3,
            'note' => 'Compra lote 3 mas caro',
        ])->assertCreated();

        $control2 = $this->getJson("/api/v1/business/stores/{$this->store}/sales/control")
            ->assertOk()
            ->json('summary.0');
        $this->assertSame('65.0000', $control2['cost_of_goods']);
        $this->assertSame('85.0000', $control2['gross_profit']);
    }

    public function test_fifo_transfer_preserves_lot_costs_and_blocks_lots_without_cost_permission(): void
    {
        $this->actingAs($this->owner);
        $secondWarehouse = Warehouse::create([
            'store_id' => $this->store,
            'code' => 'WH-2',
            'name' => 'Almacen Norte',
            'is_default' => false,
            'is_active' => true,
        ]);

        // Receive 1 at $18 and 2 at $22
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variant,
            'operation' => 'receipt',
            'quantity' => '1',
            'unit_cost' => '18.00',
            'version' => 0,
            'note' => 'Lote A',
        ])->assertCreated();
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'variant_id' => $this->variant,
            'operation' => 'receipt',
            'quantity' => '2',
            'unit_cost' => '22.00',
            'version' => 1,
            'note' => 'Lote B',
        ])->assertCreated();

        // Transfer 2 units to WH-2 => moves 1@$18 + 1@$22 = $40 in WH-2, leaves 1@$22 in WH-1
        $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [
            'request_key' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse,
            'destination_warehouse_id' => $secondWarehouse->id,
            'variant_id' => $this->variant,
            'operation' => 'transfer',
            'quantity' => '2',
            'version' => 2,
            'note' => 'Traslado FIFO',
        ])->assertCreated();

        $wh2Lots = $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$secondWarehouse->id}/variants/{$this->variant}/lots")
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $wh2Lots);
        $this->assertSame('18.0000', $wh2Lots[0]['unit_cost']);
        $this->assertSame('22.0000', $wh2Lots[1]['unit_cost']);

        // Seller without catalog.costs gets 403 on lots endpoint
        $seller = User::factory()->create();
        $sellerRole = Role::where('code', 'seller')->firstOrFail();
        $seller->roles()->attach($sellerRole->id);
        DB::table('store_user')->insert(['store_id' => $this->store, 'user_id' => $seller->id]);

        $this->actingAs($seller)
            ->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$secondWarehouse->id}/variants/{$this->variant}/lots")
            ->assertForbidden();
    }
}
