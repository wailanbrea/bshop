<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreContext;
use App\Models\BusinessProfile;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BasketWhatsAppDispatchService
{
    public const DEFAULT_MESSAGE = 'Hola {cliente}, te compartimos el resumen/factura de tus apartados en {tienda}. Total: {total} · Pendiente: {pendiente}.';

    public const SCOPES = [
        'open_and_invoiced_pending',
        'invoiced_pending_only',
        'all_open_baskets',
    ];

    public function __construct(
        private WhatsAppConfiguration $configuration,
        private WhatsAppInvoice $whatsappInvoice,
        private AuditTrailService $audit,
    ) {}

    public function overview(StoreContext $context): array
    {
        $store = $context->store();
        $schedule = $this->getSchedule($store->id);
        $whatsapp = $this->configuration->status(false, $store->id);
        $recipients = $this->previewRecipients($store, $schedule['scope'], $schedule['message']);
        $batches = $this->recentBatches($store->id);

        return [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'schedule' => $schedule,
            'whatsapp' => $whatsapp,
            'recipients' => $recipients,
            'batches' => $batches,
        ];
    }

    public function getSchedule(int $storeId): array
    {
        $row = DB::table('store_whatsapp_configs')->where('store_id', $storeId)->first();
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        $enabled = (bool) ($row->auto_dispatch_enabled ?? false);
        $weekday = (int) ($row->auto_dispatch_weekday ?? 4);
        $time = (string) ($row->auto_dispatch_time ?? '18:00');
        $scope = (string) ($row->auto_dispatch_scope ?? 'open_and_invoiced_pending');
        $message = (string) ($row->auto_dispatch_message ?: self::DEFAULT_MESSAGE);

        return [
            'enabled' => $enabled,
            'weekday' => $weekday,
            'time' => $time,
            'scope' => $scope,
            'message' => $message,
            'timezone' => $tz,
            'last_run_date' => $row->last_auto_dispatch_date ?? null,
            'next_run_at' => $enabled ? $this->computeNextRun($weekday, $time, $tz, $row->last_auto_dispatch_date ?? null) : null,
        ];
    }

    public function saveSchedule(StoreContext $context, array $data, User $actor): array
    {
        $store = $context->store();
        DB::transaction(function () use ($store, $data, $actor) {
            $existing = DB::table('store_whatsapp_configs')->where('store_id', $store->id)->lockForUpdate()->first();
            $payload = [
                'auto_dispatch_enabled' => (bool) $data['enabled'],
                'auto_dispatch_weekday' => (int) $data['weekday'],
                'auto_dispatch_time' => $data['time'],
                'auto_dispatch_scope' => $data['scope'],
                'auto_dispatch_message' => trim((string) ($data['message'] ?? '')) ?: self::DEFAULT_MESSAGE,
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
            $this->audit->record('whatsapp.apartados_schedule.updated', $store, after: $payload, actor: $actor);
        });

        return $this->getSchedule($store->id);
    }

    public function previewRecipients(Store $store, string $scope, string $messageTemplate): array
    {
        $orders = SalesOrder::where('store_id', $store->id)
            ->where('order_kind', 'reservation')
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->get();

        $currency = BusinessProfile::current()->default_currency ?: 'USD';
        $grouped = [];

        foreach ($orders as $order) {
            $invoice = OrderInvoice::query($order->id)->first();
            $position = OrderSettlement::position($order, $invoice?->id);
            $pending = $position['pending_total'];
            $hasPending = bccomp($pending, '0', 4) > 0;
            $isOpen = $order->closed_at === null && in_array($order->status, ['draft', 'reserved'], true);

            $include = match ($scope) {
                'invoiced_pending_only' => $invoice !== null && $hasPending,
                'all_open_baskets' => $isOpen,
                default => $isOpen || ($invoice !== null && $hasPending),
            };
            if (! $include) {
                continue;
            }

            $cid = (int) $order->customer_id;
            if (! isset($grouped[$cid])) {
                $customerRow = DB::table('customers')->where('id', $cid)->first();
                $snap = is_array($order->customer_snapshot) ? $order->customer_snapshot : [];
                $rawPhone = $customerRow->phone ?? ($snap['phone'] ?? '');
                $cleanPhone = WhatsAppInboxService::normalizePhone((string) $rawPhone) ?? '';
                $validMetaPhone = $cleanPhone !== '';
                $waDigits = $validMetaPhone ? ltrim($cleanPhone, '+') : preg_replace('/\D+/', '', (string) $rawPhone);

                $grouped[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $customerRow->full_name ?? ($snap['full_name'] ?? 'Cliente'),
                    'phone' => $rawPhone ?: null,
                    'clean_phone' => $cleanPhone ?: null,
                    'wa_digits' => $waDigits,
                    'phone_valid' => $validMetaPhone,
                    'currency' => $order->currency ?: $currency,
                    'orders_count' => 0,
                    'order_refs' => [],
                    'invoice_ids' => [],
                    'invoice_numbers' => [],
                    'grand_total' => '0.0000',
                    'paid_total' => '0.0000',
                    'pending_total' => '0.0000',
                ];
            }

            $grouped[$cid]['orders_count']++;
            $grouped[$cid]['order_refs'][] = $order->basket_number ?: ('#'.$order->id);
            if ($invoice) {
                $grouped[$cid]['invoice_ids'][$invoice->id] = (int) $invoice->id;
                $grouped[$cid]['invoice_numbers'][$invoice->id] = $invoice->invoice_number;
            }
            $grouped[$cid]['grand_total'] = bcadd($grouped[$cid]['grand_total'], $position['net_total'], 4);
            $grouped[$cid]['paid_total'] = bcadd($grouped[$cid]['paid_total'], $position['paid_total'], 4);
            $grouped[$cid]['pending_total'] = bcadd($grouped[$cid]['pending_total'], $pending, 4);
        }

        $result = [];
        foreach ($grouped as $item) {
            $invoiceIds = array_values($item['invoice_ids']);
            $invoiceNumbers = array_values($item['invoice_numbers']);
            $formattedTotal = $this->formatMoney($item['grand_total'], $item['currency']);
            $formattedPending = $this->formatMoney($item['pending_total'], $item['currency']);
            $formattedPaid = $this->formatMoney($item['paid_total'], $item['currency']);
            $invoiceLabel = count($invoiceNumbers) ? implode(', ', $invoiceNumbers) : implode(', ', $item['order_refs']);

            $text = strtr($messageTemplate, [
                '{cliente}' => $item['customer_name'],
                '{tienda}' => $store->name,
                '{factura}' => $invoiceLabel,
                '{total}' => $formattedTotal,
                '{abonado}' => $formattedPaid,
                '{pendiente}' => $formattedPending,
            ]);

            $item['invoice_ids'] = $invoiceIds;
            $item['invoice_numbers'] = $invoiceNumbers;
            $item['has_invoice'] = count($invoiceIds) > 0;
            $item['wa_me_url'] = 'https://wa.me/'.$item['wa_digits'].'?text='.rawurlencode($text);
            $item['preview_message'] = $text;
            $result[] = $item;
        }

        usort($result, fn ($a, $b) => strcmp($a['customer_name'], $b['customer_name']));

        return $result;
    }

    public function dispatchNow(StoreContext $context, ?array $customerIds, ?User $actor, string $triggerType = 'manual'): array
    {
        $store = $context->store();
        $schedule = $this->getSchedule($store->id);
        $whatsapp = $this->configuration->status(false, $store->id);
        abort_unless($whatsapp['ready'], 409, 'Configura y activa WhatsApp Business API en Configuración → WhatsApp para enviar automáticamente.');

        $recipients = $this->previewRecipients($store, $schedule['scope'], $schedule['message']);
        if ($customerIds !== null && count($customerIds) > 0) {
            $allowed = array_map('intval', $customerIds);
            $recipients = array_values(array_filter($recipients, fn ($r) => in_array((int) $r['customer_id'], $allowed, true)));
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $details = [];

        $effectiveActor = $actor ?? $this->fallbackStoreActor($store->id);

        foreach ($recipients as $recipient) {
            if (! $recipient['phone_valid']) {
                $skipped++;
                $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'status' => 'skipped', 'reason' => 'Teléfono internacional inválido'];
                continue;
            }
            if (empty($recipient['invoice_ids'])) {
                $skipped++;
                $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'status' => 'skipped', 'reason' => 'Sin factura emitida'];
                continue;
            }
            if (! $effectiveActor) {
                $failed++;
                $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'status' => 'failed', 'reason' => 'Sin usuario autorizado en la tienda'];
                continue;
            }

            foreach ($recipient['invoice_ids'] as $invoiceId) {
                try {
                    $delivery = $this->whatsappInvoice->send($context, (int) $invoiceId, (string) Str::uuid(), $effectiveActor);
                    if (in_array($delivery['status'], ['accepted', 'sent', 'delivered', 'read'], true)) {
                        $sent++;
                        $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'invoice_id' => $invoiceId, 'status' => 'sent'];
                    } else {
                        $failed++;
                        $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'invoice_id' => $invoiceId, 'status' => 'failed', 'reason' => $delivery['error_code'] ?? $delivery['status']];
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $details[] = ['customer_id' => $recipient['customer_id'], 'customer_name' => $recipient['customer_name'], 'invoice_id' => $invoiceId, 'status' => 'failed', 'reason' => $e->getMessage()];
                }
            }
        }

        $batchId = DB::table('whatsapp_dispatch_batches')->insertGetId([
            'store_id' => $store->id,
            'triggered_by_user_id' => $actor?->id,
            'trigger_type' => $triggerType,
            'channel' => 'meta_api',
            'total_targets' => count($recipients),
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->presentBatch(DB::table('whatsapp_dispatch_batches')->where('id', $batchId)->first());
    }

    public function logManualWaMe(StoreContext $context, int $customerId, string $customerName, User $actor): array
    {
        $batchId = DB::table('whatsapp_dispatch_batches')->insertGetId([
            'store_id' => $context->store()->id,
            'triggered_by_user_id' => $actor->id,
            'trigger_type' => 'manual',
            'channel' => 'wa_me',
            'total_targets' => 1,
            'sent_count' => 1,
            'failed_count' => 0,
            'skipped_count' => 0,
            'details' => json_encode([['customer_id' => $customerId, 'customer_name' => $customerName, 'status' => 'opened_wa_me']], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->presentBatch(DB::table('whatsapp_dispatch_batches')->where('id', $batchId)->first());
    }

    public function recentBatches(int $storeId): array
    {
        return DB::table('whatsapp_dispatch_batches')
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($row) => $this->presentBatch($row))
            ->all();
    }

    private function presentBatch(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'trigger_type' => $row->trigger_type,
            'channel' => $row->channel,
            'total_targets' => (int) $row->total_targets,
            'sent_count' => (int) $row->sent_count,
            'failed_count' => (int) $row->failed_count,
            'skipped_count' => (int) $row->skipped_count,
            'details' => $row->details ? json_decode($row->details, true, flags: JSON_THROW_ON_ERROR) : [],
            'created_at' => $row->created_at,
        ];
    }

    private function fallbackStoreActor(int $storeId): ?User
    {
        $userIds = DB::table('store_user')->where('store_id', $storeId)->pluck('user_id')->all();
        if (empty($userIds)) {
            return null;
        }

        return User::whereIn('id', $userIds)->where('is_active', true)->orderBy('id')->get()->first(fn (User $u) => $u->permits('sales.create'));
    }

    private function computeNextRun(int $weekday, string $time, string $tz, ?string $lastRunDate): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time) + [18, 0]);
        $now = CarbonImmutable::now($tz);
        $candidate = $now->setTime($hour, $minute, 0);

        if ($weekday === 0) {
            if ($candidate->lessThanOrEqualTo($now) || ($lastRunDate && $lastRunDate === $candidate->toDateString())) {
                $candidate = $candidate->addDay();
            }

            return $candidate->toIso8601String();
        }

        for ($i = 0; $i < 8; $i++) {
            $day = $candidate->addDays($i);
            if ($day->dayOfWeekIso !== $weekday) {
                continue;
            }
            if ($i === 0 && ($day->lessThanOrEqualTo($now) || ($lastRunDate && $lastRunDate === $day->toDateString()))) {
                continue;
            }

            return $day->toIso8601String();
        }

        return $candidate->addWeek()->toIso8601String();
    }

    private function formatMoney(string $value, string $currency): string
    {
        $parts = explode('.', bcadd($value, '0', 4));
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
        $fraction = str_pad(rtrim($parts[1] ?? '00', '0'), 2, '0');

        return ($currency === 'USD' ? 'US$' : $currency).' '.$whole.'.'.$fraction;
    }
}
