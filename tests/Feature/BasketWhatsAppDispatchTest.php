<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BasketWhatsAppDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoStores(): array
    {
        BusinessProfile::create([
            'id' => 1,
            'name' => 'KeylaShop',
            'default_currency' => 'USD',
            'currency_decimals' => 2,
            'timezone' => 'America/Santo_Domingo',
            'locale' => 'es_DO',
        ]);
        DB::table('business_settings')->insert([
            'id' => 1,
            'weekly_close_weekday' => 4,
            'sales_tax_rate' => '0.0000',
            'invoice_prefix' => 'FAC',
        ]);

        $ownerRole = Role::create([
            'code' => 'owner',
            'label' => 'Propietario',
            'permissions' => [
                'business.manage', 'stores.view', 'stores.manage', 'warehouses.manage',
                'members.manage', 'catalog.view', 'catalog.manage', 'catalog.costs',
                'inventory.view', 'inventory.manage', 'customers.view', 'customers.manage',
                'sales.view', 'sales.create', 'invoices.view', 'payments.record',
            ],
        ]);

        $storeA = Store::create(['name' => 'KeylaShop', 'code' => 'KEYLA', 'is_active' => true]);
        $whA = Warehouse::create(['store_id' => $storeA->id, 'name' => 'Principal Keyla', 'is_default' => true, 'is_active' => true]);
        $userA = User::create(['name' => 'Keyla Owner', 'email' => 'keyla@example.com', 'password' => bcrypt('password123'), 'is_active' => true]);
        $userA->roles()->attach($ownerRole->id);
        DB::table('store_user')->insert(['user_id' => $userA->id, 'store_id' => $storeA->id]);

        $storeB = Store::create(['name' => 'Bsolutions', 'code' => 'BSOLUTIONS', 'is_active' => true]);
        $whB = Warehouse::create(['store_id' => $storeB->id, 'name' => 'Almacén Bsolutions', 'is_default' => true, 'is_active' => true]);
        $userB = User::create(['name' => 'Bsolutions Owner', 'email' => 'bsolutions@example.com', 'password' => bcrypt('password123'), 'is_active' => true]);
        $userB->roles()->attach($ownerRole->id);
        DB::table('store_user')->insert(['user_id' => $userB->id, 'store_id' => $storeB->id]);

        return [$storeA, $whA, $userA, $storeB, $whB, $userB];
    }

    public function test_whatsapp_credentials_are_encrypted_and_strictly_isolated_per_store(): void
    {
        [$storeA, , $userA, $storeB, , $userB] = $this->seedTwoStores();

        $this->actingAs($userA)->patchJson('/api/v1/business/integrations/whatsapp', [
            'store_id' => $storeA->id,
            'enabled' => true,
            'phone_number_id' => '111222333444',
            'template' => 'factura_keyla',
            'language' => 'es',
            'graph_version' => 'v23.0',
            'body_fields' => ['customer_name', 'invoice_number', 'total'],
            'access_token' => 'SECRET_KEYLA_TOKEN_123456',
            'app_secret' => 'SECRET_KEYLA_APP_123456',
            'verify_token' => 'verify_keyla_token_123456',
        ])->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.has_access_token', true)
            ->assertJsonMissingPath('data.access_token');

        // Verify stored encrypted in DB (not plaintext)
        $rowA = DB::table('store_whatsapp_configs')->where('store_id', $storeA->id)->first();
        $this->assertNotNull($rowA);
        $this->assertNotSame('SECRET_KEYLA_TOKEN_123456', $rowA->access_token_encrypted);
        $this->assertStringNotContainsString('SECRET_KEYLA_TOKEN_123456', (string) $rowA->access_token_encrypted);

        // Store B sees NOTHING from Store A
        $this->actingAs($userB)->getJson("/api/v1/business/integrations/whatsapp?store_id={$storeB->id}")
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.ready', false)
            ->assertJsonPath('data.has_access_token', false)
            ->assertJsonPath('data.phone_number_id', null);

        // User B cannot read or modify Store A's WhatsApp config
        $this->actingAs($userB)->getJson("/api/v1/business/integrations/whatsapp?store_id={$storeA->id}")
            ->assertNotFound();
    }

    public function test_store_can_schedule_preview_and_bulk_dispatch_apartados_invoices(): void
    {
        Http::fake([
            '*/media' => Http::response(['id' => 'media_bsol_01']),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid_bsol_01']]]),
        ]);

        [, , , $storeB, $whB, $userB] = $this->seedTwoStores();

        // Configure WhatsApp API for Store B
        $this->actingAs($userB)->patchJson('/api/v1/business/integrations/whatsapp', [
            'store_id' => $storeB->id,
            'enabled' => true,
            'phone_number_id' => '999888777666',
            'template' => 'factura_bsolutions',
            'language' => 'es',
            'graph_version' => 'v23.0',
            'body_fields' => ['customer_name', 'invoice_number', 'total'],
            'access_token' => 'SECRET_BSOL_TOKEN_999999',
            'app_secret' => 'SECRET_BSOL_APP_999999',
            'verify_token' => 'verify_bsol_token_999999',
        ])->assertOk()->assertJsonPath('data.ready', true);

        // Create a service and a customer in Store B
        $prod = $this->actingAs($userB)->postJson('/api/v1/business/products', [
            'store_id' => $storeB->id,
            'name' => 'Landing Page',
            'product_type' => 'service',
            'unit_code' => 'service',
            'track_stock' => false,
            'status' => 'active',
            'variants' => [[
                'sku' => 'BSOL-LAND-TEST',
                'sale_price' => '200.00',
                'cost_price' => null,
                'attribute_value_ids' => [],
                'is_active' => true,
            ]],
        ])->assertCreated()->json('data');

        $variantId = DB::table('product_variants')->where('product_id', $prod['id'])->value('id');
        $customer = Customer::create(['store_id' => $storeB->id, 'full_name' => 'Daniel', 'phone' => '+18092374444', 'status' => 'active']);

        // Create a reservation (apartado) and issue its invoice
        $draft = [
            'warehouse_id' => $whB->id,
            'customer_id' => $customer->id,
            'lines' => [['variant_id' => $variantId, 'quantity' => '1']],
            'discount' => '0',
            'shipping' => '0',
        ];
        $quoteHash = $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/sales/quote", $draft)->assertOk()->json('data.quote_hash');
        $orderId = $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/sales/orders", [
            ...$draft,
            'mode' => 'reservation',
            'initial_payment' => '0',
            'payment_method' => 'cash',
            'request_key' => (string) Str::uuid(),
            'quote_hash' => $quoteHash,
        ])->assertCreated()->json('data.order.id');

        $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/sales/orders/{$orderId}/invoice", [
            'request_key' => (string) Str::uuid(),
            'version' => 0,
        ])->assertOk();

        // Save schedule for Store B
        $this->actingAs($userB)->patchJson("/api/v1/business/stores/{$storeB->id}/baskets/whatsapp-dispatch/schedule", [
            'enabled' => true,
            'weekday' => 4,
            'time' => '18:00',
            'scope' => 'open_and_invoiced_pending',
            'message' => 'Hola {cliente}, factura {factura} por {pendiente}.',
        ])->assertOk()
            ->assertJsonPath('data.schedule.enabled', true)
            ->assertJsonPath('data.schedule.weekday', 4)
            ->assertJsonPath('data.schedule.time', '18:00');

        // Overview lists Daniel with valid WhatsApp and wa.me URL
        $overview = $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/baskets/whatsapp-dispatch")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $overview['recipients']);
        $this->assertSame('Daniel', $overview['recipients'][0]['customer_name']);
        $this->assertTrue($overview['recipients'][0]['phone_valid']);
        $this->assertTrue($overview['recipients'][0]['has_invoice']);
        $this->assertStringContainsString('https://wa.me/18092374444?text=', $overview['recipients'][0]['wa_me_url']);

        // Trigger manual bulk dispatch now
        $batch = $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/baskets/whatsapp-dispatch/send", [
            'customer_ids' => [$customer->id],
        ])->assertCreated()->json('data.batch');

        $this->assertSame(1, $batch['sent_count']);
        $this->assertSame(0, $batch['failed_count']);
        Http::assertSentCount(2);

        // Save all 8 automations + owner_phone for Store B
        $this->actingAs($userB)->patchJson("/api/v1/business/stores/{$storeB->id}/whatsapp/automations", [
            'owner_phone' => '+18092374444',
            'daily_summary_time' => '21:00',
            'rules' => [
                'payment_receipt' => ['enabled' => true, 'template' => 'recibo_abono'],
                'order_dispatched' => ['enabled' => true, 'template' => 'pedido_despachado'],
                'receivables_reminder' => ['enabled' => true, 'template' => 'recordatorio_saldo'],
                'expiry_warning' => ['enabled' => true, 'template' => 'apartado_por_vencer', 'hours_before' => 24],
                'weekly_close_pdf' => ['enabled' => true, 'template' => 'cuenta_semanal_pdf'],
                'catalog_link' => ['enabled' => true, 'template' => 'enlace_catalogo_cliente'],
                'owner_daily_summary' => ['enabled' => true, 'template' => 'resumen_diario_dueno'],
                'low_stock_alert' => ['enabled' => true, 'template' => 'alerta_stock_bajo', 'threshold' => 2],
            ],
        ])->assertOk()
            ->assertJsonPath('data.owner_phone', '+18092374444')
            ->assertJsonPath('data.rules.payment_receipt.enabled', true);

        // Trigger receivables reminder, catalog link, and owner daily summary test
        $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/whatsapp/receivables-reminder", [
            'customer_id' => $customer->id,
            'pending_total' => '200.00',
            'reference' => 'FAC-000001',
        ])->assertOk()->assertJsonPath('data.status', 'sent');

        $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/whatsapp/catalog-link", [
            'customer_id' => $customer->id,
            'share_url' => 'https://keylashop.bsolutions.dev/s/abc123',
            'expires_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.status', 'sent');

        $this->actingAs($userB)->postJson("/api/v1/business/stores/{$storeB->id}/whatsapp/owner-summary/test")
            ->assertOk()->assertJsonPath('data.status', 'sent');

        $logs = $this->actingAs($userB)->getJson("/api/v1/business/stores/{$storeB->id}/whatsapp/automations")
            ->assertOk()->json('data.logs');
        $this->assertGreaterThanOrEqual(3, count($logs));
    }
}
