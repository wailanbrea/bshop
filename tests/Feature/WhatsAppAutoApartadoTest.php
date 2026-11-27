<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Models\AttributeValue;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAutoApartadoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Warehouse $warehouse;

    private string $secret = 'auto_apartado_test_secret_123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, [
            'name' => 'Keyla Test Shop',
            'default_currency' => 'USD',
            'timezone' => 'America/Santo_Domingo',
            'store_name' => 'Principal',
        ]);
        $this->store = Store::firstOrFail();
        $this->warehouse = Warehouse::where('store_id', $this->store->id)->firstOrFail();

        DB::table('store_whatsapp_configs')->insert([
            'store_id' => $this->store->id,
            'enabled' => true,
            'phone_number_id' => '1419120884625599',
            'template' => 'factura_cliente',
            'language' => 'es',
            'graph_version' => 'v25.0',
            'body_fields' => json_encode(['customer_name', 'invoice_number', 'total', 'business_name']),
            'automation_rules' => json_encode([
                'auto_apartado' => ['enabled' => true, 'template' => 'auto_apartado'],
            ]),
            'access_token_encrypted' => Crypt::encryptString('EAAtesttoken'),
            'app_secret_encrypted' => Crypt::encryptString($this->secret),
            'verify_token_encrypted' => Crypt::encryptString('verify_token_1234567'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postWebhook(array $messages, string $from = '18298144525', string $name = 'Laura Diaz')
    {
        $payload = ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1419120884625599'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $name]]],
            'messages' => $messages,
        ]]]]]];
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $this->secret),
        ], $raw);
    }

    private function createProductWithStock(string $name, array $variantsSpec): Product
    {
        $product = Product::create([
            'store_id' => $this->store->id,
            'name' => $name,
            'product_type' => 'physical',
            'unit_code' => 'unit',
            'track_stock' => true,
            'status' => 'active',
        ]);

        $attr = ProductAttribute::firstOrCreate(
            ['name' => 'Talla'],
            ['is_active' => true],
        );

        foreach ($variantsSpec as $spec) {
            $v = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $spec['sku'],
                'combination_key' => $spec['size'] ?? 'default',
                'sale_price' => $spec['price'],
                'cost_price' => '5.0000',
                'is_active' => true,
            ]);
            if (! empty($spec['size'])) {
                $val = AttributeValue::create([
                    'attribute_id' => $attr->id,
                    'value' => $spec['size'],
                    'is_active' => true,
                ]);
                DB::table('variant_attribute_values')->insert([
                    'variant_id' => $v->id,
                    'attribute_id' => $attr->id,
                    'value_id' => $val->id,
                ]);
            }
            DB::table('stock_balances')->insert([
                'warehouse_id' => $this->warehouse->id,
                'variant_id' => $v->id,
                'on_hand' => number_format((float) $spec['stock'], 4, '.', ''),
                'reserved' => '0.0000',
                'updated_at' => now(),
            ]);
        }

        return $product;
    }

    public function test_short_code_and_sku_auto_add_to_customer_apartado_and_reserve_stock(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BOT_REPLY']]], 200),
        ]);

        // Trusted customer reserves immediately without deposit
        Customer::create([
            'store_id' => $this->store->id,
            'full_name' => 'Laura Diaz',
            'phone' => '+18298144525',
            'status' => 'active',
            'allow_reservation_without_deposit' => true,
        ]);

        $product = $this->createProductWithStock('Brazalete Dorado', [
            ['sku' => 'BRZ-01', 'price' => '14.0000', 'stock' => 5],
        ]);

        // Trusted customer sends short code K-{id}
        $this->postWebhook([[
            'id' => 'wamid.IN_1',
            'from' => '18298144525',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'Quiero K-'.$product->id],
        ]])->assertOk();

        $order = SalesOrder::where('store_id', $this->store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('reserved', $order->status);
        $this->assertSame('14.0000', (string) $order->grand_total);
        $this->assertDatabaseHas('customers', [
            'store_id' => $this->store->id,
            'full_name' => 'Laura Diaz',
            'phone' => '+18298144525',
        ]);

        // Same customer sends exact SKU x2 -> adds to the SAME open Apartado (total qty 3, $42)
        $this->postWebhook([[
            'id' => 'wamid.IN_2',
            'from' => '18298144525',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'BRZ-01 x2'],
        ]])->assertOk();

        $order->refresh();
        $this->assertSame('42.0000', (string) $order->grand_total);
        $this->assertSame(0, bccomp((string) DB::table('stock_balances')->where('warehouse_id', $this->warehouse->id)->value('reserved'), '3', 4));
    }

    public function test_multi_variant_product_sends_interactive_buttons_and_reserves_on_selection(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.BOT_BTN']]], 200),
        ]);

        Customer::create([
            'store_id' => $this->store->id,
            'full_name' => 'Ana Perez',
            'phone' => '+18095551111',
            'status' => 'active',
            'allow_reservation_without_deposit' => true,
        ]);

        $product = $this->createProductWithStock('Vestido Verano', [
            ['sku' => 'VES-S', 'size' => 'S', 'price' => '25.0000', 'stock' => 2],
            ['sku' => 'VES-M', 'size' => 'M', 'price' => '25.0000', 'stock' => 3],
        ]);
        $variantM = ProductVariant::where('sku', 'VES-M')->firstOrFail();

        // Sends only K-{id} -> bot asks with interactive buttons
        $this->postWebhook([[
            'id' => 'wamid.MULTI_1',
            'from' => '18095551111',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'K-'.$product->id],
        ]], '18095551111', 'Ana Perez')->assertOk();

        $this->assertSame(0, SalesOrder::count());
        Http::assertSent(fn ($req) => ($req['type'] ?? null) === 'interactive' && ($req['interactive']['type'] ?? null) === 'button');

        // Customer taps button for M -> immediately reserves variant M
        $this->postWebhook([[
            'id' => 'wamid.MULTI_2',
            'from' => '18095551111',
            'timestamp' => (string) now()->timestamp,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button_reply',
                'button_reply' => [
                    'id' => 'AUTO_ADD:'.$variantM->id.':1',
                    'title' => 'M',
                ],
            ],
        ]], '18095551111', 'Ana Perez')->assertOk();

        $order = SalesOrder::where('store_id', $this->store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('25.0000', (string) $order->grand_total);
    }

    public function test_unsupported_deleted_message_renders_clean_label_and_normal_chat_stays_silent(): void
    {
        Http::fake();

        $this->postWebhook([[
            'id' => 'wamid.CHAT_1',
            'from' => '18298144525',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'Agregame ese brazalete por favor'],
        ]])->assertOk();

        $this->postWebhook([[
            'id' => 'wamid.DEL_1',
            'from' => '18298144525',
            'timestamp' => (string) now()->timestamp,
            'type' => 'unsupported',
            'errors' => [['code' => 131051, 'title' => 'Unsupported message type']],
        ]])->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_messages', [
            'provider_message_id' => 'wamid.DEL_1',
            'body' => '🚫 El cliente eliminó un mensaje',
            'status' => 'deleted',
        ]);
    }

    public function test_public_catalog_page_and_multi_code_auto_apartado(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.MULTI_BOT']]], 200),
        ]);

        Customer::create([
            'store_id' => $this->store->id,
            'full_name' => 'Laura Diaz',
            'phone' => '+18298144525',
            'status' => 'active',
            'allow_reservation_without_deposit' => true,
        ]);

        $p1 = $this->createProductWithStock('Collar Perla', [['sku' => 'COL-01', 'price' => '20.0000', 'stock' => 4]]);
        $p2 = $this->createProductWithStock('Aretes Oro', [['sku' => 'ARE-01', 'price' => '15.0000', 'stock' => 4]]);

        // Public catalog renders without auth and never exposes cost_price
        $this->get('/catalogo/'.$this->store->id)
            ->assertOk()
            ->assertSee('Collar Perla')
            ->assertSee('K-'.$p1->id)
            ->assertDontSee('cost_price');

        // Multi-code message from Public Catalog cart reserves both products into one Apartado ($35.00)
        $this->postWebhook([[
            'id' => 'wamid.CART_1',
            'from' => '18298144525',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => "K-{$p1->id}\nK-{$p2->id}"],
        ]])->assertOk();

        $order = SalesOrder::where('store_id', $this->store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('35.0000', (string) $order->grand_total);
        $this->assertSame(2, $order->items()->count());
    }

    public function test_new_customer_creates_pending_deposit_request_without_locking_stock_and_confirms_later(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.DEP_BOT']]], 200),
        ]);

        $product = $this->createProductWithStock('Correa Negra', [
            ['sku' => 'COR-01', 'price' => '30.0000', 'stock' => 1],
        ]);
        $variant = ProductVariant::where('sku', 'COR-01')->firstOrFail();

        // New (untrusted) customer requests last belt via WhatsApp
        $this->postWebhook([[
            'id' => 'wamid.NEW_1',
            'from' => '18097778888',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'K-'.$product->id],
        ]], '18097778888', 'Cliente Nueva')->assertOk();

        // Stock is NOT locked and no SalesOrder is created yet
        $this->assertSame(0, SalesOrder::count());
        $this->assertSame(0, bccomp((string) DB::table('stock_balances')->where('variant_id', $variant->id)->value('reserved'), '0', 4));
        $this->assertDatabaseHas('whatsapp_reservation_requests', [
            'store_id' => $this->store->id,
            'variant_id' => $variant->id,
            'status' => 'pending_deposit',
        ]);
        Http::assertSent(fn ($req) => str_contains((string) ($req['text']['body'] ?? ''), 'pendiente de depósito'));

        // Owner lists pending requests and confirms deposit + marks customer trusted
        $listRes = $this->actingAs($this->owner)
            ->getJson('/api/v1/business/stores/'.$this->store->id.'/whatsapp/inbox/reservation-requests')
            ->assertOk();
        $requestId = $listRes->json('data.0.id');
        $this->assertNotNull($requestId);
        $this->assertTrue($listRes->json('data.0.in_stock'));

        $confirmRes = $this->actingAs($this->owner)
            ->postJson('/api/v1/business/stores/'.$this->store->id.'/whatsapp/inbox/reservation-requests/'.$requestId.'/confirm', [
                'deposit_amount' => '10.00',
                'payment_method' => 'zelle',
                'mark_trusted' => true,
            ])
            ->assertOk();

        $orderId = $confirmRes->json('data.order_id');
        $order = SalesOrder::findOrFail($orderId);
        $this->assertSame('30.0000', (string) $order->grand_total);
        $this->assertSame(0, bccomp((string) DB::table('payments')->where('store_id', $this->store->id)->sum('amount'), '10', 4));
        $this->assertSame(0, bccomp((string) DB::table('stock_balances')->where('variant_id', $variant->id)->value('reserved'), '1', 4));
        $this->assertDatabaseHas('customers', [
            'phone' => '+18097778888',
            'allow_reservation_without_deposit' => true,
        ]);
        $this->assertDatabaseHas('whatsapp_reservation_requests', [
            'id' => $requestId,
            'status' => 'confirmed',
            'confirmed_order_id' => $orderId,
        ]);
    }

    public function test_confirming_pending_deposit_request_fails_409_when_last_unit_was_taken_by_trusted_buyer(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.RACE_BOT']]], 200),
        ]);

        Customer::create([
            'store_id' => $this->store->id,
            'full_name' => 'Clienta Fiel',
            'phone' => '+18099990000',
            'status' => 'active',
            'allow_reservation_without_deposit' => true,
        ]);

        $product = $this->createProductWithStock('Correa Unica', [
            ['sku' => 'COR-U1', 'price' => '25.0000', 'stock' => 1],
        ]);

        // 1. Untrusted buyer requests the last unit -> pending_deposit, stock still available!
        $this->postWebhook([[
            'id' => 'wamid.RACE_1',
            'from' => '18091112222',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'K-'.$product->id],
        ]], '18091112222', 'Compradora Nueva')->assertOk();

        // 2. Trusted buyer requests the same unit -> reserves immediately because it wasn't locked
        $this->postWebhook([[
            'id' => 'wamid.RACE_2',
            'from' => '18099990000',
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'K-'.$product->id],
        ]], '18099990000', 'Clienta Fiel')->assertOk();

        $this->assertSame(1, SalesOrder::count());

        // 3. Trying to confirm the untrusted buyer's request now returns 409 (no overselling)
        $requestId = DB::table('whatsapp_reservation_requests')->value('id');
        $this->actingAs($this->owner)
            ->postJson('/api/v1/business/stores/'.$this->store->id.'/whatsapp/inbox/reservation-requests/'.$requestId.'/confirm', [
                'deposit_amount' => '10.00',
            ])
            ->assertStatus(409);
    }
}
