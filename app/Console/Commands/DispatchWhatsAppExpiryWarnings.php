<?php

namespace App\Console\Commands;

use App\Domain\Sales\WhatsAppTemplateDispatcher;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchWhatsAppExpiryWarnings extends Command
{
    protected $signature = 'bshop:whatsapp-expiry-warnings';

    protected $description = 'Envía avisos automáticos por WhatsApp a clientes cuyos apartados están próximos a vencer.';

    public function handle(WhatsAppTemplateDispatcher $dispatcher): int
    {
        $tz = BusinessProfile::current()->timezone ?: 'America/Santo_Domingo';
        $configs = DB::table('store_whatsapp_configs')->where('enabled', true)->get();

        foreach ($configs as $cfg) {
            $auto = $dispatcher->getAutomations((int) $cfg->store_id);
            $rule = $auto['rules']['expiry_warning'] ?? null;
            if (! ($rule['enabled'] ?? false)) {
                continue;
            }
            $store = Store::whereKey($cfg->store_id)->where('is_active', true)->first();
            if (! $store) {
                continue;
            }
            $hours = (int) ($rule['hours_before'] ?? 24);
            $windowEnd = now()->addHours($hours);

            $orders = SalesOrder::where('store_id', $store->id)
                ->where('order_kind', 'reservation')
                ->whereIn('status', ['draft', 'reserved'])
                ->whereNull('closed_at')
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '>', now())
                ->where('reserved_until', '<=', $windowEnd)
                ->get();

            foreach ($orders as $order) {
                $customer = Customer::withTrashed()->find($order->customer_id);
                $expiresFormatted = CarbonImmutable::parse($order->reserved_until)->timezone($tz)->format('d/m/Y · h:i a');
                $parts = explode('.', bcadd((string) $order->grand_total, '0', 4));
                $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
                $frac = str_pad(rtrim($parts[1] ?? '00', '0'), 2, '0');
                $totalStr = ($order->currency === 'USD' ? 'US$' : $order->currency).' '.$whole.'.'.$frac;
                $ref = $order->basket_number ?: ('Apartado #'.$order->id);

                $dispatcher->sendTemplate(
                    $store->id,
                    'expiry_warning',
                    $customer?->phone,
                    $rule['template'],
                    [
                        $customer?->full_name ?: ($order->customer_snapshot['full_name'] ?? 'Cliente'),
                        $ref,
                        $store->name,
                        $totalStr,
                        $expiresFormatted,
                    ],
                    entityType: 'order',
                    entityId: (int) $order->id,
                    cooldownHours: max(24, $hours + 12),
                );
            }
        }

        return self::SUCCESS;
    }
}
