<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderRequestLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $customer;

    private int $warehouse;

    private int $variant;

    private int $excluded;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Public order QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create(['full_name' => 'Private customer name', 'notes' => 'Private customer notes'])->id;
        $this->actingAs($this->owner);
        foreach (['Authorized bag', 'Excluded bag'] as $index => $name) {
            $product = app(ProductService::class)->create(['name' => $name, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'REQUEST-'.$index, 'sale_price' => '25', 'cost_price' => '3', 'attribute_value_ids' => []]]], $this->owner);
            if ($index === 0) {
                $this->variant = $product->variants()->first()->id;
            } else {
                $this->excluded = $product->variants()->first()->id;
            }
        }
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Public order QA entry'])->assertCreated();
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function issue(?string $key = null, array $extra = [])
    {
        return $this->postJson($this->root().'/order-request-links', ['request_key' => $key ?? (string) Str::uuid(), 'customer_id' => $this->customer, 'warehouse_id' => $this->warehouse, 'variant_ids' => [$this->variant], 'hours' => 24, 'shipping' => '5', ...$extra]);
    }

    private function guest(): void
    {
        Auth::forgetGuards();
        $this->app['session']->flush();
    }

    private function endpoint(string $path): string
    {
        return '/api/v1/order-requests/'.basename($path);
    }

    private function lines(string $quantity = '2'): array
    {
        return ['lines' => [['variant_id' => $this->variant, 'quantity' => $quantity]]];
    }

    public function test_guest_catalog_is_explicitly_limited_and_preview_does_not_consume_or_reserve(): void
    {
        $link = $this->issue()->assertCreated()->json('data');
        $endpoint = $this->endpoint($link['path']);
        $this->guest();
        $this->get($link['path'])->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonMissingPath('data.customer_id')->assertDontSee('Private customer name');
        $this->getJson($endpoint.'/catalog')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->variant)->assertJsonPath('data.0.available', true)->assertJsonMissingPath('data.0.cost_price')->assertDontSee('Excluded bag');
        $this->postJson($endpoint.'/quote', $this->lines())->assertOk()->assertJsonPath('data.grand_total', '55.0000')->assertJsonPath('data.shipping_total', '5.0000')->assertJsonMissingPath('data.warehouse_id');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('share_links', ['id' => $link['link']['id'], 'status' => 'active', 'used_at' => null]);
    }

    public function test_public_confirmation_is_once_bound_to_the_link_and_cannot_set_price_customer_or_payment(): void
    {
        $link = $this->issue()->assertCreated()->json('data');
        $endpoint = $this->endpoint($link['path']);
        $this->guest();
        $body = [...$this->lines(), 'customer_id' => 99999, 'warehouse_id' => 99999, 'shipping' => '0', 'discount' => '999', 'mode' => 'sale', 'initial_payment' => '55', 'price' => '0', 'notes' => 'Injected notes'];
        $quote = $this->postJson($endpoint.'/quote', $body)->assertOk()->assertJsonPath('data.grand_total', '55.0000')->json('data.quote_hash');
        $key = (string) Str::uuid();
        $payload = [...$body, 'quote_hash' => $quote, 'request_key' => $key];
        $this->postJson($endpoint.'/confirm', $payload)->assertCreated()->assertJsonPath('data.receipt.total', '55.0000')->assertJsonPath('data.receipt.status', 'reserved')->assertJsonMissingPath('data.receipt.customer_id')->assertDontSee('Private customer');
        $this->postJson($endpoint.'/confirm', [...$payload, 'request_key' => strtoupper($key)])->assertOk()->assertJsonPath('data.replay', true);
        $this->postJson($endpoint.'/confirm', [...$payload, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->postJson($endpoint.'/confirm', [...$payload, ...$this->lines('3')])->assertConflict();
        $this->getJson($endpoint)->assertOk()->assertJsonPath('data.status', 'used')->assertJsonPath('data.receipt.total', '55.0000');
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseHas('orders', ['customer_id' => $this->customer, 'warehouse_id' => $this->warehouse, 'channel' => 'share_link', 'share_link_id' => $link['link']['id'], 'grand_total' => '55', 'notes' => null]);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant, 'on_hand' => '10', 'reserved' => '2']);
        $token = basename($link['path']);
        $this->assertStringNotContainsString($token, DB::table('idempotency_requests')->get()->toJson());
        $this->assertStringNotContainsString($token, DB::table('audit_logs')->get()->toJson());
    }

    public function test_failed_stock_or_changed_prices_do_not_consume_link_and_new_quote_can_succeed(): void
    {
        $link = $this->issue()->assertCreated()->json('data');
        $endpoint = $this->endpoint($link['path']);
        $this->guest();
        $hash = $this->postJson($endpoint.'/quote', $this->lines('11'))->assertOk()->json('data.quote_hash');
        $this->postJson($endpoint.'/confirm', [...$this->lines('11'), 'quote_hash' => $hash, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('share_links', ['id' => $link['link']['id'], 'status' => 'active']);
        $hash = $this->postJson($endpoint.'/quote', $this->lines())->assertOk()->json('data.quote_hash');
        ProductVariant::whereKey($this->variant)->update(['sale_price' => '30']);
        $this->postJson($endpoint.'/confirm', [...$this->lines(), 'quote_hash' => $hash, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant, 'reserved' => '0']);
        $hash = $this->postJson($endpoint.'/quote', $this->lines())->assertOk()->assertJsonPath('data.grand_total', '65.0000')->json('data.quote_hash');
        $this->postJson($endpoint.'/confirm', [...$this->lines(), 'quote_hash' => $hash, 'request_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.receipt.total', '65.0000');
    }

    public function test_unapproved_variants_invalid_quantities_expiry_and_revocation_are_rejected(): void
    {
        $link = $this->issue()->assertCreated()->json('data');
        $endpoint = $this->endpoint($link['path']);
        $this->postJson($endpoint.'/quote', ['lines' => [['variant_id' => $this->excluded, 'quantity' => '1']]])->assertUnprocessable();
        $this->postJson($endpoint.'/quote', $this->lines('0'))->assertUnprocessable();
        $this->postJson($this->root().'/order-request-links/'.$link['link']['id'].'/revoke')->assertOk();
        $this->getJson($endpoint)->assertNotFound();
        $this->postJson($endpoint.'/quote', $this->lines())->assertNotFound();
        $second = $this->issue()->assertCreated()->json('data');
        $this->travel(25)->hours();
        $this->getJson($this->endpoint($second['path']))->assertNotFound();
        $this->travelBack();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_private_issue_replay_scope_and_issuer_access_are_enforced(): void
    {
        $key = (string) Str::uuid();
        $link = $this->issue($key)->assertCreated()->json('data');
        $this->issue(strtoupper($key))->assertOk()->assertJsonPath('data.path', $link['path']);
        $this->issue($key, ['shipping' => '6'])->assertConflict();
        $this->assertDatabaseCount('share_links', 1);
        $this->getJson($this->root().'/order-request-links?customer_id='.$this->customer)->assertOk()->assertJsonMissingPath('data.0.token_hash')->assertJsonMissingPath('data.0.path');
        $this->issue(extra: ['warehouse_id' => 99999])->assertNotFound();
        $this->issue(extra: ['variant_ids' => [$this->variant, $this->variant]])->assertUnprocessable();
        $other = Store::create(['name' => 'Other', 'code' => 'REQUEST-OTHER']);
        $this->postJson('/api/v1/business/stores/'.$other->id.'/order-request-links/'.$link['link']['id'].'/revoke')->assertNotFound();
        $this->owner->is_active = false;
        $this->owner->save();
        $this->guest();
        $this->getJson($this->endpoint($link['path']))->assertNotFound();
        $this->issue()->assertUnauthorized();
    }

    public function test_revoking_used_link_never_cancels_order_or_releases_its_reservation(): void
    {
        $link = $this->issue()->assertCreated()->json('data');
        $endpoint = $this->endpoint($link['path']);
        $hash = $this->postJson($endpoint.'/quote', $this->lines())->assertOk()->json('data.quote_hash');
        $this->postJson($endpoint.'/confirm', [...$this->lines(), 'quote_hash' => $hash, 'request_key' => (string) Str::uuid()])->assertCreated();
        $this->postJson($this->root().'/order-request-links/'.$link['link']['id'].'/revoke')->assertOk();
        $this->postJson($this->root().'/order-request-links/'.$link['link']['id'].'/revoke')->assertOk();
        $this->getJson($endpoint)->assertNotFound();
        $this->assertSame('reserved', SalesOrder::first()->status);
        $this->assertDatabaseHas('stock_balances', ['variant_id' => $this->variant, 'reserved' => '2']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'order.request_link.revoked')->count());
    }
}
