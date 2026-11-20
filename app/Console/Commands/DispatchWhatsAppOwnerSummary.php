<?php

namespace App\Console\Commands;

use App\Domain\Sales\WhatsAppConfiguration;
use App\Domain\Sales\WhatsAppTemplateDispatcher;
use App\Models\BusinessProfile;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchWhatsAppOwnerSummary extends Command
{
    protected $signature = 'bshop:whatsapp-owner-summary';

    protected $description = 'Envía el resumen diario automático de ventas, cobros y CxC al WhatsApp del dueño de cada tienda.';

    public function handle(WhatsAppTemplateDispatcher $dispatcher, WhatsAppConfiguration $config): int
    {
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');

        $rows = DB::table('store_whatsapp_configs')->where('enabled', true)->whereNotNull('owner_phone')->get();

        foreach ($rows as $row) {
            if ($row->last_daily_summary_date === $today) {
                continue;
            }
            if ($currentTime < (string) ($row->daily_summary_time ?: '21:00')) {
                continue;
            }
            $auto = $dispatcher->getAutomations((int) $row->store_id);
            if (! ($auto['rules']['owner_daily_summary']['enabled'] ?? false)) {
                continue;
            }
            if (! $config->status(false, (int) $row->store_id)['ready']) {
                continue;
            }
            $store = Store::whereKey($row->store_id)->where('is_active', true)->first();
            if (! $store) {
                continue;
            }

            $claimed = DB::table('store_whatsapp_configs')
                ->where('id', $row->id)
                ->where(fn ($q) => $q->whereNull('last_daily_summary_date')->orWhere('last_daily_summary_date', '!=', $today))
                ->update(['last_daily_summary_date' => $today, 'updated_at' => now()]);

            if (! $claimed) {
                continue;
            }

            $res = $dispatcher->sendOwnerDailySummary($store);
            $this->info("Owner summary store #{$store->id} ({$store->name}): {$res['status']}");
        }

        return self::SUCCESS;
    }
}
