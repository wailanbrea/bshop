<?php

namespace App\Http\Controllers;

use App\Domain\Sales\WhatsAppConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    /**
     * @return list<array<string, mixed>>
     */
    private function allConfigs(WhatsAppConfiguration $configuration): array
    {
        $configs = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('store_whatsapp_configs')) {
            $storeIds = DB::table('store_whatsapp_configs')->pluck('store_id')->all();
            foreach ($storeIds as $sid) {
                $configs[] = $configuration->resolve((int) $sid);
            }
        }
        $configs[] = $configuration->resolve();

        return $configs;
    }

    public function verify(Request $request, WhatsAppConfiguration $configuration)
    {
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'));
        $mode = (string) ($request->query('hub_mode') ?? $request->query('hub.mode'));
        $matched = false;
        if ($mode === 'subscribe' && $token !== '') {
            foreach ($this->allConfigs($configuration) as $c) {
                if (filled($c['verify_token'] ?? null) && hash_equals((string) $c['verify_token'], $token)) {
                    $matched = true;
                    break;
                }
            }
        }
        abort_unless($matched, 403);
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge'));
        abort_unless(preg_match('/^[0-9]{1,100}$/D', $challenge), 422);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WhatsAppConfiguration $configuration)
    {
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        $signature = (string) $request->header('X-Hub-Signature-256');
        $matched = false;
        foreach ($this->allConfigs($configuration) as $c) {
            if (filled($c['app_secret'] ?? null) && hash_equals('sha256='.hash_hmac('sha256', $raw, (string) $c['app_secret']), $signature)) {
                $matched = true;
                break;
            }
        }
        abort_unless($matched, 403);
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];
                $sender = (string) ($value['metadata']['phone_number_id'] ?? '');
                foreach (($value['statuses'] ?? []) as $status) {
                    if (! in_array($status['status'] ?? '', ['sent', 'delivered', 'read', 'failed'], true) || ! is_string($status['id'] ?? null)) {
                        continue;
                    }
                    DB::transaction(function () use ($status, $sender) {
                        $query = DB::table('invoice_deliveries')->where('sender_phone_id', $sender);
                        if (isset($status['biz_opaque_callback_data'])) {
                            $query->where('id', $status['biz_opaque_callback_data']);
                        } else {
                            $query->where('provider_message_id', $status['id']);
                        }
                        $row = $query->lockForUpdate()->first();
                        if (! $row || ($row->provider_message_id && $row->provider_message_id !== $status['id']) || ltrim($row->destination, '+') !== (string) ($status['recipient_id'] ?? '')) {
                            return;
                        }
                        $rank = ['pending' => 0, 'sending' => 0, 'unknown' => 0, 'accepted' => 1, 'failed' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];
                        $next = $status['status'];
                        if (($rank[$row->status] ?? 0) >= 3 && $next === 'failed') {
                            return;
                        }
                        if ($next !== 'failed' && ($rank[$next] ?? 0) < ($rank[$row->status] ?? 0)) {
                            return;
                        }
                        $timestamp = $status['timestamp'] ?? null;
                        if (! is_scalar($timestamp) || ! ctype_digit((string) $timestamp)) {
                            return;
                        }
                        $time = CarbonImmutable::createFromTimestampUTC((int) $timestamp);
                        if ($row->event_at && $time->lessThan(CarbonImmutable::parse($row->event_at, 'UTC'))) {
                            return;
                        }
                        $update = ['status' => $next, 'provider_message_id' => $status['id'], 'event_at' => $time, 'updated_at' => now(), 'error_code' => $next === 'failed' ? 'delivery_failed' : null];
                        if ($next !== 'failed') {
                            $update[$next.'_at'] = $time;
                        }
                        DB::table('invoice_deliveries')->where('id', $row->id)->update($update);
                    });
                }
            }
        }

        return response()->json(['ok' => true]);
    }
}
