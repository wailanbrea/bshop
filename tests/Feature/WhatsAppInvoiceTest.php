<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppInvoiceTest extends BasketBoardTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['whatsapp.enabled' => true, 'whatsapp.access_token' => 'private-test-token', 'whatsapp.phone_number_id' => '12345678', 'whatsapp.app_secret' => 'test-app-secret', 'whatsapp.verify_token' => 'private-verify-token-qa', 'whatsapp.template' => 'invoice_qa', 'whatsapp.language' => 'es']);
    }

    private function invoice(): int
    {
        $id = $this->basket();
        $this->edit($id, 0, '1')->assertOk();

        return $this->postJson($this->root()."/sales/orders/$id/invoice", ['version' => 1, 'request_key' => (string) Str::uuid()])->assertOk()->json('data.order.invoice.id');
    }

    private function webhook(string $delivery, string $state)
    {
        $payload = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => '12345678'], 'statuses' => [['id' => 'wamid.qa', 'biz_opaque_callback_data' => $delivery, 'recipient_id' => '12025550101', 'status' => $state, 'timestamp' => (string) time()]]]]]]]];
        $raw = json_encode($payload);

        return $this->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, 'test-app-secret')], $raw);
    }

    public function test_pdf_is_uploaded_privately_and_one_message_is_accepted_not_falsely_sent(): void
    {
        Http::fake(['*/media' => Http::response(['id' => 'media.qa']), '*/messages' => Http::response(['messages' => [['id' => 'wamid.qa']]])]);
        $invoice = $this->invoice();
        $key = (string) Str::uuid();
        $id = $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => $key])->assertOk()->assertJsonPath('data.status', 'accepted')->json('data.id');
        $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => $key])->assertOk();
        $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.id', $id);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('invoices', 1);
        $this->getJson($this->root().'/baskets?filter=invoice_sent')->assertJsonCount(0, 'data');
        $this->webhook($id, 'sent')->assertOk();
        $this->webhook($id, 'delivered')->assertOk();
        $this->webhook($id, 'sent')->assertOk();
        $this->assertDatabaseHas('invoice_deliveries', ['id' => $id, 'status' => 'delivered']);
        $this->getJson($this->root().'/baskets?filter=invoice_sent')->assertJsonCount(1, 'data');
    }

    public function test_unknown_submission_is_never_automatically_retried(): void
    {
        $submissions = 0;
        Http::fake(function ($r) use (&$submissions) {
            if (str_ends_with($r->url(), '/media')) {
                return Http::response(['id' => 'media.qa']);
            } $submissions++;
            throw new ConnectionException('Timeout after submission');
        });
        $invoice = $this->invoice();
        $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.status', 'unknown');
        $this->assertSame(1, $submissions);
        $this->assertDatabaseCount('invoice_deliveries', 1);
    }

    public function test_disabled_configuration_never_sends_and_secrets_are_encrypted_redacted(): void
    {
        $response = $this->patchJson('/api/v1/business/integrations/whatsapp', ['enabled' => false, 'phone_number_id' => '12345678', 'template' => 'invoice_qa', 'language' => 'es', 'graph_version' => 'v23.0', 'body_fields' => ['customer_name'], 'access_token' => 'new-private-qa-token']);
        $response->assertOk()->assertJsonPath('data.enabled', false)->assertJsonMissingPath('data.access_token');
        $this->assertStringNotContainsString('new-private-qa-token', (string) (DB::table('store_whatsapp_configs')->value('access_token_encrypted') ?? DB::table('business_settings')->value('configuration') ?? ''));
        $invoice = $this->invoice();
        $this->postJson($this->root()."/invoices/$invoice/whatsapp", ['request_key' => (string) Str::uuid()])->assertConflict();
        Http::assertNothingSent();
        $this->postJson('/webhooks/whatsapp', [])->assertForbidden();
    }
}
