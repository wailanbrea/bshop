<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderViewLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Links QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $customer = Customer::factory()->create(['full_name' => 'Private customer', 'phone' => 'private-phone', 'notes' => 'private-notes']);
        $product = app(ProductService::class)->create(['name' => 'Bolso <script>alert(1)</script>', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'LINK-QA', 'sale_price' => '25', 'cost_price' => '7', 'attribute_value_ids' => []]]], $this->owner);
        $variant = $product->variants()->first()->id;
        $warehouse = Warehouse::first()->id;
        $this->actingAs($this->owner);
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Link QA'])->assertCreated();
        $data = ['warehouse_id' => $warehouse, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $variant, 'quantity' => '10']], 'shipping' => '5', 'discount' => '0'];
        $quote = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');
        $this->order = $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $quote, 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => '20', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function endpoint(?int $order = null): string
    {
        return $this->root().'/baskets/'.($order ?? $this->order).'/view-links';
    }

    private function createLink(?string $key = null, int $hours = 24)
    {
        return $this->postJson($this->endpoint(), ['request_key' => $key ?? (string) Str::uuid(), 'hours' => $hours]);
    }

    public function test_guest_reads_only_this_order_without_consumption_or_financial_changes(): void
    {
        SalesOrder::findOrFail($this->order)->items()->firstOrFail()->update(['variant_snapshot' => [['name' => 'Color', 'value' => 'Violeta']]]);
        $result = $this->createLink()->assertCreated()->json('data');
        $token = basename($result['path']);
        $this->assertSame(64, strlen($token));
        $this->assertSame(hash('sha256', $token), DB::table('order_view_links')->value('token_hash'));
        $this->assertStringNotContainsString($token, DB::table('idempotency_requests')->where('operation', 'order-view-links.create')->value('response_data'));
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_logs')->get()));
        $before = [];
        foreach (['orders', 'order_items', 'stock_balances', 'stock_reservations', 'inventory_movements', 'payments', 'invoices', 'order_view_links'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        Auth::forgetGuards();
        $this->app['session']->flush();
        foreach ([1, 2] as $_) {
            $this->get($result['path'])->assertOk()->assertSee('US$ 255.00')->assertSee('US$ 235.00')->assertSee('10 ×')->assertSee('Color: Violeta')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false)->assertDontSee('Private customer')->assertDontSee('private-phone')->assertDontSee('private-notes')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table.' changed on GET');
        }
        $this->post($result['path'])->assertStatus(405);
        $this->get('/s/order/'.str_repeat('0', 64))->assertNotFound()->assertSee('Enlace no disponible')->assertDontSee('255.00');
    }

    public function test_retry_is_idempotent_and_only_current_active_link_can_be_returned(): void
    {
        $key = (string) Str::uuid();
        $first = $this->createLink($key)->assertCreated()->json('data');
        $this->createLink(strtoupper($key))->assertOk()->assertJsonPath('data.path', $first['path'])->assertJsonPath('data.replay', true);
        $this->assertDatabaseCount('order_view_links', 1);
        $this->createLink($key, 72)->assertConflict();
        $this->getJson($this->endpoint())->assertOk()->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.path');
        $this->postJson($this->endpoint().'/'.$first['link']['id'].'/revoke')->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson($this->endpoint().'/'.$first['link']['id'].'/revoke')->assertOk();
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order.view_link.revoked')->count());
        $this->createLink($key)->assertOk()->assertJsonPath('data.path', null);
        $this->get($first['path'])->assertNotFound();
    }

    public function test_expiry_cancellation_and_inactive_store_make_public_link_unavailable(): void
    {
        $first = $this->createLink(hours: 1)->assertCreated()->json('data');
        $this->travel(1)->hours();
        $this->get($first['path'])->assertNotFound();
        $this->travelBack();
        SalesOrder::whereKey($this->order)->update(['status' => 'cancelled']);
        $this->get($first['path'])->assertNotFound();
        $this->createLink()->assertConflict();
        SalesOrder::whereKey($this->order)->update(['status' => 'reserved']);
        Store::whereKey($this->store)->update(['is_active' => false]);
        $this->get($first['path'])->assertNotFound();
    }

    public function test_permissions_scope_and_link_identity_are_enforced(): void
    {
        $first = $this->createLink()->assertCreated()->json('data');
        $other = Store::create(['name' => 'Other', 'code' => 'LINK-OTHER']);
        $this->postJson('/api/v1/business/stores/'.$other->id.'/baskets/'.$this->order.'/view-links', ['request_key' => (string) Str::uuid(), 'hours' => 24])->assertNotFound();
        $this->postJson($this->endpoint().'/999999/revoke')->assertNotFound();
        $role = Role::create(['code' => 'link_reader', 'label' => 'Reader', 'permissions' => ['stores.view', 'sales.view']]);
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $reader->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($this->endpoint())->assertOk();
        $this->createLink()->assertForbidden();
        $this->postJson($this->endpoint().'/'.$first['link']['id'].'/revoke')->assertForbidden();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson($this->endpoint())->assertUnauthorized();
        $this->get($first['path'])->assertOk();
    }

    public function test_invalid_expiry_drafts_and_active_link_limit_are_rejected(): void
    {
        $this->createLink(hours: 2)->assertUnprocessable();
        $this->postJson($this->endpoint(), ['request_key' => 'bad', 'hours' => 24])->assertUnprocessable();
        SalesOrder::whereKey($this->order)->update(['status' => 'draft']);
        $this->createLink()->assertConflict();
        SalesOrder::whereKey($this->order)->update(['status' => 'reserved']);
        for ($i = 0; $i < 5; $i++) {
            $this->createLink()->assertCreated();
        }
        $this->createLink()->assertConflict();
        $this->assertDatabaseCount('order_view_links', 5);
    }

    public function test_public_invalid_tokens_are_rate_limited_without_disclosing_orders(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get('/s/order/'.str_pad((string) $i, 64, '0'))->assertNotFound();
        }
        $this->get('/s/order/'.str_repeat('f', 64))->assertStatus(429)->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Enlace no disponible');
    }

    public function test_active_links_remain_revocable_when_newer_expired_history_exceeds_the_list_limit(): void
    {
        $active = $this->createLink()->assertCreated()->json('data.link.id');
        for ($i = 0; $i < 25; $i++) {
            DB::table('order_view_links')->insert(['order_id' => $this->order, 'store_id' => $this->store, 'created_by_user_id' => $this->owner->id,
                'token_hash' => hash('sha256', 'expired-'.$i), 'expires_at' => now()->subHour(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson($this->endpoint())->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.0.id', $active)->assertJsonPath('data.0.status', 'active');
        $this->postJson($this->endpoint().'/'.$active.'/revoke')->assertOk()->assertJsonPath('data.status', 'revoked');
    }
}
