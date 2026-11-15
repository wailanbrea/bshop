<?php

namespace App\Console\Commands;

use App\Domain\Sales\ReservationExpiry;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ExpireReservations extends Command
{
    protected $signature = 'bshop:reservations-expire {--apply : Commit eligible expirations; otherwise read-only} {--limit=100 : Maximum orders per run}';

    protected $description = 'Expire unpaid, uninvoiced reservations with an explicit elapsed deadline';

    public function handle(ReservationExpiry $expiry): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $cutoff = CarbonImmutable::now('UTC');
        $orders = SalesOrder::where('order_kind', 'reservation')->where('status', 'reserved')->whereNull('closed_at')->whereNull('shipped_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_payments as p')->whereColumn('p.order_id', 'orders.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as i')->whereColumn('i.order_id', 'orders.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as delivered')->join('order_items as di', 'di.id', '=', 'delivered.order_item_id')->whereColumn('di.order_id', 'orders.id')->where('delivered.fulfilled_quantity', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as future')->join('order_items as fi', 'fi.id', '=', 'future.order_item_id')->whereColumn('fi.order_id', 'orders.id')->where('future.status', 'active')->where(fn ($date) => $date->whereNull('future.expires_at')->orWhere('future.expires_at', '>', $cutoff)))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as r')->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')->whereColumn('oi.order_id', 'orders.id')->where('r.status', 'active')->where('r.expires_at', '<=', $cutoff))
            ->orderBy('id')->limit((int) $limit)->pluck('id');
        $counts = [];
        $failed = false;
        foreach ($orders as $id) {
            try {
                $status = $expiry->inspect($id, $cutoff, (bool) $this->option('apply'));
                $counts[$status] = ($counts[$status] ?? 0) + 1;
            } catch (\Throwable $e) {
                report($e);
                $counts['failed'] = ($counts['failed'] ?? 0) + 1;
                $failed = true;
            }
        }
        $this->line(json_encode(['read_only' => ! $this->option('apply'), 'checked' => $orders->count(), 'results' => (object) $counts, 'cutoff' => $cutoff->toIso8601String()], JSON_THROW_ON_ERROR));

        return $failed || isset($counts['inconsistent']) ? self::FAILURE : self::SUCCESS;
    }
}
