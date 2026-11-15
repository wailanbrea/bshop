<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTest extends TestCase
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
        app(InstallBusiness::class)->create($this->owner, ['name' => 'KeylaShop QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $product = app(ProductService::class)->create(['name' => 'Camiseta', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CAM-M', 'sale_price' => '25', 'cost_price' => '12', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $product->variants()->first()->id;
        $this->actingAs($this->owner);
    }

    private function move(string $operation = 'receipt', string|float $quantity = '5', int $version = 0, array $extra = [])
    {
        return $this->postJson("/api/v1/business/stores/{$this->store}/inventory/movements", [...['warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => $operation, 'quantity' => $quantity, 'version' => $version, 'note' => 'Operación QA justificada', 'request_key' => (string) Str::uuid()], ...$extra]);
    }

    private function stock()
    {
        return $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/stock");
    }

    private function reconciliation(string $query = '')
    {
        return $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/reconciliation".$query);
    }

    public function test_reconciliation_checks_zero_and_fractional_balances_without_writing(): void
    {
        $this->reconciliation()->assertOk()->assertJsonPath('summary.checked', 1)->assertJsonPath('summary.consistent', 1)->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('stock_balances', 0);
        $this->move('receipt', '2.0001')->assertCreated();
        $this->move('issue', '1', 1)->assertCreated();
        $auditCount = DB::table('audit_logs')->count();
        $this->reconciliation('?only_issues=0')->assertOk()->assertJsonPath('data.0.physical', '1.0001')
            ->assertJsonPath('data.0.ledger_physical', '1.0001')->assertJsonPath('summary.movements', 2)->assertJsonPath('summary.differences', 0);
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
    }

    public function test_reconciliation_reports_corruption_without_repairing_it(): void
    {
        $this->move()->assertCreated();
        DB::table('stock_balances')->update(['on_hand' => '4.9999', 'reserved' => '1.0000', 'version' => 42]);
        $response = $this->reconciliation()->assertOk()->assertJsonPath('summary.with_differences', 1)->assertJsonPath('summary.differences', 4);
        $this->assertEqualsCanonicalizing(['physical_ledger', 'reserved_active', 'reserved_ledger', 'balance_version'], array_column($response->json('data.0.differences'), 'code'));
        $this->artisan('bshop:inventory-reconcile', ['--warehouse' => $this->warehouse, '--json' => true])->expectsOutputToContain('"read_only":true')->assertExitCode(1);
        $this->assertDatabaseHas('stock_balances', ['warehouse_id' => $this->warehouse, 'on_hand' => '4.9999', 'reserved' => '1.0000', 'version' => 42]);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_reconciliation_uses_remaining_reservations_after_partial_delivery(): void
    {
        $this->move()->assertCreated();
        $root = "/api/v1/business/stores/{$this->store}";
        $customer = $this->postJson($root.'/customers', ['request_key' => (string) Str::uuid(), 'full_name' => 'Reconciliation QA'])->assertCreated()->json('data.customer.id');
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1.0001']], 'shipping' => '0', 'discount' => '0'];
        $quote = $this->postJson($root.'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $order = $this->postJson($root.'/sales/orders', [...$body, 'quote_hash' => $quote, 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order');
        $this->postJson($root.'/sales/orders/'.$order['id'].'/deliver', ['request_key' => (string) Str::uuid(), 'version' => 0, 'item_id' => $order['items'][0]['id'], 'quantity' => '0.0001'])->assertOk();
        $this->reconciliation('?only_issues=0')->assertOk()->assertJsonPath('data.0.physical', '4.9999')->assertJsonPath('data.0.active_reserved', '1.0000')->assertJsonPath('data.0.reserved', '1.0000')->assertJsonPath('summary.differences', 0);
        // Deliberate corruption in the in-memory fixture: a cancelled order retaining an active reservation.
        DB::table('orders')->where('id', $order['id'])->update(['status' => 'cancelled']);
        $this->reconciliation()->assertOk()->assertJsonPath('data.0.differences.0.code', 'reservation_state');
    }

    public function test_reconciliation_detects_broken_and_incomplete_historical_snapshots(): void
    {
        $this->move()->assertCreated();
        DB::table('inventory_movements')->update(['on_hand_after' => '4.0000']);
        $this->reconciliation()->assertOk()->assertJsonPath('data.0.differences.0.code', 'ledger_chain');
        DB::table('inventory_movements')->update(['on_hand_after' => null, 'reserved_after' => null, 'balance_version' => null]);
        $this->reconciliation()->assertOk()->assertJsonPath('data.0.differences.0.code', 'history_incomplete');
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_reconciliation_paginates_all_rows_while_summary_covers_the_warehouse(): void
    {
        for ($i = 0; $i < 26; $i++) {
            app(ProductService::class)->create(['name' => 'Review '.$i, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'REVIEW-'.$i, 'sale_price' => '1', 'attribute_value_ids' => []]]], $this->owner);
        }
        $this->reconciliation('?only_issues=0')->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('summary.checked', 27)->assertJsonPath('meta.last_page', 2);
        $this->reconciliation('?only_issues=0&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('summary.consistent', 27);
        $this->reconciliation()->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.checked', 27);
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_reconciliation_respects_warehouse_branch_and_read_permissions(): void
    {
        $other = Store::create(['name' => 'Other review', 'code' => 'REVIEW']);
        $warehouse = Warehouse::create(['store_id' => $other->id, 'name' => 'Other']);
        $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$warehouse->id}/reconciliation")->assertNotFound();
        $role = Role::create(['code' => 'no_inventory_review', 'label' => 'No inventory', 'permissions' => ['stores.view']]);
        $user = User::factory()->create();
        $user->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $user->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($user);
        $this->reconciliation()->assertForbidden();
    }

    public function test_reconciliation_command_validates_scope_and_accepts_consistent_history(): void
    {
        $this->move()->assertCreated();
        $this->artisan('bshop:inventory-reconcile', ['--warehouse' => $this->warehouse, '--json' => true])->expectsOutputToContain('"with_differences":0')->assertExitCode(0);
        $this->artisan('bshop:inventory-reconcile', ['--warehouse' => 'no'])->assertExitCode(2);
        $this->artisan('bshop:inventory-reconcile', ['--warehouse' => 999999])->assertExitCode(2);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_reconciliation_keeps_large_decimals_exact_and_transfers_separate_by_warehouse(): void
    {
        $this->move('receipt', '9007199254.0999')->assertCreated();
        $other = Store::create(['name' => 'Transfer review', 'code' => 'REVIEW-T']);
        $target = Warehouse::create(['store_id' => $other->id, 'name' => 'Target']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $this->move('transfer', '0.0001', 1, ['destination_warehouse_id' => $target->id])->assertCreated();
        $this->reconciliation('?only_issues=0')->assertOk()->assertJsonPath('data.0.physical', '9007199254.0998')->assertJsonPath('summary.differences', 0)->assertJsonPath('summary.movements', 2);
        $this->getJson("/api/v1/business/stores/{$other->id}/warehouses/{$target->id}/reconciliation?only_issues=0")
            ->assertOk()->assertJsonPath('data.0.physical', '0.0001')->assertJsonPath('summary.differences', 0)->assertJsonPath('summary.movements', 1);
    }

    public function test_receipt_issue_count_and_kardex_are_exact_and_atomic(): void
    {
        $this->stock()->assertOk()->assertJsonPath('data.0.on_hand', '0.0000')->assertJsonPath('data.0.version', 0)->assertJsonMissingPath('data.0.cost_price');
        $this->move('receipt', '2.0001')->assertCreated()->assertJsonPath('data.movements.0.on_hand_after', '2.0001');
        $this->move('issue', '1', 1)->assertCreated()->assertJsonPath('data.movements.0.on_hand_after', '1.0001');
        $this->move('count', '4', 2)->assertCreated()->assertJsonPath('data.movements.0.on_hand_delta', '2.9999');
        $this->stock()->assertOk()->assertJsonPath('data.0.available', '4.0000')->assertJsonPath('data.0.version', 3);
        $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$this->warehouse}/kardex")->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.on_hand_after', '4.0000');
        $this->assertSame('4.0000', bcadd((string) DB::table('inventory_movements')->sum('on_hand_delta'), '0', 4));
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.count']);
    }

    public function test_retry_returns_original_ids_and_does_not_move_twice(): void
    {
        $key = (string) Str::uuid();
        $first = $this->move(extra: ['request_key' => $key])->assertCreated()->json('data.movements.0.id');
        $this->move(extra: ['request_key' => $key])->assertOk()->assertJsonPath('data.replay', true)->assertJsonPath('data.movements.0.id', $first);
        $this->move(quantity: '6', extra: ['request_key' => $key])->assertConflict();
        $this->getJson("/api/v1/business/stores/{$this->store}/inventory/requests/$key")->assertOk()->assertJsonPath('data.movements.0.on_hand_after', '5.0000');
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('idempotency_requests', 1);
    }

    public function test_invalid_quantities_and_zero_receipt_never_change_stock(): void
    {
        foreach (['-1', '1e3', '1,5', '0.00001', 1.5, '1000000000000000', '0'] as $quantity) {
            $this->move(quantity: $quantity)->assertUnprocessable();
        }
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('stock_balances', 0);
        $this->assertDatabaseCount('idempotency_requests', 0);
    }

    public function test_stale_balance_and_insufficient_available_are_conflicts(): void
    {
        $this->move()->assertCreated();
        $this->move('issue', '1', 0)->assertConflict();
        $this->move('issue', '6', 1)->assertConflict();
        $this->move('count', '5', 1)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->stock()->assertJsonPath('data.0.on_hand', '5.0000');
    }

    public function test_reserved_units_are_protected_from_issue_and_physical_count(): void
    {
        $this->move()->assertCreated();
        // Fixture for a reservation owned by the forthcoming order service, isolated in SQLite memory.
        DB::table('stock_balances')->update(['reserved' => '3.0000']);
        $this->move('issue', '3', 1)->assertConflict();
        $this->move('count', '2', 1)->assertConflict();
        $this->move('issue', '2', 1)->assertCreated();
        $this->stock()->assertJsonPath('data.0.on_hand', '3.0000')->assertJsonPath('data.0.reserved', '3.0000')->assertJsonPath('data.0.available', '0.0000');
    }

    public function test_transfer_records_a_balanced_pair_and_requires_both_branches(): void
    {
        $other = Store::create(['name' => 'Norte', 'code' => 'NORTE']);
        $target = Warehouse::create(['store_id' => $other->id, 'name' => 'Norte']);
        $this->move()->assertCreated();
        $this->move('transfer', '2', 1, ['destination_warehouse_id' => $target->id])->assertNotFound();
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $key = (string) Str::uuid();
        $this->move('transfer', '2', 1, ['destination_warehouse_id' => $target->id, 'request_key' => $key])->assertCreated()->assertJsonCount(2, 'data.movements')->assertJsonPath('data.movements.0.on_hand_after', '3.0000')->assertJsonPath('data.movements.1.on_hand_after', '2.0000');
        $this->move('transfer', '2', 1, ['destination_warehouse_id' => $target->id, 'request_key' => $key])->assertOk();
        $this->assertDatabaseCount('inventory_movements', 3);
        DB::table('store_user')->where('store_id', $other->id)->delete();
        $this->move('transfer', '2', 1, ['destination_warehouse_id' => $target->id, 'request_key' => $key])->assertNotFound();
        $this->getJson("/api/v1/business/stores/{$this->store}/inventory/requests/$key")->assertNotFound();
    }

    public function test_inactive_location_and_untracked_service_cannot_receive_stock(): void
    {
        Warehouse::whereKey($this->warehouse)->update(['is_active' => false, 'is_default' => false]);
        $this->move()->assertConflict();
        Warehouse::whereKey($this->warehouse)->update(['is_active' => true]);
        Product::whereKey(ProductVariant::find($this->variant)->product_id)->update(['product_type' => 'service', 'track_stock' => false]);
        $this->move()->assertUnprocessable();
        $this->assertDatabaseCount('stock_balances', 0);
    }

    public function test_seller_reads_only_authorized_warehouse_and_cannot_mutate(): void
    {
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $this->stock()->assertNotFound();
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        $this->stock()->assertOk();
        $this->move()->assertForbidden();
        $other = Store::create(['name' => 'Oculta', 'code' => 'HIDDEN']);
        $wh = Warehouse::create(['store_id' => $other->id, 'name' => 'Oculto']);
        $this->getJson("/api/v1/business/stores/{$this->store}/warehouses/{$wh->id}/stock")->assertNotFound();
    }

    public function test_catalog_type_changes_conflict_after_stock_movement(): void
    {
        $product = Product::find(ProductVariant::find($this->variant)->product_id);
        $this->move()->assertCreated();
        $this->assertSame(1, $product->fresh()->lock_version);
        $this->patchJson('/api/v1/business/products/'.$product->id, ['name' => $product->name, 'product_type' => 'service', 'track_stock' => false, 'unit_code' => 'unit', 'status' => 'active', 'version' => 1])->assertConflict();
    }

    public function test_kardex_cannot_be_rewritten_through_model(): void
    {
        $this->move()->assertCreated();
        $movement = InventoryMovement::firstOrFail();
        $this->expectException(\LogicException::class);
        $movement->update(['on_hand_delta' => '500']);
    }
}
