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

    /**
     * @return array{inserted: bool, conversation_id: int, wa_phone: string, profile_name: ?string, message_id: ?int}|null
     */
    public function recordInboundWebhookMessage(int $storeId, array $msg, ?string $profileName = null): ?array
    {
        if (! Schema::hasTable('whatsapp_conversations') || ! Schema::hasTable('whatsapp_messages')) {
            return null;
        }

        $providerId = isset($msg['id']) && is_string($msg['id']) ? $msg['id'] : null;
        $waPhone = self::normalizePhone((string) ($msg['from'] ?? ''));
        if (! $providerId || ! $waPhone) {
            return null;
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
        $isDeletionEvent = false;

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
        } elseif ($type === 'video') {
            $body = trim((string) ($msg['video']['caption'] ?? '')) ?: '🎥 Video recibido';
        } elseif ($type === 'sticker') {
            $body = '🏷️ Sticker';
        } elseif ($type === 'location') {
            $body = '📍 Ubicación compartida';
        } elseif ($type === 'contacts') {
            $body = '👤 Contacto compartido';
        } elseif ($type === 'button') {
            $mappedType = 'text';
            $body = trim((string) ($msg['button']['text'] ?? 'Respuesta de botón'));
        } elseif ($type === 'interactive') {
            $mappedType = 'text';
            $body = trim((string) ($msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? 'Respuesta interactiva'));
        } elseif ($type === 'reaction') {
            $emoji = (string) ($msg['reaction']['emoji'] ?? '👍');
            $body = "Reacción: {$emoji}";
        } elseif ($type === 'unsupported') {
            $isDeletionEvent = true;
            $body = '🚫 El cliente eliminó un mensaje';
        } else {
            $body = 'Mensaje recibido ('.$type.')';
        }

        $preview = Str::limit(preg_replace('/\s+/', ' ', (string) $body), 180);
        $contextMsgId = isset($msg['context']['id']) && is_string($msg['context']['id']) ? $msg['context']['id'] : null;

        return DB::transaction(function () use ($storeId, $waPhone, $profileName, $providerId, $contextMsgId, $isDeletionEvent, $mappedType, $body, $mediaId, $mediaMime, $mediaFilename, $ts, $preview) {
            // If Meta sends a revoke/unsupported referencing an existing message, redact it in-place
            if ($isDeletionEvent) {
                $targetRef = $contextMsgId ?: $providerId;
                $existing = DB::table('whatsapp_messages')
                    ->where('store_id', $storeId)
                    ->where('provider_message_id', $targetRef)
                    ->first(['id', 'conversation_id']);
                if ($existing) {
                    DB::table('whatsapp_messages')->where('id', $existing->id)->update([
                        'message_type' => 'other',
                        'body' => '🚫 Este mensaje fue eliminado por el cliente',
                        'media_id' => null,
                        'media_mime' => null,
                        'media_filename' => null,
                        'status' => 'deleted',
                        'updated_at' => now(),
                    ]);
                    $latestId = DB::table('whatsapp_messages')
                        ->where('conversation_id', $existing->conversation_id)
                        ->orderByDesc('occurred_at')
                        ->orderByDesc('id')
                        ->value('id');
                    if ((int) $latestId === (int) $existing->id) {
                        DB::table('whatsapp_conversations')->where('id', $existing->conversation_id)->update([
                            'last_message_preview' => '🚫 Mensaje eliminado',
                            'updated_at' => now(),
                        ]);
                    }

                    return [
                        'inserted' => false,
                        'conversation_id' => (int) $existing->conversation_id,
                        'wa_phone' => $waPhone,
                        'profile_name' => $profileName,
                        'message_id' => (int) $existing->id,
                    ];
                }
            }

            $already = DB::table('whatsapp_messages')
                ->where('store_id', $storeId)
                ->where('provider_message_id', $providerId)
                ->first(['id', 'conversation_id']);
            if ($already) {
                return [
                    'inserted' => false,
                    'conversation_id' => (int) $already->conversation_id,
                    'wa_phone' => $waPhone,
                    'profile_name' => $profileName,
                    'message_id' => (int) $already->id,
                ];
            }

            $conv = DB::table('whatsapp_conversations')
                ->where('store_id', $storeId)
                ->where('wa_phone', $waPhone)
                ->lockForUpdate()
                ->first();

            $customerId = $conv?->customer_id ?: $this->matchCustomerId($storeId, $waPhone);
            $cleanProfile = $profileName ? trim($profileName) : null;
            $unreadDelta = $isDeletionEvent ? 0 : 1;

            if (! $conv) {
                $convId = DB::table('whatsapp_conversations')->insertGetId([
                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'wa_phone' => $waPhone,
                    'contact_name' => $cleanProfile,
                    'unread_count' => $unreadDelta,
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
                    'unread_count' => (int) $conv->unread_count + $unreadDelta,
                    'last_inbound_at' => $newLastIn,
                    'last_message_at' => $isNewer ? $ts : $conv->last_message_at,
                    'last_message_preview' => $isNewer ? $preview : $conv->last_message_preview,
                    'last_message_direction' => $isNewer ? 'inbound' : $conv->last_message_direction,
                    'updated_at' => now(),
                ]);
            }

            $msgId = DB::table('whatsapp_messages')->insertGetId([
                'conversation_id' => $convId,
                'store_id' => $storeId,
                'direction' => 'inbound',
                'message_type' => $mappedType,
                'body' => $body,
                'media_id' => $mediaId,
                'media_mime' => $mediaMime,
                'media_filename' => $mediaFilename,
                'provider_message_id' => $providerId,
                'status' => $isDeletionEvent ? 'deleted' : 'received',
                'occurred_at' => $ts,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'inserted' => true,
                'conversation_id' => $convId,
                'wa_phone' => $waPhone,
                'profile_name' => $cleanProfile,
                'message_id' => (int) $msgId,
            ];
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

    public const CRM_STAGES = [
        ['key' => 'new', 'label' => 'Nuevo lead', 'color' => '#6366f1'],
        ['key' => 'quoting', 'label' => 'Cotizando', 'color' => '#0ea5e9'],
        ['key' => 'reserved', 'label' => 'Apartado', 'color' => '#8b5cf6'],
        ['key' => 'payment_pending', 'label' => 'Por pagar', 'color' => '#f59e0b'],
        ['key' => 'closed_won', 'label' => 'Cerrado', 'color' => '#16a34a'],
    ];

    public const CRM_STAGE_KEYS = ['new', 'quoting', 'reserved', 'payment_pending', 'closed_won'];

    private function ensureDefaultCrmSeeds(int $storeId): void
    {
        if (Schema::hasTable('whatsapp_tags') && ! DB::table('whatsapp_tags')->where('store_id', $storeId)->exists()) {
            $now = now();
            DB::table('whatsapp_tags')->insert([
                ['store_id' => $storeId, 'name' => 'VIP', 'color' => '#d97706', 'created_at' => $now, 'updated_at' => $now],
                ['store_id' => $storeId, 'name' => 'Mayorista', 'color' => '#2563eb', 'created_at' => $now, 'updated_at' => $now],
                ['store_id' => $storeId, 'name' => 'Por despachar', 'color' => '#16a34a', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (Schema::hasTable('whatsapp_quick_replies') && ! DB::table('whatsapp_quick_replies')->where('store_id', $storeId)->exists()) {
            $now = now();
            DB::table('whatsapp_quick_replies')->insert([
                [
                    'store_id' => $storeId,
                    'shortcut' => 'envio',
                    'title' => 'Información de envío',
                    'body' => 'Hacemos envíos rápidos y seguros. En cuanto confirmes tu dirección y el pago, coordinamos tu despacho enseguida 📦✨',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'store_id' => $storeId,
                    'shortcut' => 'zelle',
                    'title' => 'Datos de pago Zelle',
                    'body' => 'Puedes realizar tu abono o pago por Zelle y enviarnos la captura por aquí mismo para acreditarlo de inmediato a tu apartado 💳✅',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'store_id' => $storeId,
                    'shortcut' => 'horario',
                    'title' => 'Horario de atención',
                    'body' => 'Nuestro horario de atención es de lunes a sábado de 9:00 a. m. a 7:00 p. m. ¡Con gusto te ayudamos! 🛍️💜',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }
    }

    public function listConversations(int $storeId, string $search = ''): array
    {
        if (! Schema::hasTable('whatsapp_conversations')) {
            return [
                'whatsapp_ready' => false,
                'needs_app_secret' => true,
                'conversations' => [],
                'crm_stages' => self::CRM_STAGES,
                'tags' => [],
                'agents' => [],
                'quick_replies' => [],
            ];
        }

        $this->ensureDefaultCrmSeeds($storeId);
        $hasCrmCols = Schema::hasColumn('whatsapp_conversations', 'crm_stage');

        $select = [
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
        ];
        $q = DB::table('whatsapp_conversations as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id');

        if ($hasCrmCols) {
            $q->leftJoin('users as au', 'au.id', '=', 'c.assigned_user_id');
            $select[] = 'c.assigned_user_id';
            $select[] = 'c.crm_stage';
            $select[] = 'au.name as assigned_user_name';
        }

        $q->where('c.store_id', $storeId)
            ->select($select)
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

        $rawRows = $q->get();
        $convIds = $rawRows->pluck('id')->all();
        $custIds = $rawRows->pluck('customer_id')->filter()->unique()->values()->all();

        $tagsByConv = [];
        if ($convIds && Schema::hasTable('whatsapp_conversation_tags') && Schema::hasTable('whatsapp_tags')) {
            $tagRows = DB::table('whatsapp_conversation_tags as ct')
                ->join('whatsapp_tags as t', 't.id', '=', 'ct.tag_id')
                ->where('ct.store_id', $storeId)
                ->whereIn('ct.conversation_id', $convIds)
                ->orderBy('t.name')
                ->get(['ct.conversation_id', 't.id', 't.name', 't.color']);
            foreach ($tagRows as $tr) {
                $tagsByConv[(int) $tr->conversation_id][] = [
                    'id' => (int) $tr->id,
                    'name' => $tr->name,
                    'color' => $tr->color,
                ];
            }
        }

        $openTotalsByCust = [];
        if ($custIds) {
            $orderSums = DB::table('orders')
                ->where('store_id', $storeId)
                ->whereIn('customer_id', $custIds)
                ->whereIn('status', ['draft', 'reserved', 'confirmed', 'invoiced'])
                ->groupBy('customer_id')
                ->selectRaw('customer_id, COALESCE(SUM(grand_total), 0) as total, COUNT(*) as cnt')
                ->get();
            foreach ($orderSums as $os) {
                $openTotalsByCust[(int) $os->customer_id] = [
                    'total' => bcadd((string) $os->total, '0', 2),
                    'count' => (int) $os->cnt,
                ];
            }
        }

        $now = CarbonImmutable::now('UTC');
        $rows = $rawRows->map(function ($row) use ($now, $hasCrmCols, $tagsByConv, $openTotalsByCust) {
            $lastIn = $row->last_inbound_at ? CarbonImmutable::parse($row->last_inbound_at, 'UTC') : null;
            $windowOpen = $lastIn !== null && $lastIn->greaterThan($now->subHours(24));
            $cid = $row->customer_id ? (int) $row->customer_id : null;
            $orderMeta = $cid ? ($openTotalsByCust[$cid] ?? null) : null;

            return [
                'id' => (int) $row->id,
                'customer_id' => $cid,
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
                'assigned_user_id' => ($hasCrmCols && $row->assigned_user_id) ? (int) $row->assigned_user_id : null,
                'assigned_user_name' => $hasCrmCols ? ($row->assigned_user_name ?? null) : null,
                'crm_stage' => $hasCrmCols ? ($row->crm_stage ?: 'new') : 'new',
                'tags' => $tagsByConv[(int) $row->id] ?? [],
                'open_orders_total' => $orderMeta['total'] ?? null,
                'open_orders_count' => $orderMeta['count'] ?? 0,
            ];
        })->all();

        $waStatus = $this->configuration->status(false, $storeId);
        $tags = Schema::hasTable('whatsapp_tags')
            ? DB::table('whatsapp_tags')->where('store_id', $storeId)->orderBy('name')->get(['id', 'name', 'color'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'color' => $t->color])->all()
            : [];
        $agents = DB::table('store_user as su')
            ->join('users as u', 'u.id', '=', 'su.user_id')
            ->where('su.store_id', $storeId)
            ->where('u.is_active', true)
            ->orderBy('u.name')
            ->get(['u.id', 'u.name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();
        $quickReplies = Schema::hasTable('whatsapp_quick_replies')
            ? DB::table('whatsapp_quick_replies')->where('store_id', $storeId)->orderBy('shortcut')->get(['id', 'shortcut', 'title', 'body'])
                ->map(fn ($q) => ['id' => (int) $q->id, 'shortcut' => $q->shortcut, 'title' => $q->title, 'body' => $q->body])->all()
            : [];

        return [
            'whatsapp_ready' => (bool) ($waStatus['ready'] ?? false),
            'needs_app_secret' => $this->hasPlaceholderAppSecret($storeId),
            'conversations' => $rows,
            'crm_stages' => self::CRM_STAGES,
            'tags' => $tags,
            'agents' => $agents,
            'quick_replies' => $quickReplies,
        ];
    }

    public function getThread(int $storeId, int $conversationId): array
    {
        $hasCrmCols = Schema::hasColumn('whatsapp_conversations', 'crm_stage');
        $hasInternalCol = Schema::hasColumn('whatsapp_messages', 'is_internal');

        $convQ = DB::table('whatsapp_conversations as c')
            ->leftJoin('customers as cu', 'cu.id', '=', 'c.customer_id');
        $select = ['c.*', 'cu.full_name as customer_name'];
        if ($hasCrmCols) {
            $convQ->leftJoin('users as au', 'au.id', '=', 'c.assigned_user_id');
            $select[] = 'au.name as assigned_user_name';
        }
        $conv = $convQ->where('c.store_id', $storeId)
            ->where('c.id', $conversationId)
            ->select($select)
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

        $msgSelect = [
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
        ];
        if ($hasInternalCol) {
            $msgSelect[] = 'm.is_internal';
        }

        $messages = DB::table('whatsapp_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.sent_by_user_id')
            ->where('m.conversation_id', $conversationId)
            ->where('m.store_id', $storeId)
            ->orderByDesc('m.occurred_at')
            ->orderByDesc('m.id')
            ->limit(150)
            ->get($msgSelect)
            ->reverse()
            ->values()
            ->map(fn ($m) => [
                'id' => (int) $m->id,
                'direction' => $m->direction,
                'is_internal' => $hasInternalCol ? (bool) ($m->is_internal ?? false) : false,
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

        $convTags = [];
        if (Schema::hasTable('whatsapp_conversation_tags') && Schema::hasTable('whatsapp_tags')) {
            $convTags = DB::table('whatsapp_conversation_tags as ct')
                ->join('whatsapp_tags as t', 't.id', '=', 'ct.tag_id')
                ->where('ct.store_id', $storeId)
                ->where('ct.conversation_id', $conversationId)
                ->orderBy('t.name')
                ->get(['t.id', 't.name', 't.color'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'color' => $t->color])
                ->all();
        }

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
                'assigned_user_id' => ($hasCrmCols && $conv->assigned_user_id) ? (int) $conv->assigned_user_id : null,
                'assigned_user_name' => $hasCrmCols ? ($conv->assigned_user_name ?? null) : null,
                'crm_stage' => $hasCrmCols ? ($conv->crm_stage ?: 'new') : 'new',
                'tags' => $convTags,
            ],
            'customer_context' => $customerContext,
            'messages' => $messages,
        ];
    }

    public function updateConversationCrm(int $storeId, int $conversationId, array $data): array
    {
        return DB::transaction(function () use ($storeId, $conversationId, $data) {
            $conv = DB::table('whatsapp_conversations')
                ->where('store_id', $storeId)
                ->where('id', $conversationId)
                ->lockForUpdate()
                ->first();
            abort_unless($conv !== null, 404);

            $update = ['updated_at' => now()];
            if (array_key_exists('assigned_user_id', $data)) {
                $uid = $data['assigned_user_id'] !== null ? (int) $data['assigned_user_id'] : null;
                if ($uid !== null) {
                    $belongs = DB::table('store_user')->where('store_id', $storeId)->where('user_id', $uid)->exists();
                    abort_unless($belongs, 422, 'El usuario no pertenece a esta sucursal.');
                }
                $update['assigned_user_id'] = $uid;
            }
            if (array_key_exists('crm_stage', $data) && in_array($data['crm_stage'], self::CRM_STAGE_KEYS, true)) {
                $update['crm_stage'] = $data['crm_stage'];
            }
            if (count($update) > 1) {
                DB::table('whatsapp_conversations')->where('id', $conversationId)->update($update);
            }

            if (array_key_exists('tag_ids', $data) && is_array($data['tag_ids'])) {
                $validIds = DB::table('whatsapp_tags')
                    ->where('store_id', $storeId)
                    ->whereIn('id', array_map('intval', $data['tag_ids']))
                    ->pluck('id')
                    ->all();

                DB::table('whatsapp_conversation_tags')
                    ->where('store_id', $storeId)
                    ->where('conversation_id', $conversationId)
                    ->delete();

                $now = now();
                foreach (array_unique($validIds) as $tid) {
                    DB::table('whatsapp_conversation_tags')->insert([
                        'conversation_id' => $conversationId,
                        'tag_id' => (int) $tid,
                        'store_id' => $storeId,
                        'created_at' => $now,
                    ]);
                }
            }

            $fresh = DB::table('whatsapp_conversations as c')
                ->leftJoin('users as au', 'au.id', '=', 'c.assigned_user_id')
                ->where('c.id', $conversationId)
                ->first(['c.id', 'c.assigned_user_id', 'c.crm_stage', 'au.name as assigned_user_name']);
            $tags = DB::table('whatsapp_conversation_tags as ct')
                ->join('whatsapp_tags as t', 't.id', '=', 'ct.tag_id')
                ->where('ct.store_id', $storeId)
                ->where('ct.conversation_id', $conversationId)
                ->orderBy('t.name')
                ->get(['t.id', 't.name', 't.color'])
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'color' => $t->color])
                ->all();

            return [
                'id' => (int) $fresh->id,
                'assigned_user_id' => $fresh->assigned_user_id ? (int) $fresh->assigned_user_id : null,
                'assigned_user_name' => $fresh->assigned_user_name,
                'crm_stage' => $fresh->crm_stage ?: 'new',
                'tags' => $tags,
            ];
        });
    }

    public function addInternalNote(int $storeId, int $conversationId, string $body, User $actor): array
    {
        $clean = trim($body);
        if ($clean === '') {
            throw ValidationException::withMessages(['body' => 'Escribe el texto de la nota interna.']);
        }

        $conv = DB::table('whatsapp_conversations')
            ->where('store_id', $storeId)
            ->where('id', $conversationId)
            ->first(['id']);
        abort_unless($conv !== null, 404);

        $now = CarbonImmutable::now('UTC');
        $msgId = DB::table('whatsapp_messages')->insertGetId([
            'conversation_id' => $conversationId,
            'store_id' => $storeId,
            'direction' => 'outbound',
            'is_internal' => true,
            'message_type' => 'text',
            'body' => $clean,
            'status' => 'internal',
            'sent_by_user_id' => $actor->id,
            'occurred_at' => $now,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => (int) $msgId,
            'direction' => 'outbound',
            'is_internal' => true,
            'message_type' => 'text',
            'body' => $clean,
            'has_media' => false,
            'media_mime' => null,
            'media_filename' => null,
            'template_name' => null,
            'status' => 'internal',
            'error_code' => null,
            'sender_user_name' => $actor->name,
            'occurred_at' => $now->toIso8601String(),
        ];
    }

    public function createTag(int $storeId, string $name, string $color): array
    {
        $cleanName = Str::limit(trim($name), 40, '');
        $cleanColor = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#6d28d9';

        $existing = DB::table('whatsapp_tags')
            ->where('store_id', $storeId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($cleanName)])
            ->first(['id', 'name', 'color']);
        if ($existing) {
            return ['id' => (int) $existing->id, 'name' => $existing->name, 'color' => $existing->color];
        }

        $id = DB::table('whatsapp_tags')->insertGetId([
            'store_id' => $storeId,
            'name' => $cleanName,
            'color' => $cleanColor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => (int) $id, 'name' => $cleanName, 'color' => $cleanColor];
    }

    public function deleteTag(int $storeId, int $tagId): void
    {
        $deleted = DB::table('whatsapp_tags')->where('store_id', $storeId)->where('id', $tagId)->delete();
        abort_unless($deleted > 0, 404);
    }

    public function upsertQuickReply(int $storeId, string $shortcut, string $title, string $body, User $actor): array
    {
        $cleanShortcut = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '', ltrim(trim($shortcut), '/')));
        if ($cleanShortcut === '') {
            throw ValidationException::withMessages(['shortcut' => 'Usa un atajo válido (ej. envio, zelle, horario).']);
        }
        $cleanTitle = Str::limit(trim($title) ?: $cleanShortcut, 80, '');
        $cleanBody = trim($body);

        $existing = DB::table('whatsapp_quick_replies')
            ->where('store_id', $storeId)
            ->where('shortcut', $cleanShortcut)
            ->first(['id']);

        if ($existing) {
            DB::table('whatsapp_quick_replies')->where('id', $existing->id)->update([
                'title' => $cleanTitle,
                'body' => $cleanBody,
                'updated_at' => now(),
            ]);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('whatsapp_quick_replies')->insertGetId([
                'store_id' => $storeId,
                'shortcut' => $cleanShortcut,
                'title' => $cleanTitle,
                'body' => $cleanBody,
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['id' => $id, 'shortcut' => $cleanShortcut, 'title' => $cleanTitle, 'body' => $cleanBody];
    }

    public function deleteQuickReply(int $storeId, int $quickReplyId): void
    {
        $deleted = DB::table('whatsapp_quick_replies')->where('store_id', $storeId)->where('id', $quickReplyId)->delete();
        abort_unless($deleted > 0, 404);
    }

    public function deleteConversation(int $storeId, int $conversationId): void
    {
        DB::transaction(function () use ($storeId, $conversationId) {
            $exists = DB::table('whatsapp_conversations')
                ->where('store_id', $storeId)
                ->where('id', $conversationId)
                ->lockForUpdate()
                ->exists();
            abort_unless($exists, 404);

            DB::table('whatsapp_messages')
                ->where('store_id', $storeId)
                ->where('conversation_id', $conversationId)
                ->delete();
            DB::table('whatsapp_conversations')
                ->where('store_id', $storeId)
                ->where('id', $conversationId)
                ->delete();
        });
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

    /**
     * Sends an automated text or interactive (buttons/list) reply inside the free 24h window
     * and logs it into whatsapp_messages so the store team sees the complete conversation.
     */
    public function sendAutomatedReply(
        int $storeId,
        int $conversationId,
        string $waPhone,
        string $text,
        ?array $interactive = null,
    ): bool {
        try {
            $status = $this->configuration->status(false, $storeId);
            if (! $status['ready']) {
                return false;
            }
            $c = $this->configuration->resolve($storeId);
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($waPhone, '+'),
            ];
            if ($interactive !== null) {
                $payload['type'] = 'interactive';
                $payload['interactive'] = $interactive;
            } else {
                $payload['type'] = 'text';
                $payload['text'] = ['preview_url' => false, 'body' => $text];
            }

            $res = Http::withToken($c['access_token'])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post('https://graph.facebook.com/'.($c['graph_version'] ?: 'v25.0').'/'.$c['phone_number_id'].'/messages', $payload);

            if (! $res->successful()) {
                return false;
            }

            $providerId = $res->json('messages.0.id');
            $now = CarbonImmutable::now('UTC');
            $preview = Str::limit(preg_replace('/\s+/', ' ', $text), 180);

            DB::transaction(function () use ($storeId, $conversationId, $text, $providerId, $now, $preview) {
                DB::table('whatsapp_conversations')->where('id', $conversationId)->update([
                    'last_message_at' => $now,
                    'last_message_preview' => $preview,
                    'last_message_direction' => 'outbound',
                    'updated_at' => now(),
                ]);
                DB::table('whatsapp_messages')->insert([
                    'conversation_id' => $conversationId,
                    'store_id' => $storeId,
                    'direction' => 'outbound',
                    'message_type' => 'text',
                    'body' => $text,
                    'provider_message_id' => is_string($providerId) ? $providerId : null,
                    'status' => 'sent',
                    'occurred_at' => $now,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
