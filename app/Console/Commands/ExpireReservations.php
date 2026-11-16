<?php

namespace App\Console\Commands;

use App\Domain\Sales\ReservationExpiry;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ExpireReservations extends Command
{
    protected $signature = 'bshop:reservations-expire {--apply : Commit eligible expirations; otherwise read-only} {--limit=100 : Maximum orders per run} {--after-id=0 : Inspect orders after this ID}';

    protected $description = 'Expire unpaid, uninvoiced reservations with an explicit elapsed deadline';

    public function handle(ReservationExpiry $expiry): int
    {
        $limit = (string) $this->option('limit');
        if (! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 1000) {
            $this->error('limit must be between 1 and 1000.');

            return self::INVALID;
        }
        $after = (string) $this->option('after-id');
        $afterId = filter_var($after, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (! ctype_digit($after) || $afterId === false) {
            $this->error('after-id must be a non-negative integer within the supported range.');

            return self::INVALID;
        }
        $cutoff = CarbonImmutable::now('UTC');
        $orders = $this->candidates($cutoff)->where('orders.id', '>', $afterId)->orderBy('id')->limit((int) $limit)->pluck('id');
        $counts = [];
        $attention = [];
        $failed = false;
        foreach ($orders as $id) {
            try {
                $status = $expiry->inspect($id, $cutoff, (bool) $this->option('apply'));
                $counts[$status] = ($counts[$status] ?? 0) + 1;
                if (in_array($status, ['inconsistent', 'changed'], true)) {
                    $attention[] = ['order_id' => (int) $id, 'status' => $status];
                }
            } catch (\Throwable $e) {
                report($e);
                $counts['failed'] = ($counts['failed'] ?? 0) + 1;
                $failed = true;
                $attention[] = ['order_id' => (int) $id, 'status' => 'failed'];
            }
        }
        $this->line(json_encode(['read_only' => ! $this->option('apply'), 'checked' => $orders->count(), 'results' => (object) $counts, 'cutoff' => $cutoff->toIso8601String(), 'after_id' => $afterId, 'next_after_id' => $orders->isEmpty() ? null : (int) $orders->last(), 'attention' => $attention], JSON_THROW_ON_ERROR));

        return $failed || isset($counts['inconsistent']) ? self::FAILURE : self::SUCCESS;
    }

    public function candidates(CarbonImmutable $cutoff): Builder
    {
        return SalesOrder::where('order_kind', 'reservation')->where('status', 'reserved')->whereNull('closed_at')->whereNull('shipped_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('order_payments as op')->join('payments as p', 'p.id', '=', 'op.payment_id')->whereColumn('op.order_id', 'orders.id')->whereIn('p.status', ['pending', 'confirmed']))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('invoices as i')->whereColumn('i.order_id', 'orders.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as delivered')->join('order_items as di', 'di.id', '=', 'delivered.order_item_id')->whereColumn('di.order_id', 'orders.id')->where('delivered.fulfilled_quantity', '>', 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as future')->join('order_items as fi', 'fi.id', '=', 'future.order_item_id')->whereColumn('fi.order_id', 'orders.id')->where('future.status', 'active')->where(fn ($date) => $date->whereNull('future.expires_at')->orWhere('future.expires_at', '>', $cutoff)))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_reservations as r')->join('order_items as oi', 'oi.id', '=', 'r.order_item_id')->whereColumn('oi.order_id', 'orders.id')->where('r.status', 'active')->where('r.expires_at', '<=', $cutoff));
    }
}
