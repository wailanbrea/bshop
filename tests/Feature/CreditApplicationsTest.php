<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\OrderSettlement;
use App\Models\BusinessProfile;
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

class CreditApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $customer;

    private int $variant;

    private int $source;

    private int $target;

    private int $payment;

    private int $receipt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Credit application QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create()->id;
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Credit bag', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'APPLY-QA', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '100', 'version' => 0, 'note' => 'Credit application fixture'])->assertCreated();
        $this->source = $this->order('3');
        $this->target = $this->order('2');
        $this->payment = $this->fund($this->source, '30');
        $this->receipt = $this->returned($this->source, '2');
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function order(string $qty, string $mode = 'sale'): int
    {
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => $qty]], 'discount' => '0', 'shipping' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');

        return $this->postJson($this->root().'/sales/orders', [...$body, 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash, 'mode' => $mode, 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
    }

    private function fund(int $order, string $amount): int
    {
        $this->postJson($this->root()."/sales/orders/$order/payment", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version, 'amount' => $amount, 'payment_method' => 'cash'])->assertOk();

        return DB::table('order_payments')->where('order_id', $order)->orderByDesc('payment_id')->value('payment_id');
    }

    private function returned(int $order, string $qty): int
    {
        $path = $this->root()."/sales/orders/$order/returns";
        $r = $this->getJson($path)->assertOk()->json('data');
        $receipt = $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Mercancía devuelta para crédito', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => $qty, 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
        $f = $this->getJson($path."/$receipt/financial")->assertOk()->json('data.proposal');
        $this->postJson($path."/$receipt/adjust", ['request_key' => (string) Str::uuid(), 'version' => $f['version'], 'review_hash' => $f['review_hash'], 'shipping_credit' => '0', 'reason' => 'Crédito de devolución aprobado'])->assertOk();

        return $receipt;
    }

    private function preview(?int $target = null): array
    {
        return $this->getJson($this->root().'/sales/orders/'.($target ?? $this->target).'/credits')->assertOk()->json('data');
    }

    private function apply(string $amount, array $review, ?string $key = null)
    {
        return $this->postJson($this->root().'/sales/orders/'.$review['order_id'].'/credits', ['request_key' => $key ?? (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'payment_id' => $this->payment, 'amount' => $amount, 'reason' => 'Aplicación de saldo autorizado']);
    }

    private function refund(int $order, int $receipt, string $amount)
    {
        return $this->postJson($this->root()."/sales/orders/$order/returns/$receipt/refund", ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($order)->lock_version, 'payment_id' => $this->payment, 'amount' => $amount, 'method' => 'cash', 'reason' => 'Devolución manual del saldo disponible']);
    }

    public function test_apply_is_once_and_moves_credit_without_new_cash_stock_or_invoice_changes(): void
    {
        $review = $this->preview();
        $key = (string) Str::uuid();
        $stock = DB::table('stock_balances')->get()->toJson();
        $p = (array) DB::table('payments')->find($this->payment);
        $docs = DB::table('invoice_documents')->pluck('sha256')->all();
        $this->apply('5', $review, $key)->assertOk()->assertJsonPath('data.order.paid_total', '5.0000')->assertJsonPath('data.order.pending_total', '15.0000')->assertJsonPath('data.order.gross_received', '0.0000')->assertJsonPath('data.order.credit_received_total', '5.0000');
        $this->apply('5', $review, strtoupper($key))->assertOk()->assertJsonPath('data.replay', true);
        $this->apply('6', $review, $key)->assertConflict();
        $this->apply('16', $this->preview())->assertUnprocessable();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('credit_applications', 1);
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertSame($p, (array) DB::table('payments')->find($this->payment));
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        $this->assertSame($docs, DB::table('invoice_documents')->pluck('sha256')->all());
        $this->getJson($this->root().'/sales/orders/'.$this->source)->assertOk()->assertJsonPath('data.credit_available', '15.0000')->assertJsonPath('data.paid_total', '10.0000')->assertJsonPath('data.credit_applied_total', '5.0000');
        $this->getJson($this->root().'/customers/'.$this->customer.'/summary')->assertOk()->assertJsonPath('data.credit_available', '15.0000')->assertJsonPath('data.pending_total', '15.0000');
        $this->getJson($this->root().'/sales/orders/'.$this->target.'/credits/requests/'.$key)->assertOk()->assertJsonPath('data.order.pending_total', '15.0000');
    }

    public function test_spent_credit_cannot_be_refunded_at_origin_but_returned_destination_credit_can(): void
    {
        $this->apply('5', $this->preview())->assertOk();
        $this->refund($this->source, $this->receipt, '16')->assertUnprocessable();
        $this->refund($this->source, $this->receipt, '15')->assertOk();
        $destinationReceipt = $this->returned($this->target, '2');
        $this->getJson($this->root().'/sales/orders/'.$this->target)->assertOk()->assertJsonPath('data.net_total', '0.0000')->assertJsonPath('data.credit_available', '5.0000')->assertJsonPath('data.paid_total', '0.0000');
        $this->refund($this->source, $this->receipt, '1')->assertUnprocessable();
        $this->refund($this->target, $destinationReceipt, '5')->assertOk();
        $this->assertSame('0.0000', OrderSettlement::payment($this->payment)['available']);
        $this->getJson($this->root().'/sales/orders/'.$this->source)->assertOk()->assertJsonPath('data.refunded_total', '15.0000');
        $this->getJson($this->root().'/customers/'.$this->customer.'/summary')->assertOk()->assertJsonPath('data.refunded_total', '20.0000')->assertJsonPath('data.credit_available', '0.0000');
    }

    public function test_same_weekly_invoice_attribution_and_reused_credit_chain_do_not_double_count(): void
    {
        $orders = [$this->order('3', 'reservation'), $this->order('1', 'reservation'), $this->order('2', 'reservation')];
        $account = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
        foreach ($orders as $i => $id) {
            $this->postJson($this->root()."/weekly-accounts/$account/orders/attach", ['request_key' => (string) Str::uuid(), 'version' => $i, 'order_id' => $id])->assertOk();
        }$r = $this->getJson($this->root()."/weekly-accounts/$account")->json('data');
        $this->postJson($this->root()."/weekly-accounts/$account/close", ['request_key' => (string) Str::uuid(), 'version' => 3, 'review_hash' => $r['review_hash']])->assertOk();
        $this->payment = $this->fund($orders[0], '30');
        $this->postJson($this->root().'/sales/orders/'.$orders[0].'/deliver', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($orders[0])->lock_version])->assertOk();
        $firstReturn = $this->returned($orders[0], '2');
        $this->apply('10', $this->preview($orders[1]))->assertOk();
        $this->getJson($this->root().'/sales/orders/'.$orders[0])->assertOk()->assertJsonPath('data.paid_total', '10.0000');
        $this->getJson($this->root().'/sales/orders/'.$orders[1])->assertOk()->assertJsonPath('data.paid_total', '10.0000');
        $paid = $this->getJson($this->root().'/baskets?filter=paid')->assertOk()->json('data');
        $this->assertContains($orders[1], array_column($paid, 'id'));
        $this->postJson($this->root().'/sales/orders/'.$orders[1].'/deliver', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($orders[1])->lock_version])->assertOk();
        $secondReturn = $this->returned($orders[1], '1');
        $this->apply('20', $this->preview($orders[2]))->assertOk();
        $this->refund($orders[0], $firstReturn, '1')->assertUnprocessable();
        $this->refund($orders[1], $secondReturn, '1')->assertUnprocessable();
        $this->getJson($this->root()."/weekly-accounts/$account")->assertOk()->assertJsonPath('data.totals.pending_total', '0.0000')->assertJsonPath('data.totals.credit_available', '0.0000');
    }

    public function test_foreign_customer_currency_and_unbilled_destinations_are_not_eligible(): void
    {
        $this->customer = Customer::factory()->create()->id;
        $other = $this->order('1');
        $this->apply('1', $this->preview($other))->assertNotFound();
        $this->customer = SalesOrder::find($this->source)->customer_id;
        BusinessProfile::whereKey(1)->update(['default_currency' => 'EUR']);
        $eur = $this->order('1');
        BusinessProfile::whereKey(1)->update(['default_currency' => 'USD']);
        $this->apply('1', $this->preview($eur))->assertNotFound();
        $unbilled = $this->order('1', 'reservation');
        $this->getJson($this->root()."/sales/orders/$unbilled/credits")->assertConflict();
        $this->assertDatabaseCount('credit_applications', 0);
    }

    public function test_pending_return_holds_credit_application_even_when_funds_and_debt_exist(): void
    {
        $path = $this->root().'/sales/orders/'.$this->target.'/returns';
        $physical = $this->getJson($path)->assertOk()->json('data');
        $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $physical['version'], 'review_hash' => $physical['review_hash'], 'reason' => 'Recepción aún no conciliada', 'lines' => [['invoice_item_id' => $physical['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertCreated();
        $review = $this->preview();
        $this->assertFalse($review['application_allowed']);
        $this->apply('1', $review)->assertConflict();
        $this->assertDatabaseCount('credit_applications', 0);
        $this->assertSame('20.0000', OrderSettlement::payment($this->payment)['available']);
    }

    public function test_other_branch_credit_is_rejected_even_with_access_to_both_branches(): void
    {
        $other = Store::create(['name' => 'Other branch', 'code' => 'CREDIT-OTHER']);
        DB::table('store_user')->insert(['user_id' => $this->owner->id, 'store_id' => $other->id]);
        $warehouse = Warehouse::create(['store_id' => $other->id, 'name' => 'Other warehouse', 'is_active' => true]);
        $this->store = $other->id;
        $this->warehouse = $warehouse->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Other branch fixture'])->assertCreated();
        $target = $this->order('1');
        $this->apply('1', $this->preview($target))->assertNotFound();
        $this->assertDatabaseCount('credit_applications', 0);
    }

    public function test_stale_review_and_missing_permission_leave_credit_untouched(): void
    {
        $old = $this->preview();
        $this->fund($this->target, '1');
        $this->apply('1', $old)->assertConflict();
        $review = $this->preview();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $this->apply('1', $review)->assertForbidden();
        $this->assertDatabaseCount('credit_applications', 0);
    }

    public function test_credit_application_requires_csrf_without_test_middleware_bypass(): void
    {
        $review = $this->preview();
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->apply('1', $review)->assertStatus(419);
        $this->assertDatabaseCount('credit_applications', 0);
    }
}
