<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesReturnReceiptsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $customer;

    private array $variants = [];

    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Return receipt QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create()->id;
        $this->actingAs($this->owner);
        foreach (['10', '20'] as $index => $price) {
            $product = app(ProductService::class)->create(['name' => 'Return bag '.$index, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'RETURN-'.$index, 'sale_price' => $price, 'attribute_value_ids' => []]]], $this->owner);
            $variant = $product->variants()->first()->id;
            $this->variants[] = $variant;
            $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Return fixture'])->assertCreated();
        }
        $this->orders[] = $this->createOrder('sale', 0, '3');
        $this->orders[] = $this->createOrder('sale', 1, '1');
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function endpoint(?int $order = null): string
    {
        return $this->root().'/sales/orders/'.($order ?? $this->orders[0]).'/returns';
    }

    private function createOrder(string $mode, int $variant, string $quantity): int
    {
        $data = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variants[$variant], 'quantity' => $quantity]], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');

        return $this->postJson($this->root().'/sales/orders', [...$data, 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash, 'mode' => $mode, 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
    }

    private function review(?int $order = null): array
    {
        return $this->getJson($this->endpoint($order))->assertOk()->json('data');
    }

    private function receive(array $review, string $quantity = '1', bool $restock = true, ?string $key = null)
    {
        return $this->postJson($this->endpoint($review['order_id']), ['request_key' => $key ?? (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'reason' => 'Mercancía recibida del cliente', 'lines' => [['invoice_item_id' => $review['lines'][0]['invoice_item_id'], 'quantity' => $quantity, 'restock' => $restock]]]);
    }

    private function undoPayload(int $receipt): array
    {
        $r = $this->getJson($this->endpoint()."/$receipt/reversal")->assertOk()->json('data');

        return ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Corregir recepción física registrada por error'];
    }

    public function test_receipt_compensation_preserves_original_items_money_and_pdf_and_reopens_quantity(): void
    {
        $r = $this->review();
        $doc = DB::table('invoice_documents')->first();
        $bytes = Storage::disk('private_invoices')->get($doc->object_key);
        $receipt = $this->receive($r)->assertCreated()->json('data.sales_return_id');
        $items = DB::table('sales_return_items')->get()->toJson();
        $invoices = DB::table('invoices')->get()->toJson();
        $body = $this->undoPayload($receipt);
        $path = $this->endpoint()."/$receipt/reversal";
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.order.return_pending', false);
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.replay', true);
        $this->postJson($path, $this->undoPayload($receipt))->assertConflict();
        $this->assertSame($items, DB::table('sales_return_items')->get()->toJson());
        $this->assertSame($invoices, DB::table('invoices')->get()->toJson());
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($doc->object_key));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('return_adjustments', 0);
        $this->assertDatabaseHas('sales_returns', ['id' => $receipt, 'status' => 'void']);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'warehouse_id' => $this->warehouse, 'on_hand' => '7', 'reserved' => '0']);
        $this->assertDatabaseHas('inventory_movements', ['movement_type' => 'customer_return_reversal', 'on_hand_delta' => '-1', 'reserved_delta' => '0']);
        $this->assertDatabaseCount('return_receipt_reversals', 1);
        $this->getJson($path.'/requests/'.$body['request_key'])->assertOk()->assertJsonPath('data.replay', true);
        $current = $this->review();
        $this->assertSame('0.0000', $current['lines'][0]['received_quantity']);
        $this->assertSame('3.0000', $current['lines'][0]['available_quantity']);
        $this->receive($current)->assertCreated();
        $this->assertSame(0, app(InventoryReconciliation::class)->inspect($this->store, $this->warehouse)['summary']['differences']);
    }

    public function test_receipt_without_restock_can_be_voided_without_an_inventory_movement(): void
    {
        $receipt = $this->receive($this->review(), restock: false)->assertCreated()->json('data.sales_return_id');
        $before = DB::table('inventory_movements')->get()->toJson();
        $this->postJson($this->endpoint()."/$receipt/reversal", $this->undoPayload($receipt))->assertOk();
        $this->assertSame($before, DB::table('inventory_movements')->get()->toJson());
        $this->assertDatabaseHas('return_receipt_reversal_items', ['original_movement_id' => null, 'inverse_movement_id' => null]);
        $this->assertSame('3.0000', $this->review()['lines'][0]['available_quantity']);
    }

    public function test_economic_adjustment_blocks_physical_receipt_cancellation(): void
    {
        $receipt = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $path = $this->endpoint()."/$receipt";
        $r = $this->getJson($path.'/financial')->json('data.proposal');
        $this->postJson($path.'/adjust', ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'shipping_credit' => '0', 'reason' => 'Ajuste económico ya aprobado'])->assertOk();
        $this->getJson($path.'/reversal')->assertOk()->assertJsonPath('data.economic_history', true)->assertJsonPath('data.allowed', false);
        $this->postJson($path.'/reversal', $this->undoPayload($receipt))->assertConflict();
        $this->assertDatabaseCount('return_receipt_reversals', 0);
    }

    public function test_consumed_stock_and_changed_stock_review_block_the_compensation(): void
    {
        $receipt = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $payload = $this->undoPayload($receipt);
        $balance = DB::table('stock_balances')->where('variant_id', $this->variants[0])->first();
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variants[0], 'operation' => 'receipt', 'quantity' => '1', 'version' => $balance->version, 'note' => 'Stock cambió después de revisar'])->assertCreated();
        $this->postJson($this->endpoint()."/$receipt/reversal", $payload)->assertConflict();
        $balance = DB::table('stock_balances')->where('variant_id', $this->variants[0])->first();
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variants[0], 'operation' => 'issue', 'quantity' => (string) $balance->on_hand, 'version' => $balance->version, 'note' => 'Unidades usadas fuera de esta recepción'])->assertCreated();
        $this->getJson($this->endpoint()."/$receipt/reversal")->assertOk()->assertJsonPath('data.stock_available', false);
        $this->postJson($this->endpoint()."/$receipt/reversal", $this->undoPayload($receipt))->assertConflict();
        $this->assertDatabaseCount('return_receipt_reversals', 0);
    }

    public function test_cancelling_one_of_two_receipts_keeps_the_other_collection_hold(): void
    {
        $a = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $b = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $this->postJson($this->endpoint()."/$a/reversal", $this->undoPayload($a))->assertOk()->assertJsonPath('data.order.return_pending', true);
        $this->postJson($this->endpoint()."/$b/reversal", $this->undoPayload($b))->assertOk()->assertJsonPath('data.order.return_pending', false);
    }

    public function test_receipt_compensation_protects_scope_permission_and_recovery_actor(): void
    {
        $receipt = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $payload = $this->undoPayload($receipt);
        $path = $this->endpoint()."/$receipt/reversal";
        $this->getJson($this->endpoint($this->orders[1])."/$receipt/reversal")->assertNotFound();
        $this->postJson($path, $payload)->assertOk();
        $reader = User::factory()->create();
        $role = Role::create(['code' => 'receipt_observer', 'label' => 'Reader', 'permissions' => ['sales.view', 'stores.view']]);
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $reader->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($path)->assertOk();
        $this->postJson($path, $payload)->assertForbidden();
        $this->getJson($path.'/requests/'.$payload['request_key'])->assertNotFound();
    }

    public function test_receipt_compensation_requires_csrf_without_the_test_bypass(): void
    {
        $receipt = $this->receive($this->review())->assertCreated()->json('data.sales_return_id');
        $payload = $this->undoPayload($receipt);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->endpoint()."/$receipt/reversal", $payload)->assertStatus(419);
        $this->assertDatabaseCount('return_receipt_reversals', 0);
    }

    public function test_receipt_restocks_once_and_preserves_fiscal_and_financial_history(): void
    {
        $review = $this->review();
        $this->assertSame('3.0000', $review['lines'][0]['delivered_quantity']);
        $this->assertSame('3.0000', $review['lines'][0]['available_quantity']);
        $invoice = DB::table('invoices')->find($review['invoice']['id']);
        $document = DB::table('invoice_documents')->where('invoice_id', $invoice->id)->first();
        $bytes = Storage::disk('private_invoices')->get($document->object_key);
        $key = (string) Str::uuid();
        $this->receive($review, key: $key)->assertCreated()->assertJsonPath('data.order.return_pending', true)->assertJsonPath('data.economic_status', 'pending_adjustment');
        $this->receive($review, key: strtoupper($key))->assertOk()->assertJsonPath('data.replay', true);
        $this->receive($review)->assertConflict();
        $this->assertDatabaseCount('sales_returns', 1);
        $this->assertDatabaseCount('sales_return_items', 1);
        $this->assertDatabaseCount('sales_return_receipts', 1);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $this->warehouse, 'variant_id' => $this->variants[0], 'on_hand' => '8', 'reserved' => '0']);
        $item = DB::table('sales_return_items')->first();
        $this->assertDatabaseHas('inventory_movements', ['return_item_id' => $item->id, 'movement_type' => 'customer_return', 'on_hand_delta' => '1', 'reserved_delta' => '0']);
        $this->assertSame((array) $invoice, (array) DB::table('invoices')->find($invoice->id));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('customer_credit_movements', 0);
        $this->postJson($this->root()."/invoices/$invoice->id/document")->assertOk();
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($document->object_key));
        $this->assertSame(0, app(InventoryReconciliation::class)->inspect($this->store, $this->warehouse)['summary']['with_differences']);
        $this->getJson($this->endpoint().'/requests/'.$key)->assertOk()->assertJsonPath('data.order.return_pending', true);
    }

    public function test_no_restock_receipt_consumes_return_allowance_without_creating_phantom_inventory(): void
    {
        $this->receive($this->review(), '1')->assertCreated();
        $stock = DB::table('stock_balances')->get()->toJson();
        $movements = DB::table('inventory_movements')->count();
        $this->receive($this->review(), '2', false)->assertCreated();
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        $this->assertSame($movements, DB::table('inventory_movements')->count());
        $review = $this->review();
        $this->assertSame('3.0000', $review['lines'][0]['received_quantity']);
        $this->assertSame('0.0000', $review['lines'][0]['available_quantity']);
        $this->receive($review, '0.0001')->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns', 2);
        $this->assertDatabaseHas('sales_return_items', ['quantity' => '2', 'restock' => false, 'refund_amount' => '0']);
    }

    public function test_invalid_batch_and_foreign_invoice_items_leave_all_rows_and_balances_unchanged(): void
    {
        $review = $this->review();
        $foreign = $this->review($this->orders[1]);
        $stock = DB::table('stock_balances')->get()->toJson();
        $this->postJson($this->endpoint(), ['request_key' => (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'reason' => 'Lote con artículo ajeno', 'lines' => [['invoice_item_id' => $review['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true], ['invoice_item_id' => $foreign['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertNotFound();
        $this->receive($review, '4')->assertUnprocessable();
        $this->receive($review, '0')->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns', 0);
        $this->assertDatabaseCount('sales_return_receipts', 0);
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
    }

    public function test_reservation_only_accepts_traced_delivered_units_and_keeps_remaining_reservations(): void
    {
        $id = $this->createOrder('reservation', 0, '3');
        $this->postJson($this->root()."/sales/orders/$id/invoice", ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        $this->receive($this->review($id))->assertUnprocessable();
        $item = DB::table('order_items')->where('order_id', $id)->first();
        $this->postJson($this->root()."/sales/orders/$id/deliver", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($id)->lock_version, 'item_id' => $item->id, 'quantity' => '1'])->assertOk();
        $review = $this->review($id);
        $this->assertSame('1.0000', $review['lines'][0]['available_quantity']);
        $this->receive($review, '0.5')->assertCreated();
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $this->warehouse, 'variant_id' => $this->variants[0], 'on_hand' => '6.5', 'reserved' => '2']);
        $this->assertDatabaseHas('stock_reservations', ['order_item_id' => $item->id, 'fulfilled_quantity' => '1', 'status' => 'active']);
        $this->assertSame(0, app(InventoryReconciliation::class)->inspect($this->store, $this->warehouse)['summary']['with_differences']);
    }

    public function test_pending_adjustment_holds_collection_and_flags_all_members_of_weekly_invoice(): void
    {
        $orders = [$this->createOrder('reservation', 0, '1'), $this->createOrder('reservation', 0, '1')];
        $account = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
        foreach ($orders as $i => $id) {
            $this->postJson($this->root()."/weekly-accounts/$account/orders/attach", ['request_key' => (string) Str::uuid(), 'version' => $i, 'order_id' => $id])->assertOk();
        }
        $review = $this->getJson($this->root()."/weekly-accounts/$account")->json('data');
        $this->postJson($this->root()."/weekly-accounts/$account/close", ['request_key' => (string) Str::uuid(), 'version' => 2, 'review_hash' => $review['review_hash']])->assertOk();
        $id = $orders[0];
        $this->postJson($this->root()."/sales/orders/$id/deliver", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($id)->lock_version])->assertOk();
        $this->receive($this->review($id))->assertCreated();
        $other = $orders[1];
        $this->getJson($this->root()."/sales/orders/$other")->assertOk()->assertJsonPath('data.return_pending', true);
        $this->postJson($this->root()."/sales/orders/$other/payment", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($other)->lock_version, 'amount' => '1', 'payment_method' => 'cash'])->assertConflict();
        $this->getJson($this->root()."/weekly-accounts/$account")->assertOk()->assertJsonPath('data.return_pending', true);
        $rows = $this->getJson($this->root().'/receivables')->assertOk()->json('data');
        $this->assertCount(2, array_filter($rows, fn ($row) => $row['return_pending']));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_capacity_failure_rolls_back_the_entire_multi_line_receipt(): void
    {
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variants[0], 'quantity' => '1'], ['variant_id' => $this->variants[1], 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $id = $this->postJson($this->root().'/sales/orders', [...$body, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => 'sale', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        $version = DB::table('stock_balances')->where('variant_id', $this->variants[1])->value('version');
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variants[1], 'operation' => 'count', 'quantity' => '999999999999999', 'version' => $version, 'note' => 'Capacity boundary QA'])->assertCreated();
        $review = $this->review($id);
        $stock = DB::table('stock_balances')->get()->toJson();
        $movements = DB::table('inventory_movements')->count();
        $this->postJson($this->endpoint($id), ['request_key' => (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'reason' => 'Lote que excede capacidad decimal', 'lines' => array_map(fn ($line) => ['invoice_item_id' => $line['invoice_item_id'], 'quantity' => '1', 'restock' => true], $review['lines'])])->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns', 0);
        $this->assertDatabaseCount('sales_return_items', 0);
        $this->assertDatabaseCount('sales_return_receipts', 0);
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        $this->assertSame($movements, DB::table('inventory_movements')->count());
    }

    public function test_services_cannot_create_physical_return_stock(): void
    {
        $product = app(ProductService::class)->create(['name' => 'Non-stock service', 'product_type' => 'service', 'track_stock' => false, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'RETURN-SERVICE', 'sale_price' => '5', 'attribute_value_ids' => []]]], $this->owner);
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $product->variants()->first()->id, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $id = $this->postJson($this->root().'/sales/orders', [...$body, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => 'sale', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        $review = $this->review($id);
        $this->assertFalse($review['lines'][0]['track_stock']);
        $this->assertSame('0.0000', $review['lines'][0]['available_quantity']);
        $this->receive($review, '1', false)->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_fulfilled_status_without_matching_stock_delivery_cannot_authorize_a_return(): void
    {
        $id = $this->createOrder('reservation', 0, '1');
        $this->postJson($this->root()."/sales/orders/$id/invoice", ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        // Imported status alone must not create evidence of a physical delivery.
        SalesOrder::whereKey($id)->update(['order_kind' => 'sale', 'status' => 'fulfilled']);
        $review = $this->review($id);
        $this->assertSame('0.0000', $review['lines'][0]['delivered_quantity']);
        $this->receive($review)->assertUnprocessable();
        $this->assertDatabaseCount('sales_returns', 0);
    }

    public function test_receiving_requires_csrf_without_the_test_only_middleware_bypass(): void
    {
        $review = $this->review();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->receive($review)->assertStatus(419);
        $this->assertDatabaseCount('sales_returns', 0);
        $this->assertDatabaseCount('sales_return_receipts', 0);
    }

    public function test_permissions_scope_and_stale_review_protect_receiving(): void
    {
        $review = $this->review();
        $this->receive($review)->assertCreated();
        $this->receive($review)->assertConflict();
        $other = Store::create(['name' => 'Other', 'code' => 'RETURN-OTHER']);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/sales/orders/'.$this->orders[0].'/returns')->assertNotFound();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $fresh = $this->review();
        $this->receive($fresh)->assertForbidden();
        $this->assertFalse($seller->permits('returns.receive'));
        $this->assertDatabaseCount('sales_returns', 1);
    }
}
