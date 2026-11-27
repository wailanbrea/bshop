<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class WhatsAppTemplateDispatcher
{
    public function __construct(
        private WhatsAppConfiguration $configuration,
        private InvoiceDocument $documents,
        private AuditTrailService $audit,
    ) {}

    public static function defaultRules(): array
    {
        return [
            'auto_apartado' => ['enabled' => false, 'template' => 'auto_apartado'],
            'payment_receipt' => ['enabled' => false, 'template' => 'recibo_abono'],
            'order_dispatched' => ['enabled' => false, 'template' => 'pedido_despachado'],
            'receivables_reminder' => ['enabled' => false, 'template' => 'recordatorio_saldo'],
            'expiry_warning' => ['enabled' => false, 'template' => 'apartado_por_vencer', 'hours_before' => 24],
            'weekly_close_pdf' => ['enabled' => false, 'template' => 'cuenta_semanal_pdf'],
            'catalog_link' => ['enabled' => false, 'template' => 'enlace_catalogo_cliente'],
            'owner_daily_summary' => ['enabled' => false, 'template' => 'resumen_diario_dueno'],
            'low_stock_alert' => ['enabled' => false, 'template' => 'alerta_stock_bajo', 'threshold' => 2],
        ];
    }

    public function getAutomations(int $storeId): array
    {
        $row = Schema::hasTable('store_whatsapp_configs')
            ? DB::table('store_whatsapp_configs')->where('store_id', $storeId)->first()
            : null;
        $stored = ($row && $row->automation_rules) ? json_decode($row->automation_rules, true, flags: JSON_THROW_ON_ERROR) : [];
        $defaults = self::defaultRules();
        $rules = [];
        foreach ($defaults as $key => $def) {
            $rules[$key] = array_merge($def, is_array($stored[$key] ?? null) ? $stored[$key] : []);
            $rules[$key]['enabled'] = (bool) $rules[$key]['enabled'];
        }

        return [
            'owner_phone' => $row->owner_phone ?? null,
            'daily_summary_time' => $row->daily_summary_time ?? '21:00',
            'last_daily_summary_date' => $row->last_daily_summary_date ?? null,
            'rules' => $rules,
            'whatsapp' => $this->configuration->status(false, $storeId),
            'logs' => $this->recentLogs($storeId),
        ];
    }

    public function saveAutomations(Store $store, array $data, User $actor): array
    {
        $defaults = self::defaultRules();
        $cleanRules = [];
        foreach ($defaults as $key => $def) {
            $in = $data['rules'][$key] ?? [];
            $entry = [
                'enabled' => (bool) ($in['enabled'] ?? false),
                'template' => trim((string) ($in['template'] ?? $def['template'])) ?: $def['template'],
            ];
            if (isset($def['hours_before'])) {
                $entry['hours_before'] = max(1, min(168, (int) ($in['hours_before'] ?? $def['hours_before'])));
            }
            if (isset($def['threshold'])) {
                $entry['threshold'] = max(0, min(1000, (int) ($in['threshold'] ?? $def['threshold'])));
            }
            $cleanRules[$key] = $entry;
        }

        DB::transaction(function () use ($store, $data, $cleanRules, $actor) {
            $existing = DB::table('store_whatsapp_configs')->where('store_id', $store->id)->lockForUpdate()->first();
            $payload = [
                'owner_phone' => filled($data['owner_phone'] ?? null) ? preg_replace('/[\s().-]+/', '', (string) $data['owner_phone']) : null,
                'daily_summary_time' => $data['daily_summary_time'] ?? '21:00',
                'automation_rules' => json_encode($cleanRules, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('store_whatsapp_configs')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('store_whatsapp_configs')->insert([
                    'store_id' => $store->id,
                    ...$payload,
                    'created_at' => now(),
                ]);
            }
            $this->audit->record('whatsapp.automations.updated', $store, after: ['store_id' => $store->id, 'owner_phone' => $payload['owner_phone'], 'daily_summary_time' => $payload['daily_summary_time']], actor: $actor);
        });

        return $this->getAutomations($store->id);
    }

    public function sendTemplate(
        int $storeId,
        string $eventType,
        ?string $rawPhone,
        string $templateName,
        array $bodyParams,
        ?string $pdfPath = null,
        ?string $pdfFilename = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?int $cooldownHours = null,
    ): array {
        $phone = WhatsAppInboxService::normalizePhone($rawPhone) ?? '';
        if (! preg_match('/^\+[1-9][0-9]{7,14}$/D', $phone)) {
            return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone ?: 'invalid', $templateName, 'skipped', null, 'invalid_phone', $bodyParams);
        }

        if ($cooldownHours !== null && $entityType !== null && $entityId !== null && Schema::hasTable('whatsapp_notification_logs')) {
            $recent = DB::table('whatsapp_notification_logs')
                ->where('store_id', $storeId)
                ->where('event_type', $eventType)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('status', 'sent')
                ->where('created_at', '>=', now()->subHours($cooldownHours))
                ->exists();
            if ($recent) {
                return ['status' => 'skipped', 'error_code' => 'cooldown_active'];
            }
        }

        $status = $this->configuration->status(false, $storeId);
        if (! $status['ready']) {
            return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone, $templateName, 'skipped', null, 'whatsapp_not_ready', $bodyParams);
        }
        $c = $this->configuration->resolve($storeId);

        try {
            $http = Http::withToken($c['access_token'])->acceptJson()->connectTimeout(5)->timeout(25);
            $components = [];
            if ($pdfPath !== null && is_file($pdfPath)) {
                $stream = fopen($pdfPath, 'rb');
                try {
                    $upload = $http->attach('file', $stream, $pdfFilename ?: 'documento.pdf', ['Content-Type' => 'application/pdf'])
                        ->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/media', ['type' => 'application/pdf', 'messaging_product' => 'whatsapp']);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if (! $upload->successful() || ! is_string($upload->json('id'))) {
                    return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone, $templateName, 'failed', null, 'media_upload_failed', $bodyParams);
                }
                $components[] = [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => ['id' => $upload->json('id'), 'filename' => $pdfFilename ?: 'documento.pdf'],
                    ]],
                ];
            }

            if (! empty($bodyParams)) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], array_values($bodyParams)),
                ];
            }

            $response = Http::withToken($c['access_token'])->acceptJson()->connectTimeout(5)->timeout(25)
                ->post('https://graph.facebook.com/'.$c['graph_version'].'/'.$c['phone_number_id'].'/messages', [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => ltrim($phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => ['code' => $c['language'] ?: 'es'],
                        'components' => $components,
                    ],
                ]);

            if ($response->successful() && is_string($response->json('messages.0.id'))) {
                $providerMsgId = $response->json('messages.0.id');
                app(WhatsAppInboxService::class)->recordOutboundSystemMessage(
                    $storeId,
                    $phone,
                    $templateName,
                    self::renderHumanBody($templateName, array_values(array_map('strval', $bodyParams))),
                    $providerMsgId,
                    $pdfFilename,
                );

                return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone, $templateName, 'sent', $providerMsgId, null, $bodyParams);
            }

            return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone, $templateName, 'failed', null, 'provider_rejected_'.$response->status(), $bodyParams);
        } catch (\Throwable $e) {
            return $this->recordLog($storeId, $eventType, $entityType, $entityId, $phone, $templateName, 'failed', null, 'exception', $bodyParams);
        }
    }

    /**
     * Renders a natural, human-readable Spanish version of the sent Meta template
     * for display inside the WhatsApp Inbox thread.
     *
     * @param  list<string>  $params
     */
    public static function renderHumanBody(string $templateName, array $params): string
    {
        $p = array_values($params);
        $get = fn (int $i, string $fallback = ''): string => $p[$i] ?? $fallback;

        return match ($templateName) {
            'recibo_abono' => sprintf(
                'Hola %s, registramos tu abono de %s en %s para el pedido #%s. Saldo pendiente actual: %s.',
                $get(0),
                $get(1),
                $get(2),
                ltrim($get(3), '#'),
                $get(4),
            ),
            'pedido_despachado' => sprintf(
                'Hola %s, tu pedido #%s en %s ya fue despachado/entregado. ¡Gracias por tu compra!',
                $get(0),
                ltrim($get(1), '#'),
                $get(2),
            ),
            'recordatorio_saldo' => sprintf(
                'Hola %s, te recordamos que tienes un saldo pendiente de %s en %s (ref. %s).',
                $get(0),
                $get(1),
                $get(2),
                $get(3),
            ),
            'apartado_por_vencer' => sprintf(
                'Hola %s, tu apartado #%s en %s está próximo a vencer (%s). Saldo pendiente: %s.',
                $get(0),
                ltrim($get(1), '#'),
                $get(2),
                $get(3),
                $get(4),
            ),
            'cuenta_semanal_pdf' => sprintf(
                'Hola %s, adjuntamos el estado de tu cuenta semanal (%s) en %s. Total: %s · Pendiente: %s.',
                $get(0),
                $get(1),
                $get(2),
                $get(3),
                $get(4),
            ),
            'enlace_catalogo_cliente' => sprintf(
                'Hola %s, aquí tienes tu catálogo personalizado de %s: %s (válido hasta %s).',
                $get(0),
                $get(1),
                $get(2),
                $get(3),
            ),
            'resumen_diario_dueno' => sprintf(
                '📊 Resumen diario de %s (%s): Ventas %s · Cobros %s · Apartados %s · CxC %s.',
                $get(0),
                $get(1),
                $get(2),
                $get(3),
                $get(4),
                $get(5),
            ),
            'alerta_stock_bajo' => sprintf(
                '⚠️ Stock bajo en %s: %s (SKU %s) quedó con %s disponible(s).',
                $get(0),
                $get(1),
                $get(2),
                $get(3),
            ),
            default => implode(' · ', $p),
        };
    }

    public function notifyPaymentReceipt(SalesOrder $order, string $paidAmount, string $pendingTotal): void
    {
        if (! Schema::hasTable('store_whatsapp_configs')) {
            return;
        }
        $auto = $this->getAutomations((int) $order->store_id);
        $rule = $auto['rules']['payment_receipt'] ?? null;
        if (! ($rule['enabled'] ?? false)) {
            return;
        }
        $customer = Customer::withTrashed()->find($order->customer_id);
        $storeName = Store::whereKey($order->store_id)->value('name') ?: BusinessProfile::current()->name;
        $ref = $order->basket_number ?: ('Pedido #'.$order->id);
        $this->sendTemplate(
            (int) $order->store_id,
            'payment_receipt',
            $customer?->phone,
            $rule['template'],
            [
                $customer?->full_name ?: ($order->customer_snapshot['full_name'] ?? 'Cliente'),
                $this->formatMoney($paidAmount, $order->currency),
                $storeName,
                $ref,
                $this->formatMoney($pendingTotal, $order->currency),
            ],
            entityType: 'order',
            entityId: (int) $order->id,
        );
    }

    public function notifyOrderDispatched(SalesOrder $order, string $pendingTotal): void
    {
        if (! Schema::hasTable('store_whatsapp_configs')) {
            return;
        }
        $auto = $this->getAutomations((int) $order->store_id);
        $rule = $auto['rules']['order_dispatched'] ?? null;
        if (! ($rule['enabled'] ?? false)) {
            return;
        }
        $customer = Customer::withTrashed()->find($order->customer_id);
        $storeName = Store::whereKey($order->store_id)->value('name') ?: BusinessProfile::current()->name;
        $ref = $order->basket_number ?: ('#'.$order->id);
        $this->sendTemplate(
            (int) $order->store_id,
            'order_dispatched',
            $customer?->phone,
            $rule['template'],
            [
                $customer?->full_name ?: ($order->customer_snapshot['full_name'] ?? 'Cliente'),
                $ref,
                $storeName,
                $this->formatMoney((string) $order->grand_total, $order->currency),
                $this->formatMoney($pendingTotal, $order->currency),
            ],
            entityType: 'order',
            entityId: (int) $order->id,
            cooldownHours: 1,
        );
    }

    public function sendReceivablesReminder(Store $store, int $customerId, string $pendingTotal, string $reference): array
    {
        $auto = $this->getAutomations($store->id);
        $rule = $auto['rules']['receivables_reminder'];
        $customer = Customer::where('store_id', $store->id)->findOrFail($customerId);
        $currency = BusinessProfile::current()->default_currency ?: 'USD';

        return $this->sendTemplate(
            $store->id,
            'receivables_reminder',
            $customer->phone,
            $rule['template'],
            [
                $customer->full_name,
                $this->formatMoney($pendingTotal, $currency),
                $store->name,
                $reference,
            ],
            entityType: 'customer',
            entityId: $customer->id,
        );
    }

    public function sendCatalogLink(Store $store, int $customerId, string $shareUrl, string $expiresAt): array
    {
        $auto = $this->getAutomations($store->id);
        $rule = $auto['rules']['catalog_link'];
        $customer = Customer::where('store_id', $store->id)->findOrFail($customerId);
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        try {
            $expFormatted = CarbonImmutable::parse($expiresAt)->timezone($tz)->format('d/m/Y · h:i a');
        } catch (\Throwable) {
            $expFormatted = $expiresAt;
        }

        return $this->sendTemplate(
            $store->id,
            'catalog_link',
            $customer->phone,
            $rule['template'],
            [$customer->full_name, $store->name, $shareUrl, $expFormatted],
            entityType: 'customer',
            entityId: $customer->id,
        );
    }

    public function sendWeeklyClosePdf(Store $store, object $weekly, object $invoice): array
    {
        $auto = $this->getAutomations($store->id);
        $rule = $auto['rules']['weekly_close_pdf'];
        $customer = Customer::withTrashed()->find($weekly->customer_id);
        $this->documents->ensure((int) $invoice->id);
        $doc = DB::table('invoice_documents')->where('invoice_id', $invoice->id)->where('version', 1)->where('status', 'ready')->first();
        $pdfPath = $doc ? Storage::disk('private_invoices')->path($doc->object_key) : null;
        $paid = bcadd((string) ($weekly->paid_at_close ?? '0'), '0', 4);
        $pending = bcsub((string) $invoice->grand_total, $paid, 4);
        if (bccomp($pending, '0', 4) < 0) {
            $pending = '0.0000';
        }

        return $this->sendTemplate(
            $store->id,
            'weekly_close_pdf',
            $customer?->phone,
            $rule['template'],
            [
                $customer?->full_name ?: 'Cliente',
                $weekly->period_start.' al '.$weekly->period_end,
                $store->name,
                $this->formatMoney((string) $invoice->grand_total, $invoice->currency),
                $this->formatMoney($paid, $invoice->currency),
                $this->formatMoney($pending, $invoice->currency),
            ],
            pdfPath: $pdfPath,
            pdfFilename: $invoice->invoice_number.'.pdf',
            entityType: 'weekly_account',
            entityId: (int) $weekly->id,
        );
    }

    public function notifyLowStockIfNeeded(int $storeId, int $warehouseId, int $variantId, string $available): void
    {
        if (! Schema::hasTable('store_whatsapp_configs')) {
            return;
        }
        $auto = $this->getAutomations($storeId);
        $rule = $auto['rules']['low_stock_alert'] ?? null;
        if (! ($rule['enabled'] ?? false) || empty($auto['owner_phone'])) {
            return;
        }
        $threshold = (string) ($rule['threshold'] ?? 2);
        if (bccomp($available, $threshold, 4) > 0) {
            return;
        }
        $variant = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.id', $variantId)
            ->select('p.name', 'v.sku')
            ->first();
        if (! $variant) {
            return;
        }
        $storeName = Store::whereKey($storeId)->value('name') ?: 'BShop';
        $warehouseName = DB::table('warehouses')->where('id', $warehouseId)->value('name') ?: 'Almacén';
        $qty = rtrim(rtrim(bcadd($available, '0', 4), '0'), '.') ?: '0';

        $this->sendTemplate(
            $storeId,
            'low_stock_alert',
            $auto['owner_phone'],
            $rule['template'],
            [$storeName, $variant->name, $variant->sku, $qty, $warehouseName],
            entityType: 'variant',
            entityId: $variantId,
            cooldownHours: 24,
        );
    }

    public function sendOwnerDailySummary(Store $store): array
    {
        $auto = $this->getAutomations($store->id);
        $rule = $auto['rules']['owner_daily_summary'];
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        $currency = BusinessProfile::current()->default_currency ?: 'USD';
        $now = CarbonImmutable::now($tz);
        $startUtc = $now->startOfDay()->utc();
        $endUtc = $now->endOfDay()->utc();

        $salesToday = (string) (DB::table('orders')
            ->where('store_id', $store->id)
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$startUtc, $endUtc])
            ->sum('grand_total') ?: '0');

        $collectedToday = (string) (DB::table('payments')
            ->where('store_id', $store->id)
            ->where('status', 'posted')
            ->whereBetween('received_at', [$startUtc, $endUtc])
            ->sum('amount') ?: '0');

        $activeBaskets = DB::table('orders')
            ->where('store_id', $store->id)
            ->where('order_kind', 'reservation')
            ->whereNull('closed_at')
            ->whereIn('status', ['draft', 'reserved'])
            ->count();

        $cxcPending = '0.0000';
        $openOrders = SalesOrder::where('store_id', $store->id)->where('status', '!=', 'cancelled')->get();
        foreach ($openOrders as $o) {
            $inv = OrderInvoice::query($o->id)->first();
            $pos = OrderSettlement::position($o, $inv?->id);
            $cxcPending = bcadd($cxcPending, $pos['pending_total'], 4);
        }

        return $this->sendTemplate(
            $store->id,
            'owner_daily_summary',
            $auto['owner_phone'],
            $rule['template'],
            [
                $store->name,
                $now->format('d/m/Y'),
                $this->formatMoney($salesToday, $currency),
                $this->formatMoney($collectedToday, $currency),
                (string) $activeBaskets,
                $this->formatMoney($cxcPending, $currency),
            ],
            entityType: 'store',
            entityId: $store->id,
        );
    }

    public function recentLogs(int $storeId): array
    {
        if (! Schema::hasTable('whatsapp_notification_logs')) {
            return [];
        }

        return DB::table('whatsapp_notification_logs')
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'event_type' => $r->event_type,
                'destination' => $r->destination,
                'template_name' => $r->template_name,
                'status' => $r->status,
                'error_code' => $r->error_code,
                'created_at' => $r->created_at,
            ])
            ->all();
    }

    private function recordLog(
        int $storeId,
        string $eventType,
        ?string $entityType,
        ?int $entityId,
        string $destination,
        string $templateName,
        string $status,
        ?string $providerMessageId,
        ?string $errorCode,
        array $payloadPreview,
    ): array {
        if (Schema::hasTable('whatsapp_notification_logs')) {
            DB::table('whatsapp_notification_logs')->insert([
                'store_id' => $storeId,
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'destination' => $destination,
                'template_name' => $templateName,
                'status' => $status,
                'provider_message_id' => $providerMessageId,
                'error_code' => $errorCode,
                'payload_preview' => json_encode($payloadPreview, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error_code' => $errorCode,
        ];
    }

    private function formatMoney(string $value, string $currency): string
    {
        $parts = explode('.', bcadd($value, '0', 4));
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
        $fraction = str_pad(rtrim($parts[1] ?? '00', '0'), 2, '0');

        return ($currency === 'USD' ? 'US$' : $currency).' '.$whole.'.'.$fraction;
    }
}
