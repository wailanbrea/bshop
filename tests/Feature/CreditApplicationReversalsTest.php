<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\FinancialDocument;
use App\Domain\Sales\OrderSettlement;
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

class CreditApplicationReversalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $variant;

    private int $customer;

    private int $source;

    private int $target;

    private int $payment;

    private int $application;

    private int $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-14T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Credit reversal QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->customer = Customer::factory()->create()->id;
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Reversible item', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CREDIT-UNDO', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => Warehouse::first()->id, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '20', 'version' => 0, 'note' => 'Isolated reversal fixture'])->assertCreated();
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function path(?int $order = null, ?int $application = null): string
    {
        return $this->root().'/sales/orders/'.($order ?? $this->target).'/credits/'.($application ?? $this->application).'/reversal';
    }

    private function fixture(bool $weekly = false): void
    {
        $orders = [];
        foreach (['3', '1'] as $qty) {
            $data = ['warehouse_id' => Warehouse::first()->id, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => $qty]], 'shipping' => '0', 'discount' => '0'];
            $hash = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');
            $orders[] = $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => $weekly ? 'reservation' : 'sale', 'initial_payment' => $qty === '3' ? '30' : '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        }
        [$this->source,$this->target] = $orders;
        $this->payment = DB::table('order_payments')->where('order_id', $this->source)->value('payment_id');
        if ($weekly) {
            $id = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
            foreach ($orders as $version => $order) {
                $this->postJson($this->root()."/weekly-accounts/$id/orders/attach", ['request_key' => (string) Str::uuid(), 'order_id' => $order, 'version' => $version])->assertOk();
            }$r = $this->getJson($this->root()."/weekly-accounts/$id")->json('data');
            $this->postJson($this->root()."/weekly-accounts/$id/close", ['request_key' => (string) Str::uuid(), 'version' => $r['lock_version'], 'review_hash' => $r['review_hash']])->assertOk();
            foreach ($orders as $order) {
                $this->postJson($this->root()."/sales/orders/$order/deliver", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version])->assertOk();
            }
        }
        $this->receipt = $this->returnReceipt($this->source);
        $this->adjust($this->source, $this->receipt);
        $this->application = $this->apply();
    }

    private function returnReceipt(int $order): int
    {
        $path = $this->root()."/sales/orders/$order/returns";
        $r = $this->getJson($path)->assertOk()->json('data');

        return $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Return for available credit', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
    }

    private function adjust(int $order, int $receipt): void
    {
        $path = $this->root()."/sales/orders/$order/returns/$receipt";
        $r = $this->getJson($path.'/financial')->assertOk()->json('data.proposal');
        $this->postJson($path.'/adjust', ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'shipping_credit' => '0', 'reason' => 'Approve original return value'])->assertOk();
    }

    private function apply(): int
    {
        $path = $this->root().'/sales/orders/'.$this->target.'/credits';
        $r = $this->getJson($path)->assertOk()->json('data');

        return $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'payment_id' => $this->payment, 'amount' => '5', 'reason' => 'Credit applied to this order'])->assertOk()->json('data.credit_application_id');
    }

    private function payload(): array
    {
        $r = $this->getJson($this->path())->assertOk()->json('data');

        return ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Correct mistakenly applied credit'];
    }

    public function test_compensation_restores_source_and_target_without_rewriting_ledger_stock_or_pdf(): void
    {
        $this->fixture();
        $doc = app(FinancialDocument::class)->ensure('credit', $this->application);
        $bytes = Storage::disk('private_invoices')->get($doc->object_key);
        $before = [];
        foreach (['payments', 'payment_allocations', 'customer_credit_movements', 'inventory_movements', 'credit_applications', 'credit_application_sources', 'return_adjustments', 'financial_documents'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $payload = $this->payload();
        $this->postJson($this->path(), $payload)->assertOk()->assertJsonPath('data.order.paid_total', '0.0000')->assertJsonPath('data.order.pending_total', '10.0000');
        $this->postJson($this->path(), $payload)->assertOk()->assertJsonPath('data.replay', true);
        $this->getJson($this->root().'/sales/orders/'.$this->source)->assertOk()->assertJsonPath('data.credit_available', '10.0000')->assertJsonPath('data.credit_applied_total', '0.0000');
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }$this->assertSame($bytes, Storage::disk('private_invoices')->get($doc->object_key));
        $this->assertDatabaseCount('credit_application_reversals', 1);
        $this->getJson($this->path().'/requests/'.$payload['request_key'])->assertOk()->assertJsonPath('data.replay', true);
        $this->postJson($this->path(), $this->payload())->assertConflict();
        $fund = OrderSettlement::payment($this->payment);
        $this->assertSame('20.0000', $fund['effective_allocated']);
        $this->assertSame('10.0000', $fund['available']);
        $this->apply();
        $this->assertSame('5.0000', OrderSettlement::payment($this->payment)['available']);
    }

    public function test_restored_credit_can_be_refunded_once_and_reports_do_not_count_cancelled_applications(): void
    {
        $this->fixture();
        $this->postJson($this->path(), $this->payload())->assertOk();
        $this->postJson($this->root().'/sales/orders/'.$this->source.'/returns/'.$this->receipt.'/refund', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->source)->lock_version, 'payment_id' => $this->payment, 'amount' => '10', 'method' => 'cash', 'external_reference' => null, 'reason' => 'Cash returned after correction'])->assertOk();
        $this->assertSame('0.0000', OrderSettlement::payment($this->payment)['available']);
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('summary.0.paid_total', '20.0000')->assertJsonPath('summary.0.pending_total', '10.0000')->assertJsonPath('cash.0.confirmed', '30.0000')->assertJsonPath('cash.0.refunded', '10.0000');
        $this->getJson($this->root().'/sales/orders/'.$this->target.'/activity')->assertOk()->assertJsonPath('data.credits.0.reversal.reason', 'Correct mistakenly applied credit');
    }

    public function test_reversal_updates_one_shared_weekly_invoice_and_preserves_order_attribution(): void
    {
        $this->fixture(true);
        $this->postJson($this->path(), $this->payload())->assertOk();
        $invoice = DB::table('invoices')->first();
        $this->assertSame('20.0000', OrderSettlement::invoice($invoice)['paid_total']);
        $this->assertSame('10.0000', OrderSettlement::invoice($invoice)['pending_total']);
        $this->getJson($this->root().'/sales/orders/'.$this->source)->assertOk()->assertJsonPath('data.credit_available', '10.0000');
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_consumed_or_pending_return_blocks_reversal_without_mutation(): void
    {
        $this->fixture();
        $r = $this->returnReceipt($this->target);
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.allowed', false);
        $this->postJson($this->path(), $this->payload())->assertConflict();
        $this->adjust($this->target, $r);
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.released', true);
        $this->postJson($this->path(), $this->payload())->assertConflict();
        $this->assertDatabaseCount('credit_application_reversals', 0);
    }

    public function test_stale_review_and_wrong_scope_are_rejected(): void
    {
        $this->fixture();
        $payload = $this->payload();
        $this->apply();
        $this->postJson($this->path(), $payload)->assertConflict();
        $this->getJson($this->path($this->source))->assertNotFound();
        $other = Store::create(['name' => 'Other', 'code' => 'REV-OTHER']);
        DB::table('store_user')->insert(['store_id' => $other->id, 'user_id' => $this->owner->id]);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/sales/orders/'.$this->target.'/credits/'.$this->application.'/reversal')->assertNotFound();
        $this->assertDatabaseCount('credit_application_reversals', 0);
    }

    public function test_chained_credit_restores_the_actual_release_owner_instead_of_the_original_cash_order(): void
    {
        $this->fixture();
        $second = $this->target;
        $receipt = $this->returnReceipt($second);
        $this->adjust($second, $receipt);
        $data = ['warehouse_id' => Warehouse::first()->id, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $data)->json('data.quote_hash');
        $this->target = $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => 'sale', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        // Consume the older A release first, then the release owned by B.
        $this->apply();
        $this->application = $this->apply();
        $this->postJson($this->path(), $this->payload())->assertOk();
        $this->getJson($this->root().'/sales/orders/'.$second)->assertOk()->assertJsonPath('data.credit_available', '5.0000')->assertJsonPath('data.credit_applied_total', '0.0000');
        $this->getJson($this->root().'/sales/orders/'.$this->source)->assertOk()->assertJsonPath('data.credit_available', '0.0000');
        $this->assertSame('5.0000', OrderSettlement::payment($this->payment)['available']);
    }

    public function test_pending_refund_excludes_the_fund_from_compensation(): void
    {
        $this->fixture();
        $invoice = DB::table('sales_return_receipts')->where('sales_return_id', $this->receipt)->value('invoice_id');
        DB::table('refunds')->insert(['store_id' => $this->store, 'customer_id' => $this->customer, 'sales_return_id' => $this->receipt, 'original_payment_id' => $this->payment, 'invoice_id' => $invoice, 'refund_kind' => 'return', 'status' => 'pending', 'amount' => '1', 'currency' => 'USD', 'method' => 'cash', 'event_key' => 'pending-reversal-fixture', 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson($this->path())->assertOk()->assertJsonPath('data.refund_pending', true)->assertJsonPath('data.allowed', false);
        $this->postJson($this->path(), $this->payload())->assertConflict();
        $this->assertDatabaseCount('credit_application_reversals', 0);
    }

    public function test_permission_and_recovery_actor_are_revalidated(): void
    {
        $this->fixture();
        $payload = $this->payload();
        $this->postJson($this->path(), $payload)->assertOk();
        $reader = User::factory()->create();
        $role = Role::create(['code' => 'credit_readonly', 'label' => 'Reader', 'permissions' => ['stores.view', 'sales.view']]);
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['store_id' => $this->store, 'user_id' => $reader->id]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($this->path())->assertOk();
        $this->postJson($this->path(), $payload)->assertForbidden();
        $this->getJson($this->path().'/requests/'.$payload['request_key'])->assertNotFound();
    }

    public function test_csrf_is_required_without_the_test_bypass(): void
    {
        $this->fixture();
        $payload = $this->payload();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->path(), $payload)->assertStatus(419);
        $this->assertDatabaseCount('credit_application_reversals',0);
    }
}
