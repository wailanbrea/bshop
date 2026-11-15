<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesControlTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $variant;

    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Control QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->customer = Customer::factory()->create(['full_name' => 'Historical Customer'])->id;
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Control service', 'product_type' => 'service', 'track_stock' => false, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CONTROL', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $p->variants()->first()->id;
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function create(string $mode = 'reservation', string $paid = '0'): array
    {
        $data = ['warehouse_id' => Warehouse::first()->id, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');

        return $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => $mode, 'initial_payment' => $paid, 'payment_method' => 'cash'])->assertCreated()->json('data.order');
    }

    public function test_control_preserves_history_and_excludes_cancelled_and_draft_money_without_writing(): void
    {
        $sale = $this->create('sale', '10');
        $this->create(paid: '3');
        $cancelled = $this->create();
        $this->postJson($this->root().'/sales/orders/'.$cancelled['id'].'/cancel', ['request_key' => (string) Str::uuid(), 'version' => $cancelled['lock_version']])->assertOk();
        $draft = $this->create();
        SalesOrder::whereKey($draft['id'])->update(['status' => 'draft']);
        $before = [];
        foreach (['orders', 'payments', 'payment_allocations', 'inventory_movements', 'customer_credit_movements'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('summary.0.orders', 4)->assertJsonPath('summary.0.invoices', 1)->assertJsonPath('summary.0.cancelled', 1)->assertJsonPath('summary.0.draft', 1)->assertJsonPath('summary.0.net_total', '20.0000')->assertJsonPath('summary.0.sales_net', '10.0000')->assertJsonPath('summary.0.unbilled_net', '10.0000')->assertJsonPath('summary.0.paid_total', '13.0000')->assertJsonPath('summary.0.pending_total', '7.0000')->assertJsonPath('cash.0.confirmed', '13.0000')->assertJsonPath('daily.0.net_total', '20.0000');
        $this->getJson($this->root().'/sales/control?state=invoiced')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sale['id']);
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson());
        }
    }

    public function test_payment_period_is_separate_from_the_order_creation_cohort_and_search(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31T12:00:00Z'));
        $old = $this->create(paid: '2');
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        $this->postJson($this->root().'/sales/orders/'.$old['id'].'/payment', ['request_key' => (string) Str::uuid(), 'version' => $old['lock_version'], 'amount' => '3', 'payment_method' => 'bank_transfer'])->assertOk();
        $this->getJson($this->root().'/sales/control?from=2026-11-12&to=2026-11-12&q=No-match')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('summary.0.net_total', '0.0000')->assertJsonPath('cash.0.confirmed', '3.0000')->assertJsonPath('cash.0.methods.0.method', 'bank_transfer');
    }

    public function test_local_day_boundaries_include_the_25_hour_dst_day_exactly(): void
    {
        BusinessProfile::whereKey(1)->update(['timezone' => 'America/New_York']);
        foreach (['2026-11-01T03:59:59Z', '2026-11-01T04:00:00Z', '2026-11-02T04:59:59Z', '2026-11-02T05:00:00Z'] as $instant) {
            $this->travelTo(CarbonImmutable::parse($instant));
            $this->create(paid: '1');
        }
        $this->getJson($this->root().'/sales/control?from=2026-11-01&to=2026-11-01')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.0.net_total', '20.0000')->assertJsonPath('cash.0.confirmed', '2.0000')->assertJsonPath('daily.0.date', '2026-11-01')->assertJsonPath('filters.timezone', 'America/New_York');
    }

    public function test_reversed_receipts_are_visible_but_not_counted_as_confirmed_cash(): void
    {
        $order = $this->create(paid: '5');
        $payment = DB::table('payments')->first()->id;
        $this->postJson($this->root().'/sales/orders/'.$order['id'].'/payments/'.$payment.'/reverse', ['request_key' => (string) Str::uuid(), 'version' => $order['lock_version'], 'reason' => 'Wrong payment recorded'])->assertOk();
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('cash.0.confirmed', '0.0000')->assertJsonPath('cash.0.reversed', '5.0000')->assertJsonPath('summary.0.pending_total', '10.0000');
        $this->getJson($this->root().'/sales/orders/'.$order['id'].'/activity')->assertOk()->assertJsonPath('data.payments.0.status', 'reversed')->assertJsonPath('data.payments.0.amount', '5.0000')->assertJsonPath('data.payments.0.reversal_reason', 'Wrong payment recorded');
    }

    public function test_pagination_full_filtered_export_and_historical_customer_names(): void
    {
        Customer::whereKey($this->customer)->update(['full_name' => '=SUM(1+1)']);
        for ($i = 0; $i < 22; $i++) {
            $this->create();
        }
        Customer::whereKey($this->customer)->update(['full_name' => 'Live renamed customer']);
        $this->getJson($this->root().'/sales/control?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 22)->assertJsonPath('summary.0.net_total', '220.0000')->assertJsonPath('data.0.customer_name', '=SUM(1+1)');
        $this->getJson($this->root().'/sales/control?q=Live%20renamed&kind=reservation&state=active')->assertOk()->assertJsonPath('meta.total', 22);
        $csv = $this->get($this->root().'/sales/control/export?page=2')->assertOk()->streamedContent();
        $this->assertSame(22, substr_count($csv, "'=SUM(1+1)"));
        $this->getJson($this->root().'/sales/control?kind=sale')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_report_rejects_bad_ranges_and_keeps_currencies_separate(): void
    {
        $this->create();
        BusinessProfile::whereKey(1)->update(['default_currency' => 'EUR']);
        $this->create();
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonCount(2, 'summary');
        foreach (['from=2026-11-20&to=2026-11-01', 'from=2020-01-01&to=2026-11-01', 'from=not-a-date', 'state=unknown', 'page=0'] as $query) {
            $this->getJson($this->root().'/sales/control?'.$query)->assertUnprocessable();
        }
    }

    public function test_sales_permission_and_explicit_store_context_protect_report_export_and_activity(): void
    {
        $order = $this->create();
        $other = Store::create(['name' => 'Other', 'code' => 'CONTROL-OTHER']);
        DB::table('store_user')->insert(['store_id' => $other->id, 'user_id' => $this->owner->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/sales/control')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/sales/orders/'.$order['id'].'/activity')->assertNotFound();
        $reader = User::factory()->create();
        $role = Role::create(['code' => 'no_sales_control', 'label' => 'No sales', 'permissions' => ['stores.view']]);
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['store_id' => $this->store, 'user_id' => $reader->id]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        foreach (['/sales/control', '/sales/control/export', '/sales/orders/'.$order['id'].'/activity'] as $path) {
            $this->getJson($this->root().$path)->assertForbidden();
        }
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson($this->root().'/sales/control')->assertUnauthorized();
    }
}
