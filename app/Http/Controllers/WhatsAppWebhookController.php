<?php

namespace App\Http\Controllers;

use App\Domain\Sales\WhatsAppConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppConfiguration $configuration)
    {
        $c = $configuration->resolve();
        $token = (string) ($request->query('hub_verify_token') ?? $request->query('hub.verify_token'));
        abort_unless(filled($c['verify_token']) && hash_equals($c['verify_token'], $token) && ($request->query('hub_mode') ?? $request->query('hub.mode')) === 'subscribe', 403);
        $challenge = (string) ($request->query('hub_challenge') ?? $request->query('hub.challenge'));
        abort_unless(preg_match('/^[0-9]{1,100}$/D', $challenge), 422);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WhatsAppConfiguration $configuration)
    {
        $c = $configuration->resolve();
        $raw = $request->getContent();
        abort_if(strlen($raw) > 1048576, 413);
        abort_unless(filled($c['app_secret']) && hash_equals('sha256='.hash_hmac('sha256', $raw, $c['app_secret']), (string) $request->header('X-Hub-Signature-256')), 403);
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
