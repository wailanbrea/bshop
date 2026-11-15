<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Catalog\ProductService;
use App\Domain\Sales\FinancialDocument;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinancialDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    private int $customer;

    private int $source;

    private int $target;

    private int $payment;

    private int $adjustment;

    private int $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T12:00:00Z'));
        Storage::fake('private_invoices');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Original Business', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Original Branch']);
        $this->store = Store::first()->id;
        $warehouse = Warehouse::first()->id;
        $this->customer = Customer::factory()->create(['full_name' => 'Original Customer <script>', 'phone' => '+1 202-555-0101'])->id;
        $this->actingAs($this->owner);
        $p = app(ProductService::class)->create(['name' => 'Proof item', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'PROOF-QA', 'sale_price' => '10', 'attribute_value_ids' => []]]], $this->owner);
        $variant = $p->variants()->first()->id;
        $this->postJson($this->root().'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse, 'variant_id' => $variant, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'note' => 'Financial document fixture'])->assertCreated();
        $orders = [];
        foreach (['3', '1'] as $quantity) {
            $body = ['warehouse_id' => $warehouse, 'customer_id' => $this->customer, 'lines' => [['variant_id' => $variant, 'quantity' => $quantity]], 'shipping' => '0', 'discount' => '0'];
            $hash = $this->postJson($this->root().'/sales/quote', $body)->assertOk()->json('data.quote_hash');
            $orders[] = $this->postJson($this->root().'/sales/orders', [...$body, 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash, 'mode' => 'sale', 'initial_payment' => $quantity === '3' ? '30' : '0', 'payment_method' => 'cash'])->assertCreated()->json('data.order.id');
        }
        [$this->source,$this->target] = $orders;
        $this->payment = DB::table('order_payments')->where('order_id', $this->source)->value('payment_id');
        $path = $this->root().'/sales/orders/'.$this->source.'/returns';
        $r = $this->getJson($path)->json('data');
        $receipt = $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Mercancía devuelta para crédito', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])->assertCreated()->json('data.sales_return_id');
        $proposal = $this->getJson($path."/$receipt/financial")->json('data.proposal');
        $this->adjustment = $this->postJson($path."/$receipt/adjust", ['request_key' => (string) Str::uuid(), 'version' => $proposal['version'], 'review_hash' => $proposal['review_hash'], 'shipping_credit' => '0', 'reason' => 'Original adjustment reason'])->assertOk()->json('data.adjustment_id');
        $this->application = $this->applyMore();
    }

    private function root(): string
    {
        return '/api/v1/business/stores/'.$this->store;
    }

    private function path(string $kind, int $id, ?int $order = null): string
    {
        return $this->root().'/sales/orders/'.($order ?? ($kind === 'adjustment' ? $this->source : $this->target))."/documents/$kind/$id";
    }

    private function applyMore(): int
    {
        $path = $this->root().'/sales/orders/'.$this->target.'/credits';
        $r = $this->getJson($path)->json('data');

        return $this->postJson($path, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'payment_id' => $this->payment, 'amount' => '5', 'reason' => 'Original credit reason'])->assertOk()->json('data.credit_application_id');
    }

    public function test_sales_control_and_activity_reconcile_adjustments_and_credit_without_new_cash(): void
    {
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('summary.0.net_total', '30.0000')->assertJsonPath('summary.0.return_credit_total', '10.0000')->assertJsonPath('summary.0.paid_total', '25.0000')->assertJsonPath('summary.0.pending_total', '5.0000')->assertJsonPath('summary.0.credit_available', '5.0000')->assertJsonPath('cash.0.confirmed', '30.0000');
        $this->getJson($this->root().'/sales/orders/'.$this->source.'/activity')->assertOk()->assertJsonCount(1, 'data.returns')->assertJsonPath('data.adjustments.0.amount', '10.0000')->assertJsonPath('data.credits.0.target_order_id', $this->target);
        $this->getJson($this->root().'/sales/orders/'.$this->target.'/activity')->assertOk()->assertJsonCount(0, 'data.payments')->assertJsonPath('data.credits.0.amount', '5.0000');
        $receipt = DB::table('return_adjustments')->where('id', $this->adjustment)->value('sales_return_id');
        $this->postJson($this->root().'/sales/orders/'.$this->source.'/returns/'.$receipt.'/refund', ['request_key' => (string) Str::uuid(), 'version' => DB::table('orders')->where('id', $this->source)->value('lock_version'), 'payment_id' => $this->payment, 'amount' => '5', 'method' => 'cash', 'external_reference' => null, 'reason' => 'Refund already delivered in cash'])->assertOk();
        $this->getJson($this->root().'/sales/control')->assertOk()->assertJsonPath('cash.0.confirmed', '30.0000')->assertJsonPath('cash.0.refunded', '5.0000')->assertJsonPath('cash.0.net_cash', '25.0000')->assertJsonPath('summary.0.credit_available', '0.0000');
        $this->getJson($this->root().'/sales/orders/'.$this->source.'/activity')->assertOk()->assertJsonPath('data.refunds.0.amount', '5.0000');
    }

    public function test_snapshots_are_captured_at_commit_and_stay_frozen_before_first_render(): void
    {
        $docs = DB::table('financial_documents')->orderBy('id')->get();
        $this->assertCount(2, $docs);
        $this->assertSame(['pending', 'pending'], $docs->pluck('status')->all());
        $service = app(FinancialDocument::class);
        BusinessProfile::whereKey(1)->update(['name' => 'Latest Business']);
        Store::whereKey($this->store)->update(['name' => 'Latest Branch']);
        Customer::whereKey($this->customer)->update(['full_name' => 'Latest Customer']);
        $this->applyMore();
        foreach ($docs as $doc) {
            $data = $service->snapshot(DB::table('financial_documents')->find($doc->id));
            $this->assertSame('Original Business', $data['business']['name']);
            $this->assertSame('Original Branch', $data['store_name']);
            $this->assertSame('Original Customer <script>', $data['customer']['full_name']);
            $html = view('documents.financial', compact('data'))->render();
            $this->assertStringContainsString('Original Customer &lt;script&gt;', $html);
            $this->assertStringNotContainsString('Latest Customer', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
        $doc = $docs->first();
        $data = json_decode($doc->snapshot, true);
        krsort($data);
        DB::table('financial_documents')->where('id', $doc->id)->update(['snapshot' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]);
        $this->assertSame('Original Business', $service->snapshot(DB::table('financial_documents')->find($doc->id))['business']['name']);
        $this->postJson($this->path('adjustment', $this->adjustment))->assertOk()->assertJsonPath('data.status', 'ready');
        $this->postJson($this->path('credit', $this->application))->assertOk()->assertJsonPath('data.status', 'ready');
    }

    public function test_repeated_prepare_and_download_have_stable_bytes_and_no_financial_effects(): void
    {
        $before = [];
        foreach (['orders', 'payments', 'payment_allocations', 'customer_credit_movements', 'stock_balances', 'inventory_movements', 'return_adjustments', 'credit_applications'] as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        foreach ([['adjustment', $this->adjustment], ['credit', $this->application]] as [$kind,$id]) {
            $path = $this->path($kind, $id);
            $this->get($path)->assertNotFound();
            $this->postJson($path)->assertOk();
            $first = $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $first->headers->get('Cache-Control'));
            $bytes = $first->streamedContent();
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->travel(2)->days();
            $this->postJson($path)->assertOk();
            $this->assertSame($bytes, $this->get($path)->assertOk()->streamedContent());
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table.' changed during PDF preparation');
        }$this->assertDatabaseCount('financial_documents', 2);
    }

    public function test_tampered_missing_or_misrouted_ready_files_are_not_regenerated(): void
    {
        $service = app(FinancialDocument::class);
        $path = $this->path('adjustment', $this->adjustment);
        $this->postJson($path)->assertOk();
        $doc = $service->ready('adjustment', $this->adjustment);
        $disk = Storage::disk('private_invoices');
        $disk->put($doc->object_key, '%PDF-tampered');
        $this->get($path)->assertStatus(503);
        $this->postJson($path)->assertStatus(503);
        $this->assertSame('%PDF-tampered', $disk->get($doc->object_key));
        $this->assertDatabaseHas('financial_documents', ['id' => $doc->id, 'status' => 'ready', 'sha256' => $doc->sha256]);
        $creditPath = $this->path('credit', $this->application);
        $this->postJson($creditPath)->assertOk();
        $credit = $service->ready('credit', $this->application);
        $disk->move($credit->object_key, $credit->object_key.'.missing');
        $this->get($creditPath)->assertStatus(503);
        $this->postJson($creditPath)->assertStatus(503);
        $this->assertFalse($disk->exists($credit->object_key));
        DB::table('financial_documents')->where('id', $credit->id)->update(['object_key' => $doc->object_key, 'sha256' => hash('sha256', '%PDF-tampered'), 'byte_size' => strlen('%PDF-tampered')]);
        $this->get($creditPath)->assertStatus(503);
    }

    public function test_failed_renderer_can_retry_same_snapshot_without_repeating_money(): void
    {
        $original = Pdf::getFacadeRoot();
        Pdf::shouldReceive('loadView')->once()->andThrow(new \RuntimeException('PDF fixture failure'));
        $path = $this->path('adjustment', $this->adjustment);
        $this->postJson($path)->assertStatus(503);
        $this->assertDatabaseHas('financial_documents', ['return_adjustment_id' => $this->adjustment, 'status' => 'failed']);
        Pdf::swap($original);
        $this->postJson($path)->assertOk();
        $this->assertDatabaseCount('return_adjustments', 1);
        $this->assertDatabaseCount('credit_applications', 1);
        $this->assertDatabaseCount('financial_documents', 2);
    }

    public function test_legacy_operation_without_snapshot_uses_frozen_invoice_data_and_a_store_identifier(): void
    {
        // Disposable in-memory metadata fixture: simulate an operation from before this migration.
        DB::table('financial_documents')->where('credit_application_id', $this->application)->delete();
        Store::whereKey($this->store)->update(['name' => 'Latest Branch']);
        Customer::whereKey($this->customer)->update(['full_name' => 'Latest Customer']);
        $this->postJson($this->path('credit', $this->application))->assertOk();
        $doc = DB::table('financial_documents')->where('credit_application_id', $this->application)->first();
        $data = app(FinancialDocument::class)->snapshot($doc);
        $this->assertTrue($data['legacy_capture']);
        $this->assertSame('Sucursal #'.$this->store, $data['store_name']);
        $this->assertSame('Original Customer <script>', $data['customer']['full_name']);
        $this->assertDatabaseCount('credit_applications', 1);
        $this->assertDatabaseCount('financial_documents', 2);
    }

    public function test_interrupted_publication_recovers_sealed_bytes_without_rendering_again(): void
    {
        $service = app(FinancialDocument::class);
        $disk = Storage::disk('private_invoices');
        $docs = [];
        foreach ([['adjustment', $this->adjustment], ['credit', $this->application]] as [$kind,$id]) {
            $this->postJson($this->path($kind, $id))->assertOk();
            $doc = $service->ready($kind, $id);
            $docs[] = [$kind, $id, $doc, $disk->get($doc->object_key)];
            DB::table('financial_documents')->where('id', $doc->id)->update(['status' => 'pending']);
            if ($kind === 'credit') {
                $disk->move($doc->object_key, $doc->object_key.'.pending');
            }
        }
        $original = Pdf::getFacadeRoot();
        Pdf::shouldReceive('loadView')->never();
        try {
            foreach ($docs as [$kind,$id,$doc,$bytes]) {
                $this->postJson($this->path($kind, $id))->assertOk();
                $this->assertSame($bytes, $disk->get($doc->object_key));
                $this->assertDatabaseHas('financial_documents', ['id' => $doc->id, 'status' => 'ready', 'sha256' => $doc->sha256]);
            }
        } finally {
            Pdf::swap($original);
        }
    }

    public function test_unknown_existing_canonical_file_is_preserved_without_overwrite(): void
    {
        $key = app(FinancialDocument::class)->key('adjustment', $this->adjustment);
        Storage::disk('private_invoices')->put($key, '%PDF-unknown-historical');
        $this->postJson($this->path('adjustment', $this->adjustment))->assertStatus(503);
        $this->assertSame('%PDF-unknown-historical', Storage::disk('private_invoices')->get($key));
        $this->assertDatabaseHas('financial_documents', ['return_adjustment_id' => $this->adjustment, 'status' => 'failed']);
    }

    public function test_document_scope_and_invoice_permission_protect_private_downloads(): void
    {
        $this->postJson($this->path('adjustment', $this->adjustment, $this->target))->assertNotFound();
        $this->get($this->path('credit', $this->application, $this->source))->assertNotFound();
        $other = Store::create(['name' => 'Other', 'code' => 'DOC-OTHER']);
        $this->getJson('/api/v1/business/stores/'.$other->id.'/sales/orders/'.$this->source.'/documents/adjustment/'.$this->adjustment)->assertNotFound();
        $role = Role::create(['code' => 'doc_reader_no_pdf', 'label' => 'Reader', 'permissions' => ['stores.view', 'sales.view']]);
        $reader = User::factory()->create();
        $reader->roles()->attach($role);
        DB::table('store_user')->insert(['user_id' => $reader->id, 'store_id' => $this->store]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($reader);
        $this->getJson($this->path('adjustment', $this->adjustment))->assertForbidden();
        $this->postJson($this->path('credit', $this->application))->assertForbidden();
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->getJson($this->path('credit', $this->application))->assertUnauthorized();
    }

    public function test_preparation_requires_csrf_without_the_test_middleware_bypass(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        foreach ([['adjustment', $this->adjustment], ['credit', $this->application]] as [$kind, $id]) {
            $this->postJson($this->path($kind, $id))->assertStatus(419);
        }
        $this->assertSame(['pending', 'pending'], DB::table('financial_documents')->orderBy('id')->pluck('status')->all());
    }

    public function test_snapshot_tampering_and_first_generation_locks_do_not_publish_documents(): void
    {
        $doc = DB::table('financial_documents')->where('return_adjustment_id', $this->adjustment)->first();
        $data = json_decode($doc->snapshot, true);
        $data['amount'] = '999.0000';
        DB::table('financial_documents')->where('id', $doc->id)->update(['snapshot' => json_encode($data)]);
        $this->postJson($this->path('adjustment', $this->adjustment))->assertStatus(503);
        $disk = Storage::disk('private_invoices');
        $directory = $disk->path('_locks');
        $handle = fopen($directory.'/financial-credit-'.$this->application.'.lock', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->postJson($this->path('credit', $this->application))->assertConflict();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }$this->assertDatabaseHas('financial_documents', ['credit_application_id' => $this->application, 'status' => 'pending']);
    }
}
