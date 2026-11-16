<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\InvoiceDocument;
use App\Models\AttributeValue;
use App\Models\Customer;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $customer;

    private array $variants = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'KeylaShop QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->actingAs($this->owner);
        $this->customer = $this->postJson($this->root().'/customers', ['request_key' => (string) Str::uuid(), 'full_name' => 'Cliente ficticio QA', 'phone' => '+1 202-555-0101'])->assertCreated()->json('data.customer.id');
        $context = app(StoreContext::class);
        $context->useStore(Store::find($this->store));
        foreach (['Camiseta' => '25', 'Jeans' => '45', 'Gafas' => '22', 'Perfume' => '49'] as $name => $price) {
            $product = app(ProductService::class)->create(['name' => $name, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => strtoupper($name), 'sale_price' => $price, 'attribute_value_ids' => []]]], $this->owner);
            $variant = $product->variants()->firstOrFail();
            $this->variants[] = $variant->id;
            app(InventoryService::class)->move($context, ['warehouse_id' => $this->warehouse, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '3', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Entrada de ejemplo justificada'], $this->owner);
        }
        $context->clear();
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function draft(int $count = 4): array
    {
        return ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => array_map(fn ($id) => ['variant_id' => $id, 'quantity' => '1'], array_slice($this->variants, 0, $count)), 'discount' => '0', 'shipping' => '5'];
    }

    private function payload(string $mode = 'reservation', string $paid = '0', int $count = 4): array
    {
        $data = $this->draft($count);
        $quote = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data');

        return [...$data, 'mode' => $mode, 'initial_payment' => $paid, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
    }

    private function create(array $payload)
    {
        return $this->postJson($this->root().'/sales/orders', $payload);
    }

    private function change(int $id, string $action, int $version, array $extra = [])
    {
        return $this->postJson($this->root()."/sales/orders/$id/$action", ['request_key' => (string) Str::uuid(), 'version' => $version, ...$extra]);
    }

    public function test_clothing_variants_reserve_deliver_and_cancel_without_changing_other_combinations(): void
    {
        $color = ProductAttribute::create(['name' => 'Color', 'is_active' => true]);
        $size = ProductAttribute::create(['name' => 'Talla', 'is_active' => true]);
        $green = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Verde', 'is_active' => true]);
        $black = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Negro', 'is_active' => true]);
        $small = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'S', 'is_active' => true]);
        $medium = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'M', 'is_active' => true]);
        $product = app(ProductService::class)->create(['name' => 'Blusa', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [
            ['sku' => 'BLUSA-VS', 'sale_price' => '25', 'attribute_value_ids' => [$green->id, $small->id]],
            ['sku' => 'BLUSA-VM', 'sale_price' => '25', 'attribute_value_ids' => [$green->id, $medium->id]],
            ['sku' => 'BLUSA-NS', 'sale_price' => '25', 'attribute_value_ids' => [$black->id, $small->id]],
            ['sku' => 'BLUSA-NM', 'sale_price' => '25', 'attribute_value_ids' => [$black->id, $medium->id]],
        ]], $this->owner);
        $ids = $product->variants()->pluck('id', 'sku');
        $context = app(StoreContext::class);
        $context->useStore(Store::findOrFail($this->store));
        foreach (['BLUSA-VS' => '5', 'BLUSA-VM' => '3', 'BLUSA-NS' => '2'] as $sku => $stock) {
            app(InventoryService::class)->move($context, ['warehouse_id' => $this->warehouse, 'variant_id' => $ids[$sku], 'operation' => 'receipt', 'quantity' => $stock, 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Entrada inicial QA'], $this->owner);
        }
        $context->clear();
        $balance = fn ($sku) => DB::table('stock_balances')->where('warehouse_id', $this->warehouse)->where('variant_id', $ids[$sku])->first();
        $draft = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $ids['BLUSA-VS'], 'quantity' => '1']], 'discount' => '0', 'shipping' => '0'];
        $quote = $this->postJson($this->root().'/sales/quote', $draft)->assertOk()->json('data.quote_hash');
        $this->assertSame('0.0000', bcadd((string) $balance('BLUSA-VS')->reserved, '0', 4));
        $order = $this->create([...$draft, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote])->assertCreated()->json('data.order');
        $this->assertSame('5.0000', bcadd((string) $balance('BLUSA-VS')->on_hand, '0', 4));
        $this->assertSame('1.0000', bcadd((string) $balance('BLUSA-VS')->reserved, '0', 4));
        $this->assertSame('3.0000', bcadd((string) $balance('BLUSA-VM')->on_hand, '0', 4));
        $this->assertSame('2.0000', bcadd((string) $balance('BLUSA-NS')->on_hand, '0', 4));
        $this->change($order['id'], 'deliver', 0, ['item_id' => $order['items'][0]['id'], 'quantity' => '1'])->assertOk();
        $this->assertSame('4.0000', bcadd((string) $balance('BLUSA-VS')->on_hand, '0', 4));
        $this->assertSame('0.0000', bcadd((string) $balance('BLUSA-VS')->reserved, '0', 4));
        $draft['lines'][0]['variant_id'] = $ids['BLUSA-VM'];
        $quote = $this->postJson($this->root().'/sales/quote', $draft)->assertOk()->json('data.quote_hash');
        $second = $this->create([...$draft, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote])->assertCreated()->json('data.order.id');
        $this->change($second, 'cancel', 0)->assertOk();
        $this->assertSame('3.0000', bcadd((string) $balance('BLUSA-VM')->on_hand, '0', 4));
        $this->assertSame('0.0000', bcadd((string) $balance('BLUSA-VM')->reserved, '0', 4));
    }

    public function test_receivables_count_orders_once_and_remove_settled_and_cancelled_balances(): void
    {
        $first = $this->create($this->payload(paid: '10', count: 1))->assertCreated()->json('data.order.id');
        $second = $this->create($this->payload(count: 1))->assertCreated()->json('data.order.id');
        $cancelled = $this->create($this->payload(count: 1))->assertCreated()->json('data.order.id');
        $this->change($cancelled, 'cancel', 0)->assertOk();
        $this->change($first, 'invoice', 0)->assertOk();
        $url = $this->root().'/receivables';
        $this->getJson($url)->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('summary.0.pending', '50.0000')->assertJsonPath('summary.0.paid', '10.0000')
            ->assertJsonPath('summary.0.total', '60.0000')->assertJsonPath('summary.0.customers', 1);
        $this->getJson($url.'?type=invoiced')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_id', $first);
        $this->getJson($url.'?type=unbilled&state=unpaid')->assertOk()->assertJsonPath('data.0.order_id', $second);
        $this->getJson($url.'?state=partial')->assertOk()->assertJsonPath('summary.0.pending', '20.0000');
        $this->change($first, 'payment', 1, ['amount' => '20', 'payment_method' => 'cash'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('summary.0.pending', '30.0000');
    }

    public function test_receivables_filters_age_exports_exact_decimals_and_scopes_branch(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
        $id = $this->create($this->payload(paid: '0.10', count: 1))->assertCreated()->json('data.order.id');
        $this->travel(45)->days();
        $url = $this->root().'/receivables';
        $this->getJson($url.'?min_days=30&q=Cliente')->assertOk()->assertJsonPath('data.0.age_days', 45)->assertJsonPath('summary.0.pending', '29.9000');
        $this->getJson($url.'?min_days=60')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'?q=ausente')->assertOk()->assertJsonCount(0, 'summary');
        DB::table('customers')->where('id', $this->customer)->update(['full_name' => '=HYPERLINK("test")']);
        $csv = $this->get($url.'/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('29.9000', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $other = Store::create(['name' => 'Otra autorizada', 'code' => 'CXC']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/receivables')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'?min_days=-1')->assertUnprocessable();
        $this->travelBack();
    }

    public function test_receivables_require_customer_and_sales_read_permissions(): void
    {
        $role = Role::create(['code' => 'cxc_denied', 'label' => 'Sin permiso de clientes', 'permissions' => ['sales.view']]);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $user->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($user);
        $this->getJson($this->root().'/receivables')->assertForbidden();
        $this->getJson($this->root().'/receivables/export')->assertForbidden();
    }

    public function test_reference_totals_are_server_exact_and_quotes_do_not_reserve(): void
    {
        $this->postJson($this->root().'/sales/quote', $this->draft(3))->assertOk()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.subtotal', '92.0000')->assertJsonPath('data.grand_total', '97.0000');
        $payload = $this->payload(paid: '50');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->create($payload)->assertCreated()->assertJsonPath('data.order.lock_version', 0)->assertJsonPath('data.order.subtotal', '141.0000')->assertJsonPath('data.order.grand_total', '146.0000')->assertJsonPath('data.order.paid_total', '50.0000')->assertJsonPath('data.order.pending_total', '96.0000')->assertJsonPath('data.order.status', 'reserved');
        $this->assertDatabaseCount('stock_reservations', 4);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '3.0000', 'reserved' => '1.0000']);
    }

    public function test_sale_retry_emits_one_invoice_payment_pdf_and_stock_movement_per_line(): void
    {
        $data = $this->payload('sale', '97', 3);
        $response = $this->create($data)->assertCreated();
        if ($response->json('data.document.status') === 'failed') {
            app(InvoiceDocument::class)->ensure($response->json('data.order.invoice.id'));
        }
        $response->assertJsonPath('data.document.status', 'ready')->assertJsonPath('data.order.invoice.status', 'paid');
        $id = $response->json('data.order.id');
        $invoice = $response->json('data.order.invoice.id');
        $this->create($data)->assertOk()->assertJsonPath('data.replay', true)->assertJsonPath('data.document.status', 'ready')->assertJsonPath('data.order.id', $id);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '2.0000', 'reserved' => '0.0000']);
        $download = $this->get($this->root()."/invoices/$invoice/document")->assertOk()->assertHeader('content-type', 'application/pdf')->assertHeader('cache-control', 'no-store, private');
        $key = "invoices/$invoice/v1.pdf";
        $bytes = Storage::disk('private_invoices')->get($key);
        $this->assertSame($bytes, $download->streamedContent());
        $this->assertStringStartsWith('%PDF-', $bytes);
        ProductVariant::whereKey($this->variants[0])->update(['sale_price' => '999']);
        Customer::whereKey($this->customer)->update(['full_name' => 'Nombre cambiado']);
        $this->postJson($this->root()."/invoices/$invoice/document")->assertOk();
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($key));
        $this->assertDatabaseHas('invoice_items', ['variant_id' => $this->variants[0], 'unit_price' => '25.0000']);
        Storage::disk('private_invoices')->put($key, '%PDF-corrupted');
        $this->get($this->root()."/invoices/$invoice/document")->assertStatus(503);
    }

    public function test_invoice_payment_and_partial_delivery_never_decrement_reserved_stock_twice(): void
    {
        $id = $this->create($this->payload(paid: '50'))->assertCreated()->json('data.order.id');
        $this->change($id, 'invoice', 0, ['deliver_now' => false])->assertOk()->assertJsonPath('data.order.status', 'confirmed')->assertJsonPath('data.order.invoice.status', 'partially_paid');
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '3.0000', 'reserved' => '1.0000']);
        $this->change($id, 'payment', 1, ['amount' => '96', 'payment_method' => 'cash'])->assertOk()->assertJsonPath('data.order.pending_total', '0.0000')->assertJsonPath('data.order.invoice.status', 'paid');
        $item = SalesOrder::find($id)->items()->where('variant_id', $this->variants[0])->firstOrFail();
        $this->change($id, 'deliver', 2, ['item_id' => $item->id, 'quantity' => '0.5'])->assertOk()->assertJsonPath('data.order.status', 'confirmed');
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '2.5', 'reserved' => '0.5']);
        $this->change($id, 'deliver', 3)->assertOk()->assertJsonPath('data.order.status', 'fulfilled');
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '2.0000', 'reserved' => '0.0000']);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 4);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame('0.0000', bcadd((string) DB::table('customer_credit_movements')->sum('amount_delta'), '0', 4));
    }

    public function test_removing_an_unpaid_item_releases_its_reservation_without_erasing_history(): void
    {
        $id = $this->create($this->payload())->assertCreated()->json('data.order.id');
        $item = SalesOrder::find($id)->items()->firstOrFail();
        $this->change($id, 'remove', 0, ['item_id' => $item->id])->assertOk()->assertJsonCount(3, 'data.order.items')->assertJsonPath('data.order.grand_total', '121.0000');
        $this->assertDatabaseCount('order_items', 4);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '3', 'reserved' => '0']);
        $this->change($id, 'cancel', 1)->assertOk()->assertJsonPath('data.order.status', 'cancelled')->assertJsonPath('data.order.pending_total', '0.0000');
        $this->assertSame(0, DB::table('stock_reservations')->where('status', 'active')->count());
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 4);
    }

    public function test_insufficient_stock_and_overpayment_roll_back_every_commercial_effect(): void
    {
        $data = $this->payload();
        DB::table('stock_balances')->where('variant_id', $this->variants[3])->update(['on_hand' => '0']);
        $this->create($data)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->create($this->payload(paid: '147'))->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_catalog_price_change_requires_a_new_quote_before_committing(): void
    {
        $data = $this->payload();
        ProductVariant::whereKey($this->variants[0])->update(['sale_price' => '26']);
        $this->create($data)->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->create($this->payload())->assertCreated()->assertJsonPath('data.order.grand_total', '147.0000');
    }

    public function test_existing_deposit_cannot_be_silently_discarded_or_overpaid(): void
    {
        $id = $this->create($this->payload(paid: '50'))->assertCreated()->json('data.order.id');
        $this->change($id, 'cancel', 0)->assertConflict();
        $this->change($id, 'payment', 0, ['amount' => '97', 'payment_method' => 'cash'])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'reserved']);
    }

    public function test_customer_creation_is_idempotent_and_foreign_branch_has_no_access(): void
    {
        $key = (string) Str::uuid();
        $data = ['request_key' => $key, 'full_name' => 'Otro cliente ficticio', 'phone' => '+1 202-555-0102'];
        $id = $this->postJson($this->root().'/customers', $data)->assertCreated()->json('data.customer.id');
        $this->postJson($this->root().'/customers', $data)->assertOk()->assertJsonPath('data.customer.id', $id);
        $other = Store::create(['name' => 'Sin acceso', 'code' => 'HIDDEN']);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/customers')->assertNotFound();
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_sales_catalog_contains_only_available_quantity_not_costs_or_physical_balances(): void
    {
        $this->getJson($this->root().'/sales/catalog?warehouse_id='.$this->warehouse)->assertOk()->assertJsonPath('data.0.variants.0.available', '3.0000')->assertJsonMissingPath('data.0.variants.0.cost_price')->assertJsonMissingPath('data.0.variants.0.on_hand');
        $viewer = User::factory()->create();
        $role = Role::create(['code' => 'sales-readonly', 'label' => 'Consulta', 'permissions' => ['stores.view', 'sales.view']]);
        $viewer->roles()->attach($role);
        DB::table('store_user')->insert(['store_id' => $this->store, 'user_id' => $viewer->id]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($viewer);
        $this->postJson($this->root().'/sales/quote', $this->draft())->assertForbidden();
        $this->getJson($this->root().'/sales/orders')->assertOk();
    }

    public function test_pdf_failure_keeps_issued_invoice_and_retry_generates_same_document(): void
    {
        $renderer = app('dompdf.wrapper');
        Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('Injected renderer failure'));
        $data = $this->payload('sale', '97', 3);
        $first = $this->create($data)->assertCreated()->assertJsonPath('data.document.status', 'failed');
        $invoice = $first->json('data.order.invoice.id');
        $this->assertDatabaseCount('invoices', 1);
        Pdf::swap($renderer);
        $this->create($data)->assertOk()->assertJsonPath('data.document.status', 'ready')->assertJsonPath('data.order.invoice.id', $invoice);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_late_audit_failure_rolls_back_stock_payments_invoice_and_order_together(): void
    {
        DB::statement("CREATE TRIGGER reject_sales_audit BEFORE INSERT ON audit_logs WHEN NEW.event = 'sales.invoice.issued' BEGIN SELECT RAISE(ABORT, 'Injected audit failure'); END");
        $this->create($this->payload('sale', '97', 3))->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoice_documents', 0);
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '3', 'reserved' => '0']);
    }

    public function test_other_branch_cannot_download_or_mutate_an_order_or_invoice(): void
    {
        $response = $this->create($this->payload('sale', '97', 3))->assertCreated();
        $id = $response->json('data.order.id');
        $invoice = $response->json('data.order.invoice.id');
        $other = Store::create(['name' => 'Otra autorizada', 'code' => 'OTHER']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $root = '/api/v1/business/stores/'.$other->id;
        $this->getJson($root.'/sales/orders/'.$id)->assertNotFound();
        $this->getJson($root.'/invoices/'.$invoice.'/document')->assertNotFound();
        $this->postJson($root.'/sales/orders/'.$id.'/payment', ['request_key' => (string) Str::uuid(), 'version' => 0, 'amount' => '1', 'payment_method' => 'cash'])->assertNotFound();
    }

    public function test_delivered_basket_remains_open_until_it_is_invoiced(): void
    {
        $id = $this->create($this->payload())->assertCreated()->json('data.order.id');
        $this->change($id, 'deliver', 0)->assertOk()->assertJsonPath('data.order.status', 'fulfilled');
        $this->getJson($this->root().'/sales/orders')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->change($id, 'invoice', 1)->assertOk();
        $this->getJson($this->root().'/sales/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_more_than_one_open_basket_per_customer_is_allowed_without_duplicate_requests(): void
    {
        $first = $this->create($this->payload())->assertCreated()->json('data.order.id');
        $second = $this->create($this->payload())->assertCreated()->json('data.order.id');
        $this->assertNotSame($first, $second);
        $this->getJson($this->root().'/sales/orders')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variants[0], 'on_hand' => '3', 'reserved' => '2']);
    }

    public function test_request_recovery_returns_current_state_and_rejects_another_actor(): void
    {
        $payload = $this->payload(paid: '50');
        $id = $this->create($payload)->assertCreated()->json('data.order.id');
        $this->change($id, 'payment', 0, ['amount' => '96', 'payment_method' => 'cash'])->assertOk();
        $this->getJson($this->root().'/sales/requests/'.$payload['request_key'])->assertOk()->assertJsonPath('data.order.pending_total', '0.0000')->assertJsonPath('data.order.lock_version', 1)->assertJsonMissingPath('data._identity');
        $other = User::factory()->create();
        $other->roles()->attach(Role::where('code', 'manager')->value('id'));
        DB::table('store_user')->insert(['user_id' => $other->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($other);
        $this->getJson($this->root().'/sales/requests/'.$payload['request_key'])->assertNotFound();
    }
}
