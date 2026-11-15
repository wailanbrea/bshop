<?php

namespace App\Domain\Sales;

use App\Domain\Business\AuditTrailService;
use App\Domain\Inventory\StockLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ReservationExpiry
{
    public function __construct(private StockLedger $ledger, private AuditTrailService $audit) {}

    /** Snapshot once; editing quantities must not silently extend the deadline. */
    public function deadline(?int $orderId = null): ?string
    {
        if ($orderId !== null) {
            $rows = DB::table('stock_reservations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $orderId)->select('id'))->get(['expires_at']);
            if ($rows->isNotEmpty()) {
                return $rows->contains(fn ($r) => $r->expires_at === null) ? null : $rows->min('expires_at');
            }
        }
        $hours = DB::table('business_settings')->where('id', 1)->value('reserve_expiry_hours');

        return $hours ? now()->addHours((int) $hours)->format('Y-m-d H:i:s') : null;
    }

    /** One order per transaction; recheck after the same lock order used by stock writers. */
    public function inspect(int $orderId, CarbonImmutable $cutoff, bool $apply = false): string
    {
        return DB::transaction(function () use ($orderId, $cutoff, $apply) {
            $ref = SalesOrder::find($orderId);
            if (! $ref || ! $ref->warehouse_id) {
                return 'skipped';
            }
            $ids = DB::table('order_items')->where('order_id', $orderId)->distinct()->pluck('variant_id')->all();
            $references = ProductVariant::withTrashed()->whereIn('id', $ids)->get();
            $products = Product::withTrashed()->whereIn('id', $references->pluck('product_id'))->orderBy('id')->lockForUpdate()->get();
            ProductVariant::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            Store::whereKey($ref->store_id)->lockForUpdate()->firstOrFail();
            $warehouse = Warehouse::where('store_id', $ref->store_id)->whereKey($ref->warehouse_id)->lockForUpdate()->firstOrFail();
            $balances = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->whereIn('variant_id', $ids)->orderBy('variant_id')->lockForUpdate()->get()->keyBy('variant_id');
            $order = SalesOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $items = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->lockForUpdate()->get();
            // A basket edit may have added a variant while this worker waited.
            if (array_diff($items->pluck('variant_id')->all(), $ids)) {
                return 'changed';
            }
            if ($order->order_kind !== 'reservation' || $order->status !== 'reserved' || $order->closed_at || $order->shipped_at) {
                return 'skipped';
            }
            $reservations = DB::table('stock_reservations')->whereIn('order_item_id', $items->pluck('id'))->orderBy('id')->lockForUpdate()->get();
            $active = $reservations->where('status', 'active');
            if ($active->isEmpty() || $active->contains(fn ($r) => ! $r->expires_at || CarbonImmutable::parse($r->expires_at, 'UTC')->greaterThan($cutoff))) {
                return 'skipped';
            }
            // Never resolve deposits, invoices or partial deliveries implicitly.
            // Locking reads see commits made while waiting under MySQL REPEATABLE READ.
            if (OrderInvoice::query($orderId)->lockForUpdate()->first()
                || DB::table('order_payments')->join('payments', 'payments.id', '=', 'order_payments.payment_id')->where('order_id', $orderId)->whereIn('payments.status', ['pending', 'confirmed'])->lockForUpdate()->first()
                || $reservations->contains(fn ($r) => bccomp((string) $r->fulfilled_quantity, '0', 4) > 0)) {
                return 'protected';
            }
            foreach ($active as $r) {
                $remaining = bcsub(bcsub((string) $r->quantity, (string) $r->fulfilled_quantity, 4), (string) $r->released_quantity, 4);
                if (! $balances->has($r->variant_id) || bccomp($remaining, '0', 4) <= 0 || $items->firstWhere('id', $r->order_item_id)?->removed_at !== null) {
                    return 'inconsistent';
                }
            }
            if (! $apply) {
                return 'eligible';
            }
            foreach ($active as $r) {
                $remaining = bcsub(bcsub((string) $r->quantity, (string) $r->fulfilled_quantity, 4), (string) $r->released_quantity, 4);
                $balance = $balances[$r->variant_id];
                $movement = $this->ledger->record($warehouse, $r->variant_id, $balance, '0.0000', bcsub('0', $remaining, 4), 'expire', 'reservation:expire:'.$r->id, 'Vencimiento de apartado #'.$order->basket_number, null, $r->id);
                $balance->reserved = $movement['reserved_after'];
                $balance->version = $movement['balance_version'];
                DB::table('stock_reservations')->where('id', $r->id)->update(['released_quantity' => bcadd((string) $r->released_quantity, $remaining, 4), 'status' => 'expired', 'updated_at' => now()]);
            }
            $before = ['status' => $order->status, 'version' => $order->lock_version];
            $order->status = 'cancelled';
            $order->cancelled_at = now();
            $order->lock_version++;
            $order->save();
            foreach ($products as $product) {
                $product->increment('lock_version');
            }
            $this->audit->record('sales.reservation.expired', $order, before: $before, after: ['status' => $order->status, 'version' => $order->lock_version, 'cutoff' => $cutoff->toIso8601String()], system: true);

            return 'expired';
        }, 3);
    }
}
