<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BasketBoardTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected int $store;

    protected int $warehouse;

    protected Customer $customer;

    protected int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'KeylaShop QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create(['phone' => '+1 202-555-0101']);
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Bolso', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'BOL-QA', 'sale_price' => '25', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Entrada QA'])->assertCreated();
    }

    protected function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    protected function basket(): int
    {
        return $this->postJson($this->root().'/baskets', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse])->assertCreated()->json('data.order.id');
    }

    protected function edit(int $id, int $version, string $quantity, array $extra = [])
    {
        $data = ['version' => $version, 'lines' => $quantity === '0' ? [] : [['variant_id' => $this->variant, 'quantity' => $quantity]], 'shipping' => '0', 'discount' => '0', ...$extra];
        $preview = $this->postJson($this->root()."/baskets/$id/preview", $data)->assertOk()->json('data');

        return $this->patchJson($this->root()."/baskets/$id", [...$data, 'quote_hash' => $preview['quote_hash'], 'request_key' => (string) Str::uuid()]);
    }

    public function test_saved_edits_adjust_only_reservation_delta_and_preserve_deposits_prices(): void
    {
        $id = $this->basket();
        $this->edit($id, 0, '2')->assertOk()->assertJsonPath('data.order.grand_total', '50.0000');
        $this->postJson($this->root()."/sales/orders/$id/payment", ['version' => 1, 'request_key' => (string) Str::uuid(), 'amount' => '20', 'payment_method' => 'cash'])->assertOk();
        ProductVariant::whereKey($this->variant)->update(['sale_price' => '99']);
        $this->edit($id, 2, '3', ['shipping' => '5', 'discount' => '5'])->assertOk()->assertJsonPath('data.order.grand_total', '75.0000')->assertJsonPath('data.order.pending_total', '55.0000');
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant, 'on_hand' => '10', 'reserved' => '3']);
        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'unit_price' => '25']);
        $this->assertDatabaseCount('payments', 1);
        $this->edit($id, 3, '1')->assertOk();
        $this->edit($id, 4, '3')->assertOk();
        $r = DB::table('stock_reservations')->first();
        $this->assertSame('3.0000', bcsub((string) $r->quantity, (string) $r->released_quantity, 4));
    }

    public function test_overstock_and_below_deposit_are_atomic_and_stale_versions_fail(): void
    {
        $id = $this->basket();
        $this->edit($id, 0, '2')->assertOk();
        $this->postJson($this->root()."/sales/orders/$id/payment", ['version' => 1, 'request_key' => (string) Str::uuid(), 'amount' => '40', 'payment_method' => 'cash'])->assertOk();
        $this->postJson($this->root()."/baskets/$id/preview", ['version' => 2, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'])->assertUnprocessable();
        $this->edit($id, 2, '11')->assertConflict();
        $this->postJson($this->root()."/baskets/$id/preview", ['version' => 0, 'lines' => [], 'shipping' => '0', 'discount' => '0'])->assertConflict();
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant, 'reserved' => '2']);
    }

    public function test_grid_filters_numbers_and_close_are_independent_from_payment_dispatch(): void
    {
        $id = $this->basket();
        $other = $this->basket();
        $this->postJson($this->root()."/baskets/$id/number", ['version' => 0, 'request_key' => (string) Str::uuid(), 'number' => '12'])->assertOk()->assertJsonPath('data.order.id', $id)->assertJsonPath('data.order.basket_number', '12');
        $this->postJson($this->root()."/baskets/$other/number", ['version' => 0, 'request_key' => (string) Str::uuid(), 'number' => '12'])->assertConflict();
        $this->edit($id, 1, '1')->assertOk();
        $this->getJson($this->root().'/baskets?filter=without_deposit&q=12')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->root()."/sales/orders/$id/invoice", ['version' => 2, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->getJson($this->root().'/baskets?filter=invoice_sent')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->root()."/baskets/$id/dispatch", ['version' => 3, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($this->root()."/sales/orders/$id/deliver", ['version' => 3, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->postJson($this->root()."/baskets/$id/dispatch", ['version' => 4, 'request_key' => (string) Str::uuid()])->assertOk();
        $this->getJson($this->root().'/baskets?filter=shipped')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->root().'/baskets?filter=paid')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('invoices', 1);
    }
}
