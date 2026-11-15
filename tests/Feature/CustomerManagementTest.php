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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Customer QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->customer = Customer::factory()->create(['full_name' => 'Cliente Original', 'phone' => '+1 202-555-0101']);
        $this->actingAs($this->owner);
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function customerStatus(string $status, int $version, ?string $key = null)
    {
        return $this->patchJson($this->root().'/customers/'.$this->customer->id.'/status', ['status' => $status, 'version' => $version, 'request_key' => $key ?? (string) Str::uuid()]);
    }

    public function test_archive_reactivate_replay_and_stale_requests_preserve_one_version_per_change(): void
    {
        $key = (string) Str::uuid();
        $this->customerStatus('archived', 0, $key)->assertOk()->assertJsonPath('data.customer.lock_version', 1)->assertJsonPath('data.replay', false);
        $this->customerStatus('archived', 0, $key)->assertOk()->assertJsonPath('data.replay', true);
        $this->customerStatus('active', 0)->assertConflict();
        $this->customerStatus('active', 0, $key)->assertConflict();
        $this->customerStatus('archived', 1)->assertOk()->assertJsonPath('data.customer.lock_version', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'customer.archived')->count());
        $this->customerStatus('active', 1)->assertOk()->assertJsonPath('data.customer.lock_version', 2);
        $this->customerStatus('archived', 0, $key)->assertOk()->assertJsonPath('data.customer.status', 'archived');
        $this->assertSame('active', $this->customer->fresh()->status);
        $this->getJson($this->root().'/customers/requests/'.strtoupper($key).'?action=status')->assertOk()->assertJsonPath('data.customer.status', 'active')->assertJsonPath('data.customer.lock_version', 2);
        $this->assertNull($this->customer->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.reactivated', 'user_id' => $this->owner->id]);
    }

    public function test_lists_default_to_active_with_archived_filters_pagination_and_literal_search(): void
    {
        $this->customerStatus('archived', 0)->assertOk();
        Customer::factory()->count(17)->create();
        $special = Customer::factory()->create(['full_name' => 'Literal %_! customer']);
        $this->getJson($this->root().'/customers')->assertOk()->assertJsonPath('meta.total', 18)->assertJsonCount(15, 'data');
        $this->getJson($this->root().'/customers?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($this->root().'/customers?status=archived')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->customer->id);
        $this->getJson($this->root().'/customers?status=all')->assertOk()->assertJsonPath('meta.total', 19);
        $this->getJson($this->root().'/customers?q='.rawurlencode('%_!'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $special->id);
        $this->getJson($this->root().'/customers?status=invalid')->assertUnprocessable();
        $this->getJson($this->root().'/customers/'.$this->customer->id)->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson($this->root().'/customers/999999')->assertNotFound();
    }

    public function test_archived_customer_retains_invoice_snapshot_cxc_stock_and_can_pay_existing_order(): void
    {
        $product = app(ProductService::class)->create(['name' => 'QA product', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CUSTOMER-QA', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $variant = $product->variants()->first()->id;
        $warehouse = Warehouse::first()->id;
        $this->postJson($this->root().'/inventory/movements', ['warehouse_id' => $warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '2', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Customer lifecycle QA'])->assertCreated();
        $body = ['warehouse_id' => $warehouse, 'customer_id' => $this->customer->id, 'lines' => [['variant_id' => $variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $quote = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $payload = [...$body, 'quote_hash' => $quote, 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => '1', 'payment_method' => 'cash'];
        $order = $this->postJson($this->root().'/sales/orders', $payload)->assertCreated()->json('data.order');
        $this->postJson($this->root().'/sales/orders/'.$order['id'].'/invoice', ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        $invoice = (array) DB::table('invoices')->where('order_id', $order['id'])->first();
        $this->customerStatus('archived', 0)->assertOk();
        $this->patchJson($this->root().'/customers/'.$this->customer->id, ['request_key' => (string) Str::uuid(), 'version' => 1, 'full_name' => 'Contacto Actualizado'])->assertOk()->assertJsonPath('data.customer.status', 'archived');
        $this->assertSame($invoice, (array) DB::table('invoices')->where('order_id', $order['id'])->first());
        $this->getJson($this->root().'/sales/orders/'.$order['id'])->assertOk()->assertJsonPath('data.customer_snapshot.full_name', 'Cliente Original')->assertJsonPath('data.customer_live.full_name', 'Contacto Actualizado');
        $this->postJson($this->root().'/sales/orders/'.$order['id'].'/payment', ['request_key' => (string) Str::uuid(), 'version' => 1, 'amount' => '3', 'payment_method' => 'cash'])->assertOk()->assertJsonPath('data.order.pending_total', '6.0000');
        $this->getJson($this->root().'/receivables')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.pending', '6.0000');
        $this->getJson($this->root().'/customers/'.$this->customer->id.'/summary')->assertOk()->assertJsonPath('data.pending_total', '6.0000');
        $this->getJson($this->root().'/customers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->root().'/sales/orders', [...$payload, 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->postJson($this->root().'/baskets', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'customer_id' => $this->customer->id])->assertNotFound();
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $variant, 'on_hand' => '2', 'reserved' => '1']);
    }

    public function test_permissions_branch_scope_and_recovery_identity_are_enforced(): void
    {
        $key = (string) Str::uuid();
        $this->customerStatus('archived', 0, $key)->assertOk();
        $other = Store::create(['name' => 'Other', 'code' => 'CUST-OTHER']);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/customers/'.$this->customer->id)->assertNotFound();
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/customers/requests/'.$key.'?action=status')->assertNotFound();
        $role = Role::create(['code' => 'customer_reader_qa', 'label' => 'Reader', 'permissions' => ['stores.view', 'customers.view']]);
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $reader->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($this->root().'/customers/'.$this->customer->id)->assertOk();
        $this->customerStatus('active', 1)->assertForbidden();
        $this->getJson($this->root().'/customers/requests/'.$key.'?action=status')->assertNotFound();
        $this->getJson('/api/v1/business/stores/'.$other->id.'/customers/'.$this->customer->id)->assertNotFound();
        $this->assertSame('archived', $this->customer->fresh()->status);
    }

    public function test_invalid_or_stale_changes_do_not_mutate_the_profile(): void
    {
        $this->customerStatus('deleted', 0)->assertUnprocessable();
        $this->customerStatus('archived', 0)->assertOk();
        $this->patchJson($this->root().'/customers/'.$this->customer->id, ['request_key' => (string) Str::uuid(), 'version' => 0, 'full_name' => 'Stale name'])->assertConflict();
        $this->assertSame('Cliente Original', $this->customer->fresh()->full_name);
        $this->assertSame(1, $this->customer->fresh()->lock_version);
        $this->assertDatabaseCount('customers', 1);
    }
}
