<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Sales\WhatsAppInboxService;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WhatsAppInboxTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private string $secret = 'real_app_secret_for_tests_123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, [
            'name' => 'Inbox Shop',
            'default_currency' => 'USD',
            'timezone' => 'America/Santo_Domingo',
            'store_name' => 'Principal',
        ]);
        $this->store = Store::firstOrFail();

        DB::table('store_whatsapp_configs')->insert([
            'store_id' => $this->store->id,
            'enabled' => true,
            'phone_number_id' => '1419120884625599',
            'template' => 'factura_cliente',
            'language' => 'es',
            'graph_version' => 'v25.0',
            'body_fields' => json_encode(['customer_name', 'invoice_number', 'total', 'business_name']),
            'access_token_encrypted' => Crypt::encryptString('EAAtesttoken'),
            'app_secret_encrypted' => Crypt::encryptString($this->secret),
            'verify_token_encrypted' => Crypt::encryptString('verify_token_1234567'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'store_id' => $this->store->id,
            'full_name' => 'Ingrid Nova',
            'phone' => '++1 (829) 814-4525',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postWebhook(array $payload)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $this->secret),
        ], $raw);
    }

    private function inboundPayload(string $id, string $text): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => '1419120884625599'],
            'contacts' => [['wa_id' => '18298144525', 'profile' => ['name' => 'Ingrid']]],
            'messages' => [[
                'id' => $id,
                'from' => '18298144525',
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'text' => ['body' => $text],
            ]],
        ]]]]]];
    }

    public function test_phone_normalization_handles_double_plus_and_ten_digits(): void
    {
        $this->assertSame('+18298144525', WhatsAppInboxService::normalizePhone('++1 (829) 814-4525'));
        $this->assertSame('+13475256436', WhatsAppInboxService::normalizePhone('347-525-6436'));
        $this->assertSame('+18092374444', WhatsAppInboxService::normalizePhone('+1 809 237 4444'));
        $this->assertNull(WhatsAppInboxService::normalizePhone('123'));
    }

    public function test_inbound_message_creates_conversation_links_customer_and_marks_unread(): void
    {
        $this->postWebhook($this->inboundPayload('wamid.TEST1', 'Hola, ¿ya salió mi pedido?'))->assertOk();
        // Duplicate delivery from Meta must be idempotent
        $this->postWebhook($this->inboundPayload('wamid.TEST1', 'Hola, ¿ya salió mi pedido?'))->assertOk();

        $root = "/api/v1/business/stores/{$this->store->id}/whatsapp/inbox";

        $summary = $this->actingAs($this->owner)->getJson("{$root}/unread")->assertOk();
        $summary->assertJsonPath('data.unread_messages', 1)
            ->assertJsonPath('data.unread_conversations', 1)
            ->assertJsonPath('data.latest_inbound.sender_name', 'Ingrid Nova');

        $list = $this->actingAs($this->owner)->getJson("{$root}/conversations")->assertOk();
        $list->assertJsonPath('data.conversations.0.display_name', 'Ingrid Nova')
            ->assertJsonPath('data.conversations.0.wa_phone', '+18298144525')
            ->assertJsonPath('data.conversations.0.window_open', true)
            ->assertJsonPath('data.needs_app_secret', false);

        $convId = $list->json('data.conversations.0.id');
        $thread = $this->actingAs($this->owner)->getJson("{$root}/conversations/{$convId}")->assertOk();
        $thread->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.body', 'Hola, ¿ya salió mi pedido?')
            ->assertJsonPath('data.messages.0.direction', 'inbound');

        $this->actingAs($this->owner)->getJson("{$root}/unread")->assertJsonPath('data.unread_messages', 0);
    }

    public function test_invalid_signature_is_rejected_and_other_store_cannot_read(): void
    {
        $raw = json_encode($this->inboundPayload('wamid.BAD', 'x'));
        $this->call('POST', '/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], $raw)->assertForbidden();
        $this->assertSame(0, DB::table('whatsapp_messages')->count());

        $this->postWebhook($this->inboundPayload('wamid.TEST2', 'Hola'))->assertOk();
        $convId = DB::table('whatsapp_conversations')->value('id');

        $other = Store::create(['name' => 'Otra', 'code' => 'OTRA', 'is_active' => true]);
        DB::table('store_user')->insert(['store_id' => $other->id, 'user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$other->id}/whatsapp/inbox/conversations/{$convId}")
            ->assertNotFound();
        $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$other->id}/whatsapp/inbox/conversations")
            ->assertOk()
            ->assertJsonCount(0, 'data.conversations');
    }
}
