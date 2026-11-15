<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Sales\ReservationExpiry;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationExpiryTest extends TestCase
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
        $this->travelTo(CarbonImmutable::parse('2026-11-14 12:00:00', 'UTC'));
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Expiry QA', 'default_currency' => 'USD', 'timezone' => 'America/Santo_Domingo', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->actingAs($this->owner);
        $this->customer = $this->postJson($this->root().'/customers', ['request_key' => (string) Str::uuid(), 'full_name' => 'Expiry QA'])->assertCreated()->json('data.customer.id');
        foreach (['A', 'B'] as $sku) {
            $p = app(ProductService::class)->create(['name' => 'Expiry '.$sku, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => $sku, 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
            $id = $p->variants()->first()->id;
            $this->variants[] = $id;
            $this->postJson($this->root().'/inventory/movements', ['warehouse_id' => $this->warehouse, 'variant_id' => $id, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Expiry test stock', 'request_key' => (string) Str::uuid()])->assertCreated();
        }
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function order(string $paid = '0', int $count = 1): array
    {
        $data = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => array_map(fn ($id) => ['variant_id' => $id, 'quantity' => '1.0001'], array_slice($this->variants, 0, $count)), 'shipping' => '0', 'discount' => '0'];
        $quote = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');

        return $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $quote, 'mode' => 'reservation', 'initial_payment' => $paid, 'payment_method' => 'cash', 'request_key' => (string) Str::uuid()])->assertCreated()->json('data.order');
    }

    private function configure(?int $hours, ?int $previous = null): void
    {
        $this->patchJson('/api/v1/business/reservations/settings', ['hours' => $hours, 'previous_hours' => $previous])->assertOk()->assertJsonPath('data.hours', $hours);
    }

    public function test_default_is_disabled_and_policy_changes_are_validated_audited_and_not_retroactive(): void
    {
        $this->getJson('/api/v1/business/reservations/settings')->assertOk()->assertJsonPath('data.hours', null);
        $old = $this->order();
        $this->assertNull($old['reservation_expires_at']);
        $this->configure(24);
        $new = $this->order();
        $this->assertSame('2026-11-15T12:00:00+00:00', $new['reservation_expires_at']);
        $this->configure(null, 24);
        $this->assertNull($this->order()['reservation_expires_at']);
        $this->assertNull(DB::table('stock_reservations')->where('order_item_id', $old['items'][0]['id'])->value('expires_at'));
        $this->patchJson('/api/v1/business/reservations/settings', ['hours' => 0, 'previous_hours' => null])->assertUnprocessable();
        $this->patchJson('/api/v1/business/reservations/settings', ['hours' => 8761, 'previous_hours' => null])->assertUnprocessable();
        $this->patchJson('/api/v1/business/reservations/settings', ['hours' => 2, 'previous_hours' => 24])->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['event' => 'reservations.policy.updated', 'user_id' => $this->owner->id]);
        $seller = User::factory()->create();
        $this->actingAs($seller);
        $this->getJson('/api/v1/business/reservations/settings')->assertForbidden();
        $this->patchJson('/api/v1/business/reservations/settings', ['hours' => 1, 'previous_hours' => null])->assertForbidden();
    }

    public function test_exact_deadline_expires_once_releases_fractional_stock_and_removes_cxc(): void
    {
        $this->configure(1);
        $order = $this->order(count: 2);
        $service = app(ReservationExpiry::class);
        $cutoff = CarbonImmutable::now()->addHour();
        $this->assertSame('skipped', $service->inspect($order['id'], $cutoff->subSecond(), true));
        $this->assertSame('eligible', $service->inspect($order['id'], $cutoff));
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertSame('expired', $service->inspect($order['id'], $cutoff, true));
        $this->assertSame('skipped', $service->inspect($order['id'], $cutoff, true));
        $this->assertDatabaseCount('inventory_movements', 6);
        $this->assertSame(2, DB::table('stock_reservations')->where('status', 'expired')->count());
        foreach (DB::table('stock_balances')->get() as $balance) {
            $this->assertSame('10.0000', bcadd((string) $balance->on_hand, '0', 4));
            $this->assertSame('0.0000', bcadd((string) $balance->reserved, '0', 4));
        }
        $this->assertSame(0, app(InventoryReconciliation::class)->inspect($this->store, $this->warehouse)['summary']['differences']);
        $this->getJson($this->root().'/sales/orders/'.$order['id'])->assertOk()->assertJsonPath('data.reservation_expired', true)->assertJsonPath('data.pending_total', '0.0000')->assertJsonPath('data.lock_version', 1);
        $this->getJson($this->root().'/receivables')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('audit_logs', ['event' => 'sales.reservation.expired', 'user_id' => null]);
        $this->assertDatabaseHas('inventory_movements', ['movement_type' => 'expire', 'performed_by_user_id' => null]);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->postJson($this->root().'/sales/orders/'.$order['id'].'/payment', ['request_key' => (string) Str::uuid(), 'version' => 0, 'amount' => '1', 'payment_method' => 'cash'])->assertConflict();
    }

    public function test_deposits_invoices_and_partial_deliveries_remain_protected(): void
    {
        $this->configure(1);
        $paid = $this->order('1');
        $invoiced = $this->order();
        $partial = $this->order();
        $this->postJson($this->root().'/sales/orders/'.$invoiced['id'].'/invoice', ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        $this->postJson($this->root().'/sales/orders/'.$partial['id'].'/deliver', ['request_key' => (string) Str::uuid(), 'version' => 0, 'item_id' => $partial['items'][0]['id'], 'quantity' => '0.0001'])->assertOk();
        $cutoff = CarbonImmutable::now()->addHours(2);
        $service = app(ReservationExpiry::class);
        $this->assertSame('protected', $service->inspect($paid['id'], $cutoff, true));
        $this->assertSame('skipped', $service->inspect($invoiced['id'], $cutoff, true));
        $this->assertSame('protected', $service->inspect($partial['id'], $cutoff, true));
        $this->assertSame(3, DB::table('stock_reservations')->where('status', 'active')->count());
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'sales.reservation.expired']);
    }

    public function test_basket_edit_uses_first_deadline_without_extending_or_retrofitting_old_reservations(): void
    {
        $legacy = $this->order();
        $this->configure(2);
        $order = $this->order();
        $deadline = $order['reservation_expires_at'];
        $this->travel(1)->hours();
        foreach ([$legacy, $order] as $target) {
            $body = ['version' => 0, 'lines' => [['id' => $target['items'][0]['id'], 'variant_id' => $this->variants[0], 'quantity' => '1'], ['variant_id' => $this->variants[1], 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
            $quote = $this->postJson($this->root().'/baskets/'.$target['id'].'/preview', $body)->assertOk()->json('data.quote_hash');
            $result = $this->patchJson($this->root().'/baskets/'.$target['id'], [...$body, 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote])->assertOk();
            $result->assertJsonPath('data.order.reservation_expires_at', $target['id'] === $legacy['id'] ? null : $deadline);
        }
    }

    public function test_command_is_read_only_by_default_and_apply_is_idempotent_after_policy_disable(): void
    {
        $this->configure(1);
        $this->order();
        $this->configure(null, 1);
        $this->travel(1)->hours();
        $this->artisan('bshop:reservations-expire')->expectsOutputToContain('"read_only":true')->assertExitCode(0);
        $this->assertSame(1, DB::table('stock_reservations')->where('status', 'active')->count());
        $this->artisan('bshop:reservations-expire', ['--apply' => true])->expectsOutputToContain('"expired":1')->assertExitCode(0);
        $this->artisan('bshop:reservations-expire', ['--apply' => true])->expectsOutputToContain('"checked":0')->assertExitCode(0);
        $this->artisan('bshop:reservations-expire', ['--limit' => 0])->assertExitCode(2);
        $this->assertDatabaseCount('inventory_movements', 4);
    }

    public function test_failure_rolls_back_all_releases_and_audit(): void
    {
        $this->configure(1);
        $order = $this->order(count: 2);
        DB::table('stock_balances')->where('variant_id', $this->variants[1])->update(['reserved' => '0']);
        $this->travel(1)->hours();
        $this->artisan('bshop:reservations-expire', ['--apply' => true])->expectsOutputToContain('"failed":1')->assertExitCode(1);
        $this->assertSame('reserved', SalesOrder::find($order['id'])->status);
        $this->assertSame(2, DB::table('stock_reservations')->where('status', 'active')->count());
        $this->assertDatabaseCount('inventory_movements', 4);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'sales.reservation.expired']);
        $this->assertDatabaseMissing('inventory_movements', ['movement_type' => 'expire']);
    }

    public function test_mixed_deadlines_do_not_expire_or_block_another_eligible_order_in_a_limited_batch(): void
    {
        $this->configure(1);
        $mixed = $this->order(count: 2);
        DB::table('stock_reservations')->where('order_item_id', $mixed['items'][1]['id'])->update(['expires_at' => null]);
        $eligible = $this->order();
        $this->travel(1)->hours();
        $this->assertSame('skipped', app(ReservationExpiry::class)->inspect($mixed['id'], CarbonImmutable::now(), true));
        $this->artisan('bshop:reservations-expire', ['--apply' => true, '--limit' => 1])->expectsOutputToContain('"expired":1')->assertExitCode(0);
        $this->assertSame('reserved', SalesOrder::find($mixed['id'])->status);
        $this->assertSame('cancelled', SalesOrder::find($eligible['id'])->status);
    }

    public function test_first_save_of_an_empty_basket_assigns_one_deadline_to_all_new_reservations(): void
    {
        $this->configure(24);
        $order = $this->postJson($this->root().'/baskets', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'customer_id' => $this->customer])->assertCreated()->json('data.order');
        $this->assertNull($order['reservation_expires_at']);
        $body = ['version' => 0, 'lines' => array_map(fn ($id) => ['variant_id' => $id, 'quantity' => '1'], $this->variants), 'shipping' => '0', 'discount' => '0'];
        $quote = $this->postJson($this->root().'/baskets/'.$order['id'].'/preview', $body)->assertOk()->json('data.quote_hash');
        $payload = [...$body, 'quote_hash' => $quote, 'request_key' => (string) Str::uuid()];
        $this->patchJson($this->root().'/baskets/'.$order['id'], $payload)->assertOk()->assertJsonPath('data.order.reservation_expires_at', '2026-11-15T12:00:00+00:00');
        $this->travel(1)->hours();
        $this->patchJson($this->root().'/baskets/'.$order['id'], $payload)->assertOk();
        $this->assertSame(1, DB::table('stock_reservations')->distinct()->count('expires_at'));
        $this->assertDatabaseCount('stock_reservations', 2);
        $this->assertDatabaseCount('inventory_movements', 4);
    }
}
