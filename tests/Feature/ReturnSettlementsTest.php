<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\OrderSettlement;
use App\Models\Customer;
use App\Models\ProductVariant;
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

class ReturnSettlementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $warehouse;

    private int $customer;

    private int $variant;

    private int $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Settlement QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $this->warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create()->id;
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Credited bag', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CREDIT-QA', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $this->variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $this->variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Settlement fixture'])->assertCreated();
        DB::table('business_settings')->where('id', 1)->update(['sales_tax_rate' => '10']);
        $this->order = $this->sale('3', '3', '5');
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function path(int $receipt): string
    {
        return $this->root().'/sales/orders/'.$this->order.'/returns/'.$receipt;
    }

    private function sale(string $qty, string $discount = '0', string $shipping = '0'): int
    {
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => $qty]], 'discount' => $discount, 'shipping' => $shipping];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');

        return $this->postJson($this->root().'/sales/orders', [...$body, 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash, 'mode' => 'sale', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
    }

    private function fund(string $amount): int
    {
        $this->postJson($this->root().'/sales/orders/'.$this->order.'/payment', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->order)->lock_version, 'amount' => $amount, 'payment_method' => 'cash'])->assertOk();

        return DB::table('order_payments')->where('order_id', $this->order)->orderByDesc('payment_id')->value('payment_id');
    }

    private function receive(string $qty = '1'): int
    {
        $path = $this->root().'/sales/orders/'.$this->order.'/returns';
        $r = $this->getJson($path)->assertOk()->json('data');

        return $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Recepción para conciliación', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => $qty, 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
    }

    private function adjust(int $receipt, string $shipping = '0', ?string $key = null)
    {
        $proposal = $this->getJson($this->path($receipt).'/financial?shipping_credit='.$shipping)->assertOk()->json('data.proposal');

        return $this->postJson($this->path($receipt).'/adjust', ['request_key' => $key ?? (string) Str::uuid(), 'version' => $proposal['version'], 'review_hash' => $proposal['review_hash'], 'shipping_credit' => $shipping, 'reason' => 'Ajuste revisado por devolución']);
    }

    private function refund(int $receipt, int $payment, string $amount, string $reference, ?string $key = null, ?int $version = null)
    {
        return $this->postJson($this->path($receipt).'/refund', ['request_key' => $key ?? (string) Str::uuid(), 'version' => $version ?? SalesOrder::find($this->order)->lock_version, 'payment_id' => $payment, 'amount' => $amount, 'method' => 'bank_transfer', 'external_reference' => $reference, 'reason' => 'Reembolso manual ya realizado']);
    }

    private function erroneousRefund(): array
    {
        $payment = $this->fund('34.70');
        $receipt = $this->receive();
        $this->adjust($receipt)->assertOk();
        $refund = $this->refund($receipt, $payment, '5', 'WRONG-REGISTRATION')->assertOk()->json('data.refund_id');

        return [$receipt, $payment, $refund];
    }

    private function correctionPath(int $receipt, int $refund): string
    {
        return $this->path($receipt)."/refunds/$refund/reversal";
    }

    private function correctionPayload(int $receipt, int $refund): array
    {
        $r = $this->getJson($this->correctionPath($receipt, $refund))->assertOk()->json('data');

        return ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Registro erróneo, no se entregó dinero', 'confirmed_no_money' => true];
    }

    public function test_erroneous_refund_correction_restores_credit_without_new_cash_or_rewriting_history(): void
    {
        [$receipt,$payment,$refund] = $this->erroneousRefund();
        $before = [];
        foreach (['payments', 'payment_allocations', 'customer_credit_movements', 'refunds', 'refund_confirmations', 'invoices', 'invoice_documents', 'sales_return_items', 'inventory_movements', 'stock_balances'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }$body = $this->correctionPayload($receipt, $refund);
        $path = $this->correctionPath($receipt, $refund);
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.order.credit_available', '9.9000')->assertJsonPath('data.order.refunded_total', '0.0000')->assertJsonPath('data.order.net_total', '24.8000')->assertJsonPath('data.order.paid_total', '24.8000');
        $this->postJson($path, $body)->assertOk()->assertJsonPath('data.replay', true);
        $this->getJson($path.'/requests/'.$body['request_key'])->assertOk();
        $this->postJson($path, $this->correctionPayload($receipt, $refund))->assertConflict();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertDatabaseCount('refund_reversals', 1);
        $this->assertSame('9.9000', OrderSettlement::payment($payment)['available']);
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('cash.0.confirmed', '34.7000')->assertJsonPath('cash.0.refunded', '0.0000')->assertJsonPath('cash.0.net_cash', '34.7000');
        $this->getJson($this->path($receipt).'/financial')->assertOk()->assertJsonPath('data.refunds.0.reversal.reason', 'Registro erróneo, no se entregó dinero');
    }

    public function test_refund_correction_requires_an_explicit_no_money_confirmation(): void
    {
        [$receipt,,$refund] = $this->erroneousRefund();
        $data = $this->correctionPayload($receipt, $refund);
        unset($data['confirmed_no_money']);
        $this->postJson($this->correctionPath($receipt, $refund), $data)->assertUnprocessable();
        $this->postJson($this->correctionPath($receipt, $refund), [...$data, 'confirmed_no_money' => false])->assertUnprocessable();
        $this->assertDatabaseCount('refund_reversals', 0);
    }

    public function test_corrected_refund_does_not_consume_the_next_real_refund_budget(): void
    {
        [$receipt,$payment,$refund] = $this->erroneousRefund();
        $this->postJson($this->correctionPath($receipt, $refund), $this->correctionPayload($receipt, $refund))->assertOk();
        $this->refund($receipt, $payment, '8.90', 'REAL-TRANSFER')->assertOk()->assertJsonPath('data.order.refunded_total', '8.9000')->assertJsonPath('data.order.credit_available', '1.0000');
        $this->assertSame('13.9000', OrderSettlement::payment($payment)['historical_refunded']);
        $this->assertSame('8.9000', OrderSettlement::payment($payment)['refunded']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_corrected_refund_credit_can_be_applied_without_creating_a_receipt(): void
    {
        [$receipt,$payment,$refund] = $this->erroneousRefund();
        $this->postJson($this->correctionPath($receipt, $refund), $this->correctionPayload($receipt, $refund))->assertOk();
        $target = $this->sale('1');
        $path = $this->root()."/sales/orders/$target/credits";
        $r = $this->getJson($path)->assertOk()->json('data');
        $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'payment_id' => $payment, 'amount' => '9.90', 'reason' => 'Aplicar crédito realmente disponible'])->assertOk()->assertJsonPath('data.order.pending_total', '1.1000');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('0.0000', OrderSettlement::payment($payment)['available']);
    }

    public function test_next_return_adjustment_reconciles_the_historical_and_effective_refunds(): void
    {
        [$receipt,,$refund] = $this->erroneousRefund();
        $this->postJson($this->correctionPath($receipt, $refund), $this->correctionPayload($receipt, $refund))->assertOk();
        $next = $this->receive();
        $this->adjust($next)->assertOk()->assertJsonPath('data.order.credit_available', '19.8000')->assertJsonPath('data.order.refunded_total', '0.0000');
    }

    public function test_refund_correction_rejects_stale_scope_and_nonconfirmed_records(): void
    {
        [$receipt,,$refund] = $this->erroneousRefund();
        $body = $this->correctionPayload($receipt, $refund);
        SalesOrder::whereKey($this->order)->increment('lock_version');
        $this->postJson($this->correctionPath($receipt, $refund), $body)->assertConflict();
        $this->getJson($this->correctionPath($receipt + 1, $refund))->assertNotFound();
        DB::table('refunds')->where('id', $refund)->update(['status' => 'pending']);
        $this->getJson($this->correctionPath($receipt, $refund))->assertOk()->assertJsonPath('data.allowed', false);
        $this->postJson($this->correctionPath($receipt, $refund), $this->correctionPayload($receipt, $refund))->assertConflict();
        $this->assertDatabaseCount('refund_reversals', 0);
    }

    public function test_refund_correction_permission_recovery_identity_and_csrf(): void
    {
        [$receipt,,$refund] = $this->erroneousRefund();
        $body = $this->correctionPayload($receipt, $refund);
        $path = $this->correctionPath($receipt, $refund);
        $this->postJson($path, $body)->assertOk();
        $reader = User::factory()->create();
        $role = Role::create(['code' => 'refund_observer', 'label' => 'Reader', 'permissions' => ['sales.view', 'stores.view']]);
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['store_id' => $this->store, 'user_id' => $reader->id]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($path)->assertOk();
        $this->postJson($path, $body)->assertForbidden();
        $this->getJson($path.'/requests/'.$body['request_key'])->assertNotFound();
    }

    public function test_refund_correction_requires_csrf_without_the_test_bypass(): void
    {
        [$receipt,,$refund] = $this->erroneousRefund();
        $body = $this->correctionPayload($receipt, $refund);
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->correctionPath($receipt, $refund), $body)->assertStatus(419);
        $this->assertDatabaseCount('refund_reversals', 0);
    }

    public function test_credit_and_manual_refunds_preserve_invoice_stock_and_all_original_money_rows(): void
    {
        $payment = $this->fund('34.70');
        $receipt = $this->receive();
        $stock = DB::table('stock_balances')->get()->toJson();
        $moves = DB::table('inventory_movements')->count();
        $invoice = DB::table('invoices')->where('order_id', $this->order)->first();
        $doc = DB::table('invoice_documents')->where('invoice_id', $invoice->id)->first();
        $bytes = Storage::disk('private_invoices')->get($doc->object_key);
        $this->adjust($receipt)->assertOk()->assertJsonPath('data.order.net_total', '24.8000')->assertJsonPath('data.order.paid_total', '24.8000')->assertJsonPath('data.order.credit_available', '9.9000')->assertJsonPath('data.order.return_pending', false);
        $this->assertDatabaseHas('sales_return_items', ['sales_return_id' => $receipt, 'refund_amount' => '9.9']);
        $version = SalesOrder::find($this->order)->lock_version;
        $key = (string) Str::uuid();
        $this->refund($receipt, $payment, '1', '0', $key, $version)->assertOk();
        $this->refund($receipt, $payment, '1', '0', strtoupper($key), $version)->assertOk()->assertJsonPath('data.replay', true);
        $this->refund($receipt, $payment, '1', '0')->assertConflict();
        $this->refund($receipt, $payment, '9', 'BANK-X')->assertUnprocessable();
        $this->refund($receipt, $payment, '8.90', 'BANK-2')->assertOk()->assertJsonPath('data.order.refunded_total', '9.9000')->assertJsonPath('data.order.credit_available', '0.0000')->assertJsonPath('data.order.pending_total', '0.0000');
        $this->assertDatabaseCount('refunds', 2);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('payments', ['id' => $payment, 'amount' => '34.7', 'status' => 'confirmed']);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'grand_total' => '34.7', 'status' => 'paid']);
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        $this->assertSame($moves, DB::table('inventory_movements')->count());
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($doc->object_key));
        $this->assertSame('0.0000', OrderSettlement::payment($payment)['available']);
        $this->getJson($this->root().'/customers/'.$this->customer.'/summary')->assertOk()->assertJsonPath('data.credit_available', '0.0000');
    }

    public function test_credit_reduces_debt_first_and_new_cash_is_limited_to_net_due(): void
    {
        $this->fund('5');
        $receipt = $this->receive();
        $this->adjust($receipt)->assertOk()->assertJsonPath('data.order.net_total', '24.8000')->assertJsonPath('data.order.pending_total', '19.8000')->assertJsonPath('data.order.credit_available', '0.0000');
        $this->getJson($this->path($receipt).'/financial')->assertOk()->assertJsonCount(0, 'data.refund_sources');
        $body = ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->order)->lock_version, 'amount' => '19.81', 'payment_method' => 'cash'];
        $this->postJson($this->root().'/sales/orders/'.$this->order.'/payment', $body)->assertUnprocessable();
        $this->fund('19.80');
        $this->getJson($this->root().'/receivables')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_cumulative_rounding_credits_three_units_exactly_without_cent_drift(): void
    {
        ProductVariant::whereKey($this->variant)->update(['sale_price' => '0.3333']);
        DB::table('business_settings')->where('id', 1)->update(['sales_tax_rate' => '0']);
        $this->order = $this->sale('3');
        $values = [];
        for ($i = 0; $i < 3; $i++) {
            $receipt = $this->receive();
            $values[] = $this->getJson($this->path($receipt).'/financial')->assertOk()->json('data.proposal.amount');
            $this->adjust($receipt)->assertOk();
        }
        $this->assertSame(['0.3300', '0.3400', '0.3300'], $values);
        $this->getJson($this->root().'/sales/orders/'.$this->order)->assertOk()->assertJsonPath('data.net_total', '0.0000')->assertJsonPath('data.return_credit_total', '1.0000');
    }

    public function test_shipping_is_explicit_capped_and_current_catalog_prices_do_not_change_credit(): void
    {
        $receipt = $this->receive();
        ProductVariant::whereKey($this->variant)->update(['sale_price' => '999']);
        $this->adjust($receipt, '5')->assertOk()->assertJsonPath('data.order.net_total', '19.8000');
        $second = $this->receive('2');
        $this->getJson($this->path($second).'/financial?shipping_credit=0.01')->assertUnprocessable();
        $this->adjust($second)->assertOk()->assertJsonPath('data.order.net_total', '0.0000')->assertJsonPath('data.order.return_credit_total', '34.7000');
    }

    public function test_weekly_credit_keeps_other_order_debt_and_does_not_transfer_credit_implicitly(): void
    {
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1']], 'discount' => '0', 'shipping' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $orders = [];
        for ($i = 0; $i < 2; $i++) {
            $orders[] = $this->postJson($this->root().'/sales/orders', [...$body, 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        }
        $account = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
        foreach ($orders as $i => $id) {
            $this->postJson($this->root()."/weekly-accounts/$account/orders/attach", ['request_key' => (string) Str::uuid(), 'version' => $i, 'order_id' => $id])->assertOk();
        }
        $review = $this->getJson($this->root()."/weekly-accounts/$account")->json('data');
        $this->postJson($this->root()."/weekly-accounts/$account/close", ['request_key' => (string) Str::uuid(), 'version' => 2, 'review_hash' => $review['review_hash']])->assertOk();
        $this->order = $orders[0];
        $payment = $this->fund('11');
        $this->postJson($this->root().'/sales/orders/'.$this->order.'/deliver', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->order)->lock_version])->assertOk();
        $receipt = $this->receive();
        $this->adjust($receipt)->assertOk()->assertJsonPath('data.order.net_total', '0.0000')->assertJsonPath('data.order.credit_available', '11.0000');
        $this->getJson($this->root()."/weekly-accounts/$account")->assertOk()->assertJsonPath('data.totals.net_total', '11.0000')->assertJsonPath('data.totals.pending_total', '11.0000');
        $this->refund($receipt, $payment, '11', 'WEEK-REFUND')->assertOk();
        $this->getJson($this->root().'/sales/orders/'.$orders[1])->assertOk()->assertJsonPath('data.pending_total', '11.0000');
    }

    public function test_financial_adjustment_requires_csrf_without_the_test_bypass(): void
    {
        $receipt = $this->receive();
        $proposal = $this->getJson($this->path($receipt).'/financial')->json('data.proposal');
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson($this->path($receipt).'/adjust', ['request_key' => (string) Str::uuid(), 'version' => $proposal['version'], 'review_hash' => $proposal['review_hash'], 'shipping_credit' => '0', 'reason' => 'Conciliación con protección CSRF'])->assertStatus(419);
        $this->assertDatabaseCount('return_adjustments', 0);
    }

    public function test_multi_item_credit_distributes_original_discount_and_tax_exactly(): void
    {
        $p = app(ProductService::class)->create(['name' => 'Second credited item', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'CREDIT-SECOND', 'sale_price' => '20', 'attribute_value_ids' => []]]], $this->owner);
        $variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $this->warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Multi-item credit QA'])->assertCreated();
        $body = ['warehouse_id' => $this->warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $this->variant, 'quantity' => '1'], ['variant_id' => $variant, 'quantity' => '1']], 'discount' => '3', 'shipping' => '5'];
        $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
        $this->order = $this->postJson($this->root().'/sales/orders', [...$body, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => 'sale', 'initial_payment' => '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        $path = $this->root().'/sales/orders/'.$this->order.'/returns';
        $review = $this->getJson($path)->json('data');
        $receipt = $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'reason' => 'Recepción de todos los artículos', 'lines' => array_map(fn ($line) => ['invoice_item_id' => $line['invoice_item_id'], 'quantity' => '1', 'restock' => true], $review['lines'])])->assertCreated()->json('data.sales_return_id');
        $proposal = $this->getJson($this->path($receipt).'/financial?shipping_credit=5')->assertOk()->json('data.proposal');
        $this->assertSame(['9.9000', '19.8000'], array_column($proposal['lines'], 'credit_amount'));
        $this->assertSame('34.7000', $proposal['amount']);
        $this->adjust($receipt, '5')->assertOk()->assertJsonPath('data.order.net_total', '0.0000');
    }

    public function test_conflicts_and_permissions_do_not_apply_a_credit_twice(): void
    {
        $receipt = $this->receive();
        $proposal = $this->getJson($this->path($receipt).'/financial')->json('data.proposal');
        $body = ['request_key' => (string) Str::uuid(), 'version' => $proposal['version'], 'review_hash' => $proposal['review_hash'], 'shipping_credit' => '0', 'reason' => 'Ajuste correcto de mercancía'];
        $this->postJson($this->path($receipt).'/adjust', $body)->assertOk();
        $this->postJson($this->path($receipt).'/adjust', $body)->assertOk()->assertJsonPath('data.replay', true);
        $this->postJson($this->path($receipt).'/adjust', [...$body, 'request_key' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('return_adjustments', 1);
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $this->postJson($this->path($receipt).'/adjust', $body)->assertForbidden();
        $this->getJson($this->path($receipt).'/financial/requests/'.$body['request_key'].'?operation=adjust')->assertNotFound();
    }
}
