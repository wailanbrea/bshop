<?php

namespace App\Domain\Sales;

use App\Domain\Business\StoreContext;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WhatsAppInvoice
{
    public function __construct(private WhatsAppConfiguration $configuration, private InvoiceDocument $documents, private SalesService $sales) {}

    public function send(StoreContext $context, int $invoiceId, string $key, User $actor): array
    {
        abort_unless(Str::isUuid($key), 422, 'Identificador de envío no válido.');
        $c = $this->configuration->resolve();
        abort_unless($this->configuration->status()['ready'], 409, 'WhatsApp es opcional y todavía no está habilitado/configurado.');
        abort_unless(preg_match('/^v[0-9]{1,2}\.0$/D', $c['graph_version']) && preg_match('/^[0-9]{5,40}$/D', $c['phone_number_id']), 422, 'Configuración del proveedor no válida.');
        $invoice = DB::table('invoices')->where('store_id', $context->store()->id)->where('id', $invoiceId)->firstOrFail();
        $snapshot = OrderInvoice::customer($invoice);
        $phone = preg_replace('/[\s().-]+/', '', Customer::withTrashed()->findOrFail($invoice->customer_id)->phone ?? '');
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Registra un teléfono internacional con + y código de país.']);
        }
        $directory = Storage::disk('private_invoices')->path('_locks');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            abort(503, 'No se pudo bloquear el envío.');
        }
        $handle = fopen($directory.'/send-'.$invoiceId.'.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            } abort(409, 'El envío se está procesando. Consulta su estado.');
        }
        try {
            $delivery = DB::transaction(function () use ($invoice, $context, $actor, $key, $phone, $c) {
                $this->sales->authorize($context, $actor);
                DB::table('invoices')->where('id', $invoice->id)->lockForUpdate()->firstOrFail();
                $same = DB::table('invoice_deliveries')->where('request_key', $key)->lockForUpdate()->first();
                if ($same) {
                    abort_unless($same->invoice_id === $invoice->id && $same->created_by_user_id === $actor->id, 404);

                    return $same;
                }
                $active = DB::table('invoice_deliveries')->where('invoice_id', $invoice->id)->whereIn('status', ['sending', 'unknown', 'accepted', 'sent', 'delivered', 'read'])->lockForUpdate()->first();
                if ($active) {
                    return $active;
                }
                $id = (string) Str::orderedUuid();
                DB::table('invoice_deliveries')->insert(['id' => $id, 'invoice_id' => $invoice->id, 'store_id' => $invoice->store_id, 'created_by_user_id' => $actor->id, 'request_key' => $key, 'destination' => $phone, 'sender_phone_id' => $c['phone_number_id'], 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);

                return DB::table('invoice_deliveries')->where('id', $id)->firstOrFail();
            });
            if ($delivery->status !== 'pending') {
                return $this->public($delivery);
            }
            try {
                $this->documents->ensure($invoiceId);
                $doc = DB::table('invoice_documents')->where('invoice_id', $invoiceId)->where('version', 1)->where('status', 'ready')->firstOrFail();
                $path = Storage::disk('private_invoices')->path($doc->object_key);
                $http = Http::withToken($c['access_token'])->acceptJson()->connectTimeout(5)->timeout(30);
                $stream = fopen($path, 'rb');
                try {
                    $upload = $http->attach('file', $stream, $invoice->invoice_number.'.pdf', ['Content-Type' => 'application/pdf'])->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/media', ['type' => 'application/pdf', 'messaging_product' => 'whatsapp']);
                } finally {
                    fclose($stream);
                }
                if (! $upload->successful() || ! is_string($upload->json('id'))) {
                    $this->state($delivery->id, 'failed', 'media_upload_failed');

                    return $this->fresh($delivery->id);
                }
                $storeName = DB::table('stores')->where('id', $invoice->store_id)->value('name');
                $snapshotBusiness = json_decode($invoice->business_snapshot, true);
                $values = ['customer_name' => $snapshot['full_name'], 'invoice_number' => $invoice->invoice_number, 'total' => ($invoice->currency === 'USD' ? 'US$ ' : $invoice->currency.' ').bcadd((string) $invoice->grand_total, '0', 2), 'business_name' => $storeName ?: ($snapshotBusiness['name'] ?? 'BShop')];
                $components = [['type' => 'header', 'parameters' => [['type' => 'document', 'document' => ['id' => $upload->json('id'), 'filename' => $invoice->invoice_number.'.pdf']]]]];
                if ($c['body_fields']) {
                    $components[] = ['type' => 'body', 'parameters' => array_map(fn ($field) => ['type' => 'text', 'text' => (string) $values[$field]], $c['body_fields'])];
                }
                // No automatic HTTP retry: timeout after message submission has an unknown outcome.
                $this->state($delivery->id, 'sending');
                $response = Http::withToken($c['access_token'])->acceptJson()->connectTimeout(5)->timeout(30)->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/messages', ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => ltrim($delivery->destination, '+'), 'type' => 'template', 'template' => ['name' => $c['template'], 'language' => ['code' => $c['language']], 'components' => $components], 'biz_opaque_callback_data' => $delivery->id]);
                if ($response->successful() && is_string($response->json('messages.0.id'))) {
                    DB::table('invoice_deliveries')->where('id', $delivery->id)->whereIn('status', ['sending', 'pending'])->update(['status' => 'accepted', 'provider_message_id' => $response->json('messages.0.id'), 'accepted_at' => now(), 'updated_at' => now()]);
                } elseif ($response->status() >= 500 || $response->successful()) {
                    $this->state($delivery->id, 'unknown', 'provider_outcome_unknown');
                } else {
                    $this->state($delivery->id, 'failed', 'provider_rejected');
                }
            } catch (\Throwable) {
                $current = DB::table('invoice_deliveries')->where('id', $delivery->id)->first();
                if (in_array($current->status, ['pending', 'sending'], true)) {
                    $this->state($delivery->id, $current->status === 'sending' ? 'unknown' : 'failed', $current->status === 'sending' ? 'provider_outcome_unknown' : 'preparation_failed');
                }
            }

            return $this->fresh($delivery->id);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function state(string $id, string $status, ?string $error = null): void
    {
        DB::table('invoice_deliveries')->where('id', $id)->whereIn('status', ['pending', 'sending'])->update(['status' => $status, 'error_code' => $error, 'attempts' => DB::raw($status === 'sending' ? 'attempts + 1' : 'attempts'), 'updated_at' => now()]);
    }

    private function fresh(string $id): array
    {
        return $this->public(DB::table('invoice_deliveries')->where('id', $id)->firstOrFail());
    }

    private function public(object $row): array
    {
        return ['id' => $row->id, 'status' => $row->status, 'error_code' => $row->error_code, 'sent_at' => $row->sent_at, 'delivered_at' => $row->delivered_at, 'read_at' => $row->read_at];
    }
}
