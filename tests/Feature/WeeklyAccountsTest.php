<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\OrderInvoice;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\WeeklyAccounts;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeeklyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WeeklyAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $customer;

    private int $variant;

    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Weekly QA', 'default_currency' => 'USD', 'timezone' => 'America/Santo_Domingo', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create(['full_name' => 'Original Weekly Customer'])->id;
        $this->actingAs($this->owner);
        $product = app(ProductService::class)->create(['name' => 'Weekly bag', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'WEEKLY-QA', 'sale_price' => '25', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $product->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '20', 'version' => 0, 'note' => 'Weekly fixture'])->assertCreated();
        $data = ['warehouse_id' => $warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $quote = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');
        foreach (['5', '0'] as $paid) {
            $this->orders[] = $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $quote, 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => $paid, 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        }
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function createAccount(?string $key = null, string $start = '2026-11-09')
    {
        return $this->postJson($this->root().'/weekly-accounts', ['request_key' => $key ?? (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => $start]);
    }

    private function attach(int $account, int $order, int $version)
    {
        return $this->postJson($this->root()."/weekly-accounts/$account/orders/attach", ['request_key' => (string) Str::uuid(), 'order_id' => $order, 'version' => $version]);
    }

    private function review(int $account): array
    {
        return $this->getJson($this->root()."/weekly-accounts/$account")->assertOk()->json('data');
    }

    private function closeAccount(int $id, array $review, ?string $key = null)
    {
        return $this->postJson($this->root()."/weekly-accounts/$id/close", ['request_key' => $key ?? (string) Str::uuid(), 'version' => $review['lock_version'], 'review_hash' => $review['review_hash']]);
    }

    private function grouped(): int
    {
        $id = $this->createAccount()->assertCreated()->json('data.account.id');
        foreach ($this->orders as $index => $order) {
            $this->attach($id, $order, $index)->assertOk();
        }

        return $id;
    }

    public function test_weekly_cutoff_is_configurable_audited_and_does_not_change_existing_accounts(): void
    {
        $account = $this->createAccount()->assertCreated()->json('data.account.id');
        $path = '/api/v1/business/weekly/settings';
        $this->getJson($path)->assertOk()->assertJsonPath('data.close_weekday', 4);
        $this->patchJson($path, ['close_weekday' => 6, 'previous_weekday' => 4])->assertOk()->assertJsonPath('data.close_weekday', 6);
        $this->patchJson($path, ['close_weekday' => 7, 'previous_weekday' => 4])->assertConflict();
        $this->patchJson($path, ['close_weekday' => 8, 'previous_weekday' => 6])->assertUnprocessable();
        $this->assertDatabaseHas('weekly_accounts', ['id' => $account, 'period_start' => '2026-11-09', 'period_end' => '2026-11-15']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'weekly.policy.updated', 'user_id' => $this->owner->id]);
        $this->actingAs(User::factory()->create());
        $this->patchJson($path, ['close_weekday' => 7, 'previous_weekday' => 6])->assertForbidden();
        $this->assertSame(6, (int) DB::table('business_settings')->where('id', 1)->value('weekly_close_weekday'));
    }

    public function test_cutoff_notice_uses_account_local_date_and_does_not_close_or_invoice(): void
    {
        $account = $this->createAccount()->assertCreated()->json('data.account.id');
        $otherCustomer = Customer::factory()->create();
        $other = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $otherCustomer->id, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
        WeeklyAccount::whereKey($other)->update(['timezone' => 'America/New_York']);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T03:59:59Z'));
        $this->getJson($this->root().'/weekly-accounts?status=due')->assertOk()->assertJsonPath('meta.due_count', 0)->assertJsonCount(0, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-11-15T04:00:00Z'));
        $this->getJson($this->root().'/weekly-accounts?status=due')->assertOk()->assertJsonPath('meta.due_count', 1)->assertJsonPath('data.0.id', $account);
        $this->travelTo(CarbonImmutable::parse('2026-11-15T05:00:00Z'));
        $this->getJson($this->root().'/weekly-accounts?status=due')->assertOk()->assertJsonPath('meta.due_count', 2)->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('weekly_accounts', ['id' => $account, 'status' => 'open']);
        $this->assertDatabaseCount('invoices', 0);
        $this->postJson($this->root()."/weekly-accounts/$account/cancel", ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        $this->getJson($this->root().'/weekly-accounts?status=due')->assertOk()->assertJsonPath('meta.due_count', 1)->assertJsonPath('data.0.id', $other);
        $this->postJson($this->root()."/weekly-accounts/$other/cancel", ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk();
        $this->getJson($this->root().'/weekly-accounts?status=due')->assertOk()->assertJsonPath('meta.due_count', 0)->assertJsonCount(0, 'data');
    }

    public function test_adjustment_compensation_reopens_the_shared_invoice_hold_and_preserves_its_original_pdf(): void
    {
        $id = $this->grouped();
        $this->closeAccount($id, $this->review($id))->assertOk();
        $order = $this->orders[0];
        $this->postJson($this->root()."/sales/orders/$order/deliver", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version])->assertOk();
        $path = $this->root()."/sales/orders/$order/returns";
        $r = $this->getJson($path)->json('data');
        $receipt = $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Revisión de devolución semanal', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
        $r = $this->getJson($path."/$receipt/financial")->json('data.proposal');
        $a = $this->postJson($path."/$receipt/adjust", ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'shipping_credit' => '0', 'reason' => 'Ajuste semanal inicial'])->assertOk()->json('data.adjustment_id');
        $doc = DB::table('invoice_documents')->first();
        $bytes = Storage::disk('private_invoices')->get($doc->object_key);
        $r = $this->getJson($path."/$receipt/adjustments/$a/reversal")->assertOk()->json('data');
        $this->postJson($path."/$receipt/adjustments/$a/reversal", ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Corregir ajuste económico semanal'])->assertOk();
        $other = $this->orders[1];
        $this->getJson($this->root()."/sales/orders/$other")->assertOk()->assertJsonPath('data.return_pending', true);
        $invoice = DB::table('invoices')->first();
        $this->assertSame('50.0000', OrderSettlement::invoice($invoice)['net_total']);
        $this->assertSame('5.0000', OrderSettlement::invoice($invoice)['paid_total']);
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($doc->object_key));
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_sales_control_counts_a_shared_weekly_invoice_once_and_each_order_amount_once(): void
    {
        $id = $this->grouped();
        $this->closeAccount($id, $this->review($id))->assertOk();
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('summary.0.orders', 2)->assertJsonPath('summary.0.invoices', 1)->assertJsonPath('summary.0.sales_net', '50.0000')->assertJsonPath('summary.0.paid_total', '5.0000')->assertJsonPath('summary.0.pending_total', '45.0000')->assertJsonPath('cash.0.confirmed', '5.0000');
    }

    public function test_receipt_compensation_removes_the_shared_invoice_hold_without_modifying_its_money(): void
    {
        $id = $this->grouped();
        $this->closeAccount($id, $this->review($id))->assertOk();
        $order = $this->orders[0];
        $this->postJson($this->root()."/sales/orders/$order/deliver", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version])->assertOk();
        $path = $this->root()."/sales/orders/$order/returns";
        $r = $this->getJson($path)->json('data');
        $receipt = $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Recepción errónea en cuenta semanal', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
        $other = $this->orders[1];
        $this->getJson($this->root()."/sales/orders/$other")->assertOk()->assertJsonPath('data.return_pending', true);
        $invoice = DB::table('invoices')->first();
        $r = $this->getJson($path."/$receipt/reversal")->json('data');
        $this->postJson($path."/$receipt/reversal", ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Anular registro físico por error'])->assertOk();
        $this->getJson($this->root()."/sales/orders/$other")->assertOk()->assertJsonPath('data.return_pending', false);
        $this->assertSame((array) $invoice, (array) DB::table('invoices')->first());
    }

    public function test_close_creates_one_aggregate_invoice_and_allocates_pre_and_post_close_deposits_once(): void
    {
        $id = $this->grouped();
        $review = $this->review($id);
        $stock = DB::table('stock_balances')->get()->toJson();
        $key = (string) Str::uuid();
        $this->assertSame('50.0000', $review['totals']['grand_total']);
        $this->assertSame('5.0000', $review['totals']['paid_total']);
        $result = $this->closeAccount($id, $review, $key)->assertOk()->assertJsonPath('data.account.status', 'closed')->assertJsonPath('data.account.invoice.status', 'partially_paid')->json('data');
        $invoice = $result['account']['invoice']['id'];
        $this->closeAccount($id, $review, $key)->assertOk()->assertJsonPath('data.replay', true);
        $this->closeAccount($id, $this->review($id))->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_orders', 2);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        foreach ([$this->orders[1] => '25', $this->orders[0] => '20'] as $order => $amount) {
            $this->postJson($this->root()."/sales/orders/$order/payment", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version, 'amount' => $amount, 'payment_method' => 'cash'])->assertOk();
            $status = DB::table('invoices')->where('id', $invoice)->value('status');
            $this->assertSame($amount === '25' ? 'partially_paid' : 'paid', $status);
        }
        $this->assertDatabaseCount('payment_allocations', 3);
        $this->assertDatabaseHas('weekly_accounts', ['id' => $id, 'paid_at_close' => '5']);
        $this->assertSame('0.0000', $this->review($id)['totals']['pending_total']);
        $this->getJson($this->root().'/sales/orders/'.$this->orders[0])->assertOk()->assertJsonPath('data.invoice.id', $invoice);
    }

    public function test_pdf_snapshot_is_stable_and_closed_members_cannot_be_refactored_or_cancelled(): void
    {
        $id = $this->grouped();
        $result = $this->closeAccount($id, $this->review($id))->assertOk()->assertJsonPath('data.document.status', 'ready')->json('data');
        $invoice = $result['account']['invoice']['id'];
        $document = DB::table('invoice_documents')->where('invoice_id', $invoice)->first();
        $bytes = Storage::disk('private_invoices')->get($document->object_key);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame('Original Weekly Customer', OrderInvoice::customer(DB::table('invoices')->find($invoice))['full_name']);
        Customer::whereKey($this->customer)->update(['full_name' => 'Later Customer Name']);
        $this->postJson($this->root()."/invoices/$invoice/document")->assertOk();
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($document->object_key));
        $this->get($this->root()."/invoices/$invoice/document")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $order = SalesOrder::find($this->orders[1]);
        $this->postJson($this->root()."/sales/orders/$order->id/invoice", ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version])->assertOk()->assertJsonPath('data.order.invoice.id', $invoice);
        $this->postJson($this->root()."/sales/orders/$order->id/cancel", ['request_key' => (string) Str::uuid(), 'version' => $order->fresh()->lock_version])->assertConflict();
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_individually_invoiced_member_is_excluded_and_cxc_never_double_counts(): void
    {
        $id = $this->grouped();
        $order = SalesOrder::find($this->orders[0]);
        $this->postJson($this->root()."/sales/orders/$order->id/invoice", ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version])->assertOk();
        $review = $this->review($id);
        $this->assertFalse($review['orders'][0]['included']);
        $this->assertSame('25.0000', $review['totals']['grand_total']);
        $this->closeAccount($id, $review)->assertOk();
        $this->assertDatabaseCount('invoices', 2);
        $this->assertDatabaseCount('invoice_items', 2);
        $this->getJson($this->root().'/receivables?type=invoiced')->assertOk()->assertJsonPath('summary.0.pending', '45.0000')->assertJsonPath('summary.0.documents', 2)->assertJsonCount(2, 'data');
        $this->getJson($this->root().'/baskets?filter=all')->assertOk()->assertJsonPath('data.0.invoice_generated', true);
    }

    public function test_aggregate_cxc_uses_one_document_and_stale_review_changes_nothing(): void
    {
        $id = $this->grouped();
        $review = $this->review($id);
        $order = SalesOrder::find($this->orders[0]);
        $this->postJson($this->root()."/sales/orders/$order->id/payment", ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version, 'amount' => '1', 'payment_method' => 'cash'])->assertOk();
        $this->closeAccount($id, $review)->assertConflict();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseHas('weekly_accounts', ['id' => $id, 'status' => 'open']);
        $this->closeAccount($id, $this->review($id))->assertOk();
        $this->getJson($this->root().'/receivables?type=invoiced')->assertOk()->assertJsonPath('summary.0.pending', '44.0000')->assertJsonPath('summary.0.documents', 1)->assertJsonCount(2, 'data');
        $this->getJson($this->root().'/receivables?type=unbilled')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_membership_versions_period_currency_and_overlap_are_enforced(): void
    {
        $this->createAccount(start: '0999-12-31')->assertUnprocessable();
        $this->createAccount(start: '9999-12-26')->assertUnprocessable();
        $key = (string) Str::uuid();
        $id = $this->createAccount($key)->assertCreated()->assertJsonPath('data.account.period_end', '2026-11-15')->json('data.account.id');
        $this->createAccount($key)->assertOk()->assertJsonPath('data.replay', true);
        $this->createAccount(start: '2026-11-12')->assertConflict();
        $this->attach($id, $this->orders[0], 0)->assertOk();
        $this->attach($id, $this->orders[1], 0)->assertConflict();
        SalesOrder::whereKey($this->orders[1])->update(['currency' => 'EUR']);
        $this->attach($id, $this->orders[1], 1)->assertConflict();
        $this->postJson($this->root()."/weekly-accounts/$id/orders/detach", ['request_key' => (string) Str::uuid(), 'version' => 1, 'order_id' => $this->orders[0]])->assertOk();
        $this->closeAccount($id, $this->review($id))->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertNull(SalesOrder::find($this->orders[0])->weekly_account_id);
    }

    public function test_local_week_bounds_exclude_the_previous_midnight_and_include_last_day(): void
    {
        SalesOrder::whereKey($this->orders[0])->update(['created_at' => '2026-11-09 03:59:59']);
        SalesOrder::whereKey($this->orders[1])->update(['created_at' => '2026-11-16 03:59:59']);
        $id = $this->createAccount()->assertCreated()->json('data.account.id');
        $this->getJson($this->root()."/weekly-accounts/$id/candidates")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->orders[1]);
        $this->attach($id, $this->orders[0], 0)->assertConflict();
        $this->attach($id, $this->orders[1], 0)->assertOk();
    }

    public function test_other_branch_and_guest_cannot_access_accounts(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.account.id');
        $other = Store::create(['name' => 'Other', 'code' => 'WEEKLY-OTHER']);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/weekly-accounts/'.$id)->assertNotFound();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson($this->root()."/weekly-accounts/$id")->assertUnauthorized();
    }

    public function test_empty_account_can_be_cancelled_without_deleting_history_and_period_reused(): void
    {
        $id = $this->createAccount()->assertCreated()->json('data.account.id');
        $this->postJson($this->root()."/weekly-accounts/$id/cancel", ['request_key' => (string) Str::uuid(), 'version' => 0])->assertOk()->assertJsonPath('data.account.status', 'cancelled');
        $next = $this->createAccount()->assertCreated()->json('data.account.id');
        $this->assertSame($id, $next);
        $version = $this->review($next)['lock_version'];
        $this->attach($next, $this->orders[0], $version)->assertOk();
        $this->postJson($this->root()."/weekly-accounts/$next/cancel", ['request_key' => (string) Str::uuid(), 'version' => $version + 1])->assertConflict();
        $this->assertDatabaseCount('weekly_accounts', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'weekly.reopened')->count());
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_recovery_is_actor_bound_and_readers_cannot_mutate_accounts(): void
    {
        $key = (string) Str::uuid();
        $id = $this->createAccount($key)->assertCreated()->json('data.account.id');
        $this->getJson($this->root().'/weekly-accounts/requests/'.strtoupper($key).'?operation=create')->assertOk()->assertJsonPath('data.account.id', $id);
        $role = Role::create(['code' => 'weekly_reader', 'label' => 'Reader', 'permissions' => ['stores.view', 'sales.view', 'customers.view']]);
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $reader->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($this->root()."/weekly-accounts/$id")->assertOk();
        $this->getJson($this->root().'/weekly-accounts/requests/'.$key.'?operation=create')->assertNotFound();
        $this->createAccount(start: '2026-11-16')->assertForbidden();
        $this->attach($id, $this->orders[0], 0)->assertForbidden();
        $this->closeAccount($id, $this->review($id))->assertForbidden();
    }

    public function test_week_retains_its_timezone_across_dst_and_business_timezone_changes(): void
    {
        BusinessProfile::whereKey(1)->update(['timezone' => 'America/New_York']);
        $id = $this->createAccount(start: '2026-11-01')->assertCreated()->json('data.account.id');
        BusinessProfile::whereKey(1)->update(['timezone' => 'UTC']);
        [$start, $end] = app(WeeklyAccounts::class)->bounds(WeeklyAccount::findOrFail($id));
        $this->assertSame('2026-11-01 04:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-08 05:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_aggregate_over_decimal_limit_rolls_back_before_invoice_or_sequence(): void
    {
        $id = $this->grouped();
        SalesOrder::whereIn('id', $this->orders)->update(['subtotal' => '600000000000000', 'grand_total' => '600000000000000']);
        $this->closeAccount($id, $this->review($id))->assertUnprocessable();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('document_sequences', 0);
        $this->assertDatabaseHas('weekly_accounts', ['id' => $id, 'status' => 'open']);
    }
}
