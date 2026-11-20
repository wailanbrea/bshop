<?php

namespace App\Console\Commands;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\BasketWhatsAppDispatchService;
use App\Domain\Sales\WhatsAppConfiguration;
use App\Models\BusinessProfile;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchScheduledBasketWhatsApp extends Command
{
    protected $signature = 'bshop:whatsapp-dispatch-scheduled';

    protected $description = 'Envía automáticamente las facturas de apartados por WhatsApp según la programación de cada tienda.';

    public function handle(BasketWhatsAppDispatchService $dispatch, WhatsAppConfiguration $config): int
    {
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        $now = CarbonImmutable::now($tz);
        $today = $now->toDateString();
        $currentTime = $now->format('H:i');
        $currentWeekday = $now->dayOfWeekIso; // 1..7

        $rows = DB::table('store_whatsapp_configs')
            ->where('auto_dispatch_enabled', true)
            ->get();

        foreach ($rows as $row) {
            if ($row->last_auto_dispatch_date === $today) {
                continue;
            }
            $weekday = (int) $row->auto_dispatch_weekday;
            if ($weekday !== 0 && $weekday !== $currentWeekday) {
                continue;
            }
            if ($currentTime < (string) $row->auto_dispatch_time) {
                continue;
            }
            if (! $config->status(false, (int) $row->store_id)['ready']) {
                continue;
            }

            $store = Store::whereKey($row->store_id)->where('is_active', true)->first();
            if (! $store) {
                continue;
            }

            // Atomically claim today's run so overlapping invocations never double-send.
            $claimed = DB::table('store_whatsapp_configs')
                ->where('id', $row->id)
                ->where(fn ($q) => $q->whereNull('last_auto_dispatch_date')->orWhere('last_auto_dispatch_date', '!=', $today))
                ->update(['last_auto_dispatch_date' => $today, 'updated_at' => now()]);

            if (! $claimed) {
                continue;
            }

            $context = new StoreContext;
            $context->useStore($store);
            $batch = $dispatch->dispatchNow($context, null, null, 'scheduled');
            $this->info("Store #{$store->id} ({$store->name}): sent={$batch['sent_count']}, failed={$batch['failed_count']}, skipped={$batch['skipped_count']}");
        }

        return self::SUCCESS;
    }
}
