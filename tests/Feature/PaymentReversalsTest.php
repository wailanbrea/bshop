<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\ReservationExpiry;
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

class PaymentReversalsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $customer;

    private int $payment;

    private array $orders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Reversal QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->store = Store::first()->id;
        $warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create()->id;
        $this->actingAs($this->owner);
        $product = app(ProductService::class)->create(['name' => 'Reversal bag', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'REV-QA', 'sale_price' => '25', 'attribute_value_ids' => []]]], $this->owner);
        $variant = $product->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Reversal fixture'])->assertCreated();
        $data = ['warehouse_id' => $warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $variant, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
        $hash = $this->postJson($this->root().'/sales/quote', $data)->assertOk()->json('data.quote_hash');
        foreach (['5', '0'] as $paid) {
            $this->orders[] = $this->postJson($this->root().'/sales/orders', [...$data, 'quote_hash' => $hash, 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => $paid, 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        }
        $this->payment = DB::table('order_payments')->where('order_id', $this->orders[0])->value('payment_id');
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function endpoint(?int $order = null, ?int $payment = null): string
    {
        return $this->root().'/sales/orders/'.($order ?? $this->orders[0]).'/payments/'.($payment ?? $this->payment).'/reverse';
    }

    private function reverse(?string $key = null, ?int $version = null, string $reason = 'Cobro registrado dos veces')
    {
        return $this->postJson($this->endpoint(), ['request_key' => $key ?? (string) Str::uuid(), 'version' => $version ?? SalesOrder::find($this->orders[0])->lock_version, 'reason' => $reason]);
    }

    private function invoice(): int
    {
        $order = SalesOrder::find($this->orders[0]);

        return $this->postJson($this->root()."/sales/orders/$order->id/invoice", ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version])->assertOk()->json('data.order.invoice.id');
    }

    private function credit(int $payment): string
    {
        $amount = '0.0000';
        foreach (DB::table('customer_credit_movements')->where('payment_id', $payment)->pluck('amount_delta') as $delta) {
            $amount = bcadd($amount, (string) $delta, 4);
        }

        return $amount;
    }

    public function test_unbilled_reversal_preserves_receipt_and_stock_and_never_reallocates_it_later(): void
    {
        $original = DB::table('payments')->find($this->payment);
        $stock = DB::table('stock_balances')->get()->toJson();
        $movements = DB::table('inventory_movements')->count();
        $key = (string) Str::uuid();
        $this->reverse($key, 0)->assertOk()->assertJsonPath('data.order.paid_total', '0.0000')->assertJsonPath('data.order.pending_total', '25.0000');
        $this->reverse(strtoupper($key), 0)->assertOk()->assertJsonPath('data.replay', true);
        $this->reverse()->assertConflict();
        $current = DB::table('payments')->find($this->payment);
        foreach (['amount', 'paid_at', 'currency', 'payment_method', 'notes'] as $field) {
            $this->assertSame($original->$field, $current->$field);
        }
        $this->assertSame('reversed', $current->status);
        $this->assertSame('0.0000', $this->credit($this->payment));
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        $this->assertSame($movements, DB::table('inventory_movements')->count());
        $this->assertDatabaseCount('payment_reversals', 1);
        $this->assertDatabaseCount('order_payments', 1);
        $this->assertDatabaseHas('payment_reversals', ['payment_id' => $this->payment, 'amount' => '5', 'allocated_amount' => '0', 'credit_voided' => '5']);
        $invoice = $this->invoice();
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'status' => 'issued']);
        $this->getJson($this->root().'/sales/orders/'.$this->orders[0].'/payments')->assertOk()->assertJsonPath('data.0.status', 'reversed')->assertJsonPath('data.0.reversal.reason', 'Cobro registrado dos veces');
        $this->getJson($this->root().'/sales/orders/'.$this->orders[0].'/payments/requests/'.$key)->assertOk()->assertJsonPath('data.order.invoice.id', $invoice);
    }

    public function test_reversal_reopens_paid_invoice_without_deleting_allocations_or_changing_pdf(): void
    {
        $invoice = $this->invoice();
        $doc = DB::table('invoice_documents')->where('invoice_id', $invoice)->first();
        $bytes = Storage::disk('private_invoices')->get($doc->object_key);
        $this->postJson($this->root().'/sales/orders/'.$this->orders[0].'/payment', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->orders[0])->lock_version, 'amount' => '20', 'payment_method' => 'card'])->assertOk()->assertJsonPath('data.order.invoice.status', 'paid');
        $this->reverse()->assertOk()->assertJsonPath('data.order.paid_total', '20.0000')->assertJsonPath('data.order.pending_total', '5.0000')->assertJsonPath('data.order.invoice.status', 'partially_paid');
        $this->assertDatabaseCount('payment_allocations', 2);
        $this->assertDatabaseHas('payment_reversals', ['payment_id' => $this->payment, 'allocated_amount' => '5', 'credit_voided' => '0']);
        $this->assertSame('0.0000', $this->credit($this->payment));
        $this->postJson($this->root()."/invoices/$invoice/document")->assertOk();
        $this->assertSame($bytes, Storage::disk('private_invoices')->get($doc->object_key));
        $this->getJson($this->root().'/receivables?type=invoiced')->assertOk()->assertJsonPath('summary.0.pending', '5.0000');
    }

    public function test_weekly_invoice_uses_all_remaining_payments_and_keeps_the_closing_snapshot(): void
    {
        $account = $this->postJson($this->root().'/weekly-accounts', ['request_key' => (string) Str::uuid(), 'customer_id' => $this->customer, 'period_start' => '2026-11-09'])->assertCreated()->json('data.account.id');
        foreach ($this->orders as $index => $order) {
            $this->postJson($this->root()."/weekly-accounts/$account/orders/attach", ['request_key' => (string) Str::uuid(), 'version' => $index, 'order_id' => $order])->assertOk();
        }
        $review = $this->getJson($this->root()."/weekly-accounts/$account")->json('data');
        $invoice = $this->postJson($this->root()."/weekly-accounts/$account/close", ['request_key' => (string) Str::uuid(), 'version' => 2, 'review_hash' => $review['review_hash']])->assertOk()->json('data.account.invoice.id');
        $this->reverse()->assertOk()->assertJsonPath('data.order.invoice.status', 'issued');
        $this->assertDatabaseHas('weekly_accounts', ['id' => $account, 'status' => 'closed', 'paid_at_close' => '5']);
        $this->postJson($this->root().'/sales/orders/'.$this->orders[1].'/payment', ['request_key' => (string) Str::uuid(), 'version' => SalesOrder::find($this->orders[1])->lock_version, 'amount' => '25', 'payment_method' => 'cash'])->assertOk()->assertJsonPath('data.order.invoice.status', 'partially_paid');
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'grand_total' => '50']);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_partial_historical_allocation_only_voids_the_unallocated_credit_remainder(): void
    {
        $invoice = $this->invoice();
        $allocation = DB::table('payment_allocations')->where('payment_id', $this->payment)->first();
        DB::table('payment_allocations')->where('id', $allocation->id)->update(['amount' => '3']);
        DB::table('customer_credit_movements')->where('payment_allocation_id', $allocation->id)->update(['amount_delta' => '-3']);
        $this->reverse()->assertOk()->assertJsonPath('data.order.pending_total', '25.0000')->assertJsonPath('data.order.invoice.status', 'issued');
        $this->assertDatabaseHas('payment_reversals', ['payment_id' => $this->payment, 'amount' => '5', 'allocated_amount' => '3', 'credit_voided' => '2']);
        $this->assertDatabaseHas('payment_allocations', ['id' => $allocation->id, 'amount' => '3']);
        $this->assertSame('0.0000', $this->credit($this->payment));
        $this->assertDatabaseHas('invoices', ['id' => $invoice, 'status' => 'issued']);
    }

    public function test_seller_can_read_history_but_cannot_reverse_or_recover_someone_elses_action(): void
    {
        $key = (string) Str::uuid();
        $this->reverse($key)->assertOk();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $this->getJson($this->root().'/sales/orders/'.$this->orders[0].'/payments')->assertOk();
        $this->reverse()->assertForbidden();
        $this->getJson($this->root().'/sales/orders/'.$this->orders[0].'/payments/requests/'.$key)->assertNotFound();
        $this->assertFalse($seller->permits('payments.reverse'));
    }

    public function test_scope_versions_reason_and_adjusted_documents_fail_without_side_effects(): void
    {
        $this->reverse(reason: '    ')->assertUnprocessable();
        $this->reverse(version: 99)->assertConflict();
        $this->postJson($this->endpoint($this->orders[1]), ['request_key' => (string) Str::uuid(), 'version' => 0, 'reason' => 'No pertenece al pedido'])->assertNotFound();
        $other = Store::create(['name' => 'Other', 'code' => 'REV-OTHER']);
        $this->postJson('/api/v1/business/stores/'.$other->id.'/sales/orders/'.$this->orders[0].'/payments/'.$this->payment.'/reverse', ['request_key' => (string) Str::uuid(), 'version' => 0, 'reason' => 'Sucursal ajena'])->assertNotFound();
        $invoice = $this->invoice();
        DB::table('invoices')->where('id', $invoice)->update(['status' => 'void']);
        $this->reverse()->assertConflict();
        $this->assertDatabaseCount('payment_reversals', 0);
        $this->assertDatabaseHas('payments', ['id' => $this->payment, 'status' => 'confirmed']);
    }

    public function test_reversal_requires_csrf_when_the_test_only_bypass_is_disabled(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->reverse()->assertStatus(419);
        $this->assertDatabaseCount('payment_reversals', 0);
        $this->assertDatabaseHas('payments', ['id' => $this->payment, 'status' => 'confirmed']);
    }

    public function test_pending_refund_blocks_reversal_and_reversed_only_reservation_can_expire_normally(): void
    {
        $refund = DB::table('refunds')->insertGetId(['customer_id' => $this->customer, 'store_id' => $this->store, 'original_payment_id' => $this->payment, 'refund_kind' => 'unused_credit', 'status' => 'pending', 'amount' => '1', 'currency' => 'USD', 'method' => 'cash', 'event_key' => 'refund-qa']);
        $this->reverse()->assertConflict();
        DB::table('refunds')->where('id', $refund)->update(['status' => 'failed']);
        $stock = DB::table('stock_balances')->get()->toJson();
        $this->reverse()->assertOk();
        $this->assertSame($stock, DB::table('stock_balances')->get()->toJson());
        DB::table('stock_reservations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $this->orders[0])->select('id'))->update(['expires_at' => '2026-11-12 11:00:00']);
        $this->assertSame('expired', app(ReservationExpiry::class)->inspect($this->orders[0], CarbonImmutable::now(), true));
        $this->assertSame('cancelled', SalesOrder::find($this->orders[0])->status);
        $this->assertDatabaseHas('payments', ['id' => $this->payment, 'status' => 'reversed']);
    }
}
