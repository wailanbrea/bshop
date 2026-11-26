<?php

namespace App\Domain\Sales;

use App\Models\Customer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WhatsAppInboxService
{
    public function __construct(
        private WhatsAppConfiguration $configuration,
    ) {}

    public static function normalizePhone(?string $raw): ?string
    {
        $trimmed = trim((string) ($raw ?? ''));
        if ($trimmed === '') {
            return null;
        }
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed);
        if ($digits === '') {
            return null;
        }
        // Auto-normalize 10-digit US/DR/NANP numbers (e.g. 829-814-4525 or 347-525-6436) to +1XXXXXXXXXX
        if (! $hasPlus && strlen($digits) === 10 && preg_match('/^[2-9][0-9]{9}$/', $digits)) {
            return '+1'.$digits;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            return null;
        }

        return '+'.$digits;
    }

    public function resolveStoreIdByPhoneNumberId(string $senderPhoneId): ?int
    {
        if ($senderPhoneId === '' || ! Schema::hasTable('store_whatsapp_configs')) {
            return null;
        }
        $sid = DB::table('store_whatsapp_configs')
            ->where('phone_number_id', $senderPhoneId)
            ->value('store_id');
        if ($sid !== null) {
            return (int) $sid;
        }

        $defaultCfg = $this->configuration->resolve();
        if (($defaultCfg['phone_number_id'] ?? null) === $senderPhoneId) {
            $primary = DB::table('stores')->orderBy('id')->value('id');

            return $primary ? (int) $primary : null;
        }

        return null;
    }

    public function hasPlaceholderAppSecret(int $storeId): bool
    {
        $c = $this->configuration->resolve($storeId);
        $secret = (string) ($c['app_secret'] ?? '');

        return $secret === '' || str_starts_with($secret, 'temp_');
    }

    private function matchCustomerId(int $storeId, string $e164Phone): ?int
    {
        $digits = ltrim($e164Phone, '+');
        $last10 = strlen($digits) >= 10 ? substr($digits, -10) : $digits;

        $candidates = Customer::where('store_id', $storeId)
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.substr($last10, -4).'%')
            ->get(['id', 'phone']);

        foreach ($candidates as $cust) {
            $cDigits = preg_replace('/\D+/', '', (string) $cust->phone);
            if ($cDigits === $digits || (strlen($last10) === 10 && strlen($cDigits) >= 10 && substr($cDigits, -10) === $last10)) {
                return (int) $cust->id;
            }
        }

        return null;
    }

    public function recordInboundWebhookMessage(int $storeId, array $msg, ?string $profileName = null): void
    {
        if (! Schema::hasTable('whatsapp_conversations') || ! Schema::hasTable('whatsapp_messages')) {
            return;
        }

        $providerId = isset($msg['id']) && is_string($msg['id']) ? $msg['id'] : null;
        $waPhone = self::normalizePhone((string) ($msg['from'] ?? ''));
        if (! $providerId || ! $waPhone) {
            return;
        }

        $ts = isset($msg['timestamp']) && ctype_digit((string) $msg['timestamp'])
            ? CarbonImmutable::createFromTimestampUTC((int) $msg['timestamp'])
            : CarbonImmutable::now('UTC');

        $type = (string) ($msg['type'] ?? 'other');
        $mappedType = in_array($type, ['text', 'image', 'document', 'audio'], true) ? $type : 'other';
        $body = null;
        $mediaId = null;
        $mediaMime = null;
        $mediaFilename = null;

        if ($type === 'text') {
            $body = trim((string) ($msg['text']['body'] ?? ''));
        } elseif ($type === 'image') {
            $mediaId = $msg['image']['id'] ?? null;
            $mediaMime = $msg['image']['mime_type'] ?? 'image/jpeg';
            $body = trim((string) ($msg['image']['caption'] ?? '')) ?: '📷 Imagen recibida';
        } elseif ($type === 'document') {
            $mediaId = $msg['document']['id'] ?? null;
            $mediaMime = $msg['document']['mime_type'] ?? 'application/octet-stream';
            $mediaFilename = $msg['document']['filename'] ?? 'documento';
            $body = trim((string) ($msg['document']['caption'] ?? '')) ?: ('📄 '.$mediaFilename);
        } elseif ($type === 'audio') {
            $mediaId = $msg['audio']['id'] ?? null;
            $mediaMime = $msg['audio']['mime_type'] ?? 'audio/ogg';
            $body = '🎤 Nota de voz';
        } elseif ($type === 'button') {
            $mappedType = 'text';
            $body = trim((string) ($msg['button']['text'] ?? 'Respuesta de botón'));
        } elseif ($type === 'interactive') {
            $mappedType = 'text';
            $body = trim((string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? 'Respuesta interactiva'));
        } elseif ($type === 'reaction') {
            $emoji = (string) ($msg['reaction']['emoji'] ?? '👍');
            $body = "Reacción: {$emoji}";
        } else {
            $body = 'Mensaje recibido ('.$type.')';
        }

        $preview = Str::limit(preg_replace('/\s+/', ' ', (string) $body), 180);

        DB::transaction(function () use ($storeId, $waPhone, $profileName, $providerId, $mappedType, $body, $mediaId, $mediaMime, $mediaFilename, $ts, $preview) {
            $already = DB::table('whatsapp_messages')
                ->where('store_id', $storeId)
                ->where('provider_message_id', $providerId)
                ->exists();
            if ($already) {
                return;
            }

            $conv = DB::table('whatsapp_conversations')
                ->where('store_id', $storeId)
                ->where('wa_phone', $waPhone)
                ->lockForUpdate()
                ->first();

            $customerId = $conv?->customer_id ?: $this->matchCustomerId($storeId, $waPhone);
            $cleanProfile = $profileName ? trim($profileName) : null;

            if (! $conv) {
                $convId = DB::table('whatsapp_conversations')->insertGetId([
                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'wa_phone' => $waPhone,
                    'contact_name' => $cleanProfile,
                    'unread_count' => 1,
                    'last_inbound_at' => $ts,
                    'last_message_at' => $ts,
                    'last_message_preview' => $preview,
                    'last_message_direction' => 'inbound',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $convId = (int) $conv->id;
                $newLastIn = (! $conv->last_inbound_at || $ts->greaterThan(CarbonImmutable::parse($conv->last_inbound_at, 'UTC')))
                    ? $ts
                    : $conv->last_inbound_at;
                $isNewer = ! $conv->last_message_at || $ts->greaterThanOrEqualTo(CarbonImmutable::parse($conv->last_message_at, 'UTC'));

                DB::table('whatsapp_conversations')->where('id', $convId)->update([
                    'customer_id' => $customerId ?: $conv->customer_id,
                    'contact_name' => $cleanProfile ?: $conv->contact_name,
                    'unread_count' => (int) $conv->unread_count + 1,
                    'last_inbound_at' => $newLastIn,
                    'last_message_at' => $isNewer ? $ts : $conv->last_message_at,
                    'last_message_preview' => $isNewer ? $preview : $conv->last_message_preview,
                    'last_message_direction' => $isNewer ? 'inbound' : $conv->last_message_direction,
                    'updated_at' => now(),
                ]);
            }

            DB::table('whatsapp_messages')->insert([
                'conversation_id' => $convId,
                'store_id' => $storeId,
                'direction' => 'inbound',
                'message_type' => $mappedType,
                'body' => $body,
                'media_id' => $mediaId,
                'media_mime' => $mediaMime,
                'media_filename' => $mediaFilename,
                'provider_message_id' => $providerId,
                'status' => 'received',
                'occurred_at' => $ts,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function updateMessageStatusFromWebhook(int $storeId, array $statusPayload): void
    {
        if (! Schema::hasTable('whatsapp_messages')) {
            return;
        }
        $providerId = $statusPayload['id'] ?? null;
        $next = $statusPayload['status'] ?? null;
        if (! is_string($providerId) || ! in_array($next, ['sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }

        $row = DB::table('whatsapp_messages')
            ->where('store_id', $storeId)
            ->where('provider_message_id', $providerId)
            ->first(['id', 'status']);
        if (! $row) {
            return;
        }

        $rank = ['sending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 1];
        if ($next === 'failed' && ($rank[$row->status] ?? 0) >= 2) {
            return;
        }
        if ($next !== 'failed' && ($rank[$next] ?? 0) <= ($rank[$row->status] ?? 0)) {
            return;
        }

        $errCode = null;
        if ($next === 'failed') {
            $errCode = (string) ($statusPayload['errors'][0]['code'] ?? 'delivery_failed');
        }

        DB::table('whatsapp_messages')->where('id', $row->id)->update([
            'status' => $next,
            'error_code' => $errCode,
            'updated_at' => now(),
        ]);
    }

    public function recordOutboundSystemMessage(
        int $storeId,
        ?string $rawPhone,
        string $templateName,
        string $summaryBody,
        ?string $providerMessageId,
        ?string $pdfFilename = null,
        ?int $actorId = null,
    ): void {
        try {
            if (! Schema::hasTable('whatsapp_conversations') || ! Schema::hasTable('whatsapp_messages')) {
                return;
            }
            $waPhone = self::normalizePhone($rawPhone);
            if (! $waPhone) {
                return;
            }
            $now = CarbonImmutable::now('UTC');
            $preview = Str::limit($summaryBody, 180);

            DB::transaction(function () use ($storeId, $waPhone, $templateName, $summaryBody, $providerMessageId, $pdfFilename, $actorId, $now, $preview) {
                if ($providerMessageId && DB::table('whatsapp_messages')->where('store_id', $storeId)->where('provider_message_id', $providerMessageId)->exists()) {
                    return;
                }
                $conv = DB::table('whatsapp_conversations')
                    ->where('store_id', $storeId)
                    ->where('wa_phone', $waPhone)
                    ->lockForUpdate()
                    ->first();

                $customerId = $conv?->customer_id ?: $this->matchCustomerId($storeId, $waPhone);
                $custName = $customerId ? DB::table('customers')->where('id', $customerId)->value('full_name') : null;

                if (! $conv) {
                    $convId = DB::table('whatsapp_conversations')->insertGetId([
                        'store_id' => $storeId,
                        'customer_id' => $customerId,
                        'wa_phone' => $waPhone,
                        'contact_name' => $custName,
                        'unread_count' => 0,
                        'last_message_at' => $now,
                        'last_message_preview' => $preview,
                        'last_message_direction' => 'outbound',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $convId = (int) $conv->id;
                    DB::table('whatsapp_conversations')->where('id', $convId)->update([
                        'customer_id' => $customerId ?: $conv->customer_id,
                        'contact_name' => $conv->contact_name ?: $custName,
                        'last_message_at' => $now,
                        'last_message_preview' => $preview,
                        'last_message_direction' => 'outbound',
                        'updated_at' => now(),
                    ]);
                }

                DB::table('whatsapp_messages')->insert([
                    'conversation_id' => $convId,
                    'store_id' => $storeId,
                    'direction' => 'outbound',
                    'message_type' => 'template',
                    'body' => $summaryBody,
                    'media_filename' => $pdfFilename,
                    'template_name' => $templateName,
                    'provider_message_id' => $providerMessageId,
                    'status' => 'sent',
                    'sent_by_user_id' => $actorId,
                    'occurred_at' => $now,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable) {
            // Never interrupt core billing/automation flow due to chat logging.
        }
    }

    public function unreadSummary(int $storeId): array
    {
        if (! Schema::hasTable('whatsapp_conversations') || ! Schema::hasTable('whatsapp_messages')) {
            return [
                'unread_conversations' => 0,
                'unread_messages' => 0,
                'latest_inbound' => null,
            ];
        }

        $agg = DB::table('whatsapp_conversations')
            ->where('store_id', $storeId)
            ->where('unread_count', '>', 0)
            ->selectRaw('COUNT(*) as convs, COALESCE(SUM(unread_count), 0) as msgs')
            ->first();

        $latest = DB::table('whatsapp_messages as m')
            ->join('whatsapp_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('m.store_id', $storeId)
            ->where('m.direction', 'inbound')
            ->orderByDesc('m.id')
            ->select([
                'm.id',
                'm.conversation_id',
                'm.body',
                'm.occurred_at',
                'c.wa_phone',
                'c.contact_name',
                'cu.full_name as customer_name',
            ])
            ->first();

        return [
            'unread_conversations' => (int) ($agg->convs ?? 0),
            'unread_messages' => (int) ($agg->msgs ?? 0),
            'latest_inbound' => $latest ? [
                'id' => (int) $latest->id,
                'conversation_id' => (int) $latest->conversation_id,
                'sender_name' => $latest->customer_name ?: ($latest->contact_name ?: $latest->wa_phone),
                'wa_phone' => $latest->wa_phone,
                'preview' => Str::limit((string) $latest->body, 120),
                'occurred_at' => CarbonImmutable::parse($latest->occurred_at, 'UTC')->toIso8601String(),
            ] : null,
        ];
    }

    public function listConversations(int $storeId, string $search = ''): array
    {
        if (! Schema::hasTable('whatsapp_conversations')) {
            return [
                'whatsapp_ready' => false,
                'needs_app_secret' => true,
                'conversations' => [],
            ];
        }

        $q = DB::table('whatsapp_conversations as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.store_id', $storeId)
            ->select([
                'c.id',
                'c.customer_id',
                'c.wa_phone',
                'c.contact_name',
                'c.unread_count',
                'c.last_inbound_at',
                'c.last_message_at',
                'c.last_message_preview',
                'c.last_message_direction',
                'cu.full_name as customer_name',
            ])
            ->orderByDesc('c.last_message_at')
            ->orderByDesc('c.id')
            ->limit(150);

        $term = trim($search);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(function ($sub) use ($like) {
                $sub->where('c.wa_phone', 'like', $like)
                    ->orWhere('c.contact_name', 'like', $like)
                    ->orWhere('cu.full_name', 'like', $like)
                    ->orWhere('c.last_message_preview', 'like', $like);
            });
        }

        $now = CarbonImmutable::now('UTC');
        $rows = $q->get()->map(function ($row) use ($now) {
            $lastIn = $row->last_inbound_at ? CarbonImmutable::parse($row->last_inbound_at, 'UTC') : null;
            $windowOpen = $lastIn !== null && $lastIn->greaterThan($now->subHours(24));

            return [
                'id' => (int) $row->id,
                'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
                'display_name' => $row->customer_name ?: ($row->contact_name ?: $row->wa_phone),
                'customer_name' => $row->customer_name,
                'contact_name' => $row->contact_name,
                'wa_phone' => $row->wa_phone,
                'unread_count' => (int) $row->unread_count,
                'window_open' => $windowOpen,
                'window_expires_at' => $windowOpen ? $lastIn->addHours(24)->toIso8601String() : null,
                'last_message_at' => $row->last_message_at ? CarbonImmutable::parse($row->last_message_at, 'UTC')->toIso8601String() : null,
                'last_message_preview' => $row->last_message_preview,
                'last_message_direction' => $row->last_message_direction,
            ];
        })->all();

        $waStatus = $this->configuration->status(false, $storeId);

        return [
            'whatsapp_ready' => (bool) ($waStatus['ready'] ?? false),
            'needs_app_secret' => $this->hasPlaceholderAppSecret($storeId),
            'conversations' => $rows,
        ];
    }

    public function getThread(int $storeId, int $conversationId): array
    {
        $conv = DB::table('whatsapp_conversations as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id')
            ->where('c.store_id', $storeId)
            ->where('c.id', $conversationId)
            ->select([
                'c.*',
                'cu.full_name as customer_name',
            ])
            ->first();

        abort_unless($conv !== null, 404);

        // Auto-link customer if registered after conversation started
        if (! $conv->customer_id) {
            $matched = $this->matchCustomerId($storeId, $conv->wa_phone);
            if ($matched) {
                DB::table('whatsapp_conversations')->where('id', $conversationId)->update(['customer_id' => $matched]);
                $conv->customer_id = $matched;
                $conv->customer_name = DB::table('customers')->where('id', $matched)->value('full_name');
            }
        }

        if ((int) $conv->unread_count > 0) {
            DB::table('whatsapp_conversations')->where('id', $conversationId)->update([
                'unread_count' => 0,
                'updated_at' => now(),
            ]);
            $conv->unread_count = 0;
        }

        $messages = DB::table('whatsapp_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.sent_by_user_id')
            ->where('m.conversation_id', $conversationId)
            ->where('m.store_id', $storeId)
            ->orderByDesc('m.occurred_at')
            ->orderByDesc('m.id')
            ->limit(150)
            ->get([
                'm.id',
                'm.direction',
                'm.message_type',
                'm.body',
                'm.media_id',
                'm.media_mime',
                'm.media_filename',
                'm.template_name',
                'm.status',
                'm.error_code',
                'm.occurred_at',
                'u.name as sender_user_name',
            ])
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'direction' => $m->direction,
                'message_type' => $m->message_type,
                'body' => $m->body,
                'has_media' => filled($m->media_id),
                'media_mime' => $m->media_mime,
                'media_filename' => $m->media_filename,
                'template_name' => $m->template_name,
                'status' => $m->status,
                'error_code' => $m->error_code,
                'sender_user_name' => $m->sender_user_name,
                'occurred_at' => CarbonImmutable::parse($m->occurred_at, 'UTC')->toIso8601String(),
            ])
            ->all();

        $customerContext = null;
        if ($conv->customer_id) {
            $openOrders = DB::table('orders')
                ->where('store_id', $storeId)
                ->where('customer_id', $conv->customer_id)
                ->whereIn('status', ['draft', 'reserved', 'confirmed', 'invoiced'])
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'basket_number', 'status', 'grand_total', 'currency']);

            $customerContext = [
                'id' => (int) $conv->customer_id,
                'full_name' => $conv->customer_name,
                'recent_orders' => $openOrders->map(fn ($o) => [
                    'id' => (int) $o->id,
                    'label' => $o->basket_number ?: ('#'.$o->id),
                    'status' => $o->status,
                    'grand_total' => bcadd((string) $o->grand_total, '0', 2),
                    'currency' => $o->currency,
                ])->all(),
            ];
        }

        $now = CarbonImmutable::now('UTC');
        $lastIn = $conv->last_inbound_at ? CarbonImmutable::parse($conv->last_inbound_at, 'UTC') : null;
        $windowOpen = $lastIn !== null && $lastIn->greaterThan($now->subHours(24));

        return [
            'conversation' => [
                'id' => (int) $conv->id,
                'customer_id' => $conv->customer_id ? (int) $conv->customer_id : null,
                'display_name' => $conv->customer_name ?: ($conv->contact_name ?: $conv->wa_phone),
                'wa_phone' => $conv->wa_phone,
                'unread_count' => 0,
                'window_open' => $windowOpen,
                'window_expires_at' => $windowOpen ? $lastIn->addHours(24)->toIso8601String() : null,
            ],
            'customer_context' => $customerContext,
            'messages' => $messages,
        ];
    }

    public function sendReply(int $storeId, int $conversationId, ?string $text, ?UploadedFile $attachment, User $actor): array
    {
        $conv = DB::table('whatsapp_conversations')
            ->where('store_id', $storeId)
            ->where('id', $conversationId)
            ->first();
        abort_unless($conv !== null, 404);

        $status = $this->configuration->status(false, $storeId);
        abort_unless($status['ready'], 409, 'WhatsApp no está habilitado o configurado en esta tienda.');

        $lastIn = $conv->last_inbound_at ? CarbonImmutable::parse($conv->last_inbound_at, 'UTC') : null;
        if (! $lastIn || $lastIn->lessThanOrEqualTo(CarbonImmutable::now('UTC')->subHours(24))) {
            throw ValidationException::withMessages([
                'body' => 'Pasaron más de 24 horas desde el último mensaje del cliente. Envía una factura, recordatorio o enlace de catálogo para reabrir la conversación.',
            ]);
        }

        $cleanText = trim((string) ($text ?? ''));
        if ($cleanText === '' && ! $attachment) {
            throw ValidationException::withMessages(['body' => 'Escribe un mensaje o adjunta un archivo.']);
        }

        $c = $this->configuration->resolve($storeId);
        $http = Http::withToken($c['access_token'])->acceptJson()->connectTimeout(5)->timeout(30);
        $toDigits = ltrim($conv->wa_phone, '+');

        $msgType = 'text';
        $mediaId = null;
        $mediaMime = null;
        $mediaFilename = null;
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $toDigits,
        ];

        if ($attachment) {
            $mime = $attachment->getMimeType() ?: 'application/octet-stream';
            $origName = $attachment->getClientOriginalName() ?: 'archivo';
            $isImage = str_starts_with($mime, 'image/');
            $msgType = $isImage ? 'image' : 'document';

            $stream = fopen($attachment->getRealPath(), 'rb');
            try {
                $up = $http->attach('file', $stream, $origName, ['Content-Type' => $mime])
                    ->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/media', [
                        'messaging_product' => 'whatsapp',
                        'type' => $mime,
                    ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if (! $up->successful() || ! is_string($up->json('id'))) {
                throw ValidationException::withMessages(['attachment' => 'Meta rechazó el archivo adjunto.']);
            }
            $mediaId = $up->json('id');
            $mediaMime = $mime;
            $mediaFilename = $origName;

            if ($isImage) {
                $payload['type'] = 'image';
                $payload['image'] = array_filter(['id' => $mediaId, 'caption' => $cleanText !== '' ? $cleanText : null]);
            } else {
                $payload['type'] = 'document';
                $payload['document'] = array_filter(['id' => $mediaId, 'filename' => $origName, 'caption' => $cleanText !== '' ? $cleanText : null]);
            }
        } else {
            $payload['type'] = 'text';
            $payload['text'] = ['preview_url' => false, 'body' => $cleanText];
        }

        $res = $http->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/messages', $payload);
        if (! $res->successful() || ! is_string($res->json('messages.0.id'))) {
            $errMsg = $res->json('error.error_user_msg') ?: $res->json('error.message') ?: 'Meta rechazó el mensaje.';
            throw ValidationException::withMessages(['body' => $errMsg]);
        }

        $providerId = $res->json('messages.0.id');
        $now = CarbonImmutable::now('UTC');
        $storedBody = $cleanText !== '' ? $cleanText : ($msgType === 'image' ? '📷 Imagen enviada' : ('📄 '.$mediaFilename));
        $preview = Str::limit($storedBody, 180);

        $msgId = DB::transaction(function () use ($storeId, $conversationId, $msgType, $storedBody, $mediaId, $mediaMime, $mediaFilename, $providerId, $actor, $now, $preview) {
            DB::table('whatsapp_conversations')->where('id', $conversationId)->update([
                'unread_count' => 0,
                'last_message_at' => $now,
                'last_message_preview' => $preview,
                'last_message_direction' => 'outbound',
                'updated_at' => now(),
            ]);

            return DB::table('whatsapp_messages')->insertGetId([
                'conversation_id' => $conversationId,
                'store_id' => $storeId,
                'direction' => 'outbound',
                'message_type' => $msgType,
                'body' => $storedBody,
                'media_id' => $mediaId,
                'media_mime' => $mediaMime,
                'media_filename' => $mediaFilename,
                'provider_message_id' => $providerId,
                'status' => 'sent',
                'sent_by_user_id' => $actor->id,
                'occurred_at' => $now,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return [
            'id' => (int) $msgId,
            'direction' => 'outbound',
            'message_type' => $msgType,
            'body' => $storedBody,
            'has_media' => filled($mediaId),
            'media_mime' => $mediaMime,
            'media_filename' => $mediaFilename,
            'template_name' => null,
            'status' => 'sent',
            'error_code' => null,
            'sender_user_name' => $actor->name,
            'occurred_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Downloads incoming/outgoing WhatsApp media strictly scoped to $storeId.
     *
     * @return array{bytes: string, mime: string, filename: string}
     */
    public function fetchMessageMedia(int $storeId, int $messageId): array
    {
        $msg = DB::table('whatsapp_messages')
            ->where('store_id', $storeId)
            ->where('id', $messageId)
            ->first();
        abort_unless($msg && filled($msg->media_id), 404);

        $c = $this->configuration->resolve($storeId);
        abort_unless(filled($c['access_token'] ?? null), 409, 'Token de WhatsApp no disponible.');

        $meta = Http::withToken($c['access_token'])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(25)
            ->get('https://graph.facebook.com/'.($c['graph_version'] ?: 'v25.0').'/'.urlencode((string) $msg->media_id));
        abort_unless($meta->successful() && is_string($meta->json('url')), 404, 'El archivo multimedia ya expiró en Meta.');

        $mediaUrl = $meta->json('url');
        $host = strtolower((string) parse_url($mediaUrl, PHP_URL_HOST));
        abort_unless(
            str_ends_with($host, '.fbsbx.com')
                || str_ends_with($host, '.fbcdn.net')
                || str_ends_with($host, '.facebook.com')
                || str_ends_with($host, '.whatsapp.net'),
            403,
            'Origen multimedia no permitido.'
        );

        // Fresh client without Accept: application/json and with explicit User-Agent required by lookaside.fbsbx.com
        $dl = Http::withToken($c['access_token'])
            ->withHeaders(['User-Agent' => 'KeylaShop-WhatsApp/1.0'])
            ->connectTimeout(5)
            ->timeout(25)
            ->get($mediaUrl);
        abort_unless($dl->successful(), 502, 'No se pudo descargar el archivo desde Meta.');

        return [
            'bytes' => $dl->body(),
            'mime' => $msg->media_mime ?: ($meta->json('mime_type') ?: 'application/octet-stream'),
            'filename' => preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) ($msg->media_filename ?: ('whatsapp-'.$msg->id))),
        ];
    }
}
