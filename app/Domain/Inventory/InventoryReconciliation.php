<?php

namespace App\Domain\Inventory;

use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/** Read-only diagnosis. Differences must never be repaired by rewriting balances. */
final class InventoryReconciliation
{
    public function inspect(int $storeId, int $warehouseId): array
    {
        return DB::transaction(function () use ($storeId, $warehouseId) {
            // Stock writers take these locks exclusively. Hold their suffix only:
            // no earlier product/variant locks are acquired after the location locks.
            Store::whereKey($storeId)->sharedLock()->firstOrFail();
            Warehouse::where('store_id', $storeId)->whereKey($warehouseId)->sharedLock()->firstOrFail();
            $balances = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->get()->keyBy('variant_id');
            $ledger = [];
            $movementCount = 0;
            foreach (DB::table('inventory_movements')->where('warehouse_id', $warehouseId)->where('store_id', $storeId)->orderBy('id')->cursor() as $movement) {
                $id = $movement->variant_id;
                $ledger[$id] ??= ['physical' => '0.0000', 'reserved' => '0.0000', 'version' => 0, 'chain_ok' => true, 'complete' => true];
                $line = &$ledger[$id];
                $line['physical'] = bcadd($line['physical'], (string) $movement->on_hand_delta, 4);
                $line['reserved'] = bcadd($line['reserved'], (string) $movement->reserved_delta, 4);
                $line['chain_ok'] = $line['chain_ok'] && bccomp($line['physical'], '0', 4) >= 0
                    && bccomp($line['reserved'], '0', 4) >= 0 && bccomp($line['reserved'], $line['physical'], 4) <= 0;
                if ($movement->balance_version === null || $movement->on_hand_after === null || $movement->reserved_after === null) {
                    $line['complete'] = false;
                } else {
                    $line['chain_ok'] = $line['chain_ok'] && (int) $movement->balance_version === $line['version'] + 1
                        && bccomp($line['physical'], (string) $movement->on_hand_after, 4) === 0
                        && bccomp($line['reserved'], (string) $movement->reserved_after, 4) === 0;
                }
                $line['version'] = (int) ($movement->balance_version ?? $line['version'] + 1);
                unset($line);
                $movementCount++;
            }
            $reserved = [];
            $invalidReservations = [];
            foreach (DB::table('stock_reservations as r')->join('order_items as i', 'i.id', '=', 'r.order_item_id')
                ->join('orders as o', 'o.id', '=', 'i.order_id')->where('r.warehouse_id', $warehouseId)->where('r.store_id', $storeId)
                ->where('r.status', 'active')->select('r.*', 'i.removed_at', 'o.status as order_status')->orderBy('r.id')->cursor() as $reservation) {
                $id = $reservation->variant_id;
                $remaining = bcsub(bcsub((string) $reservation->quantity, (string) $reservation->fulfilled_quantity, 4), (string) $reservation->released_quantity, 4);
                $reserved[$id] = bcadd($reserved[$id] ?? '0', $remaining, 4);
                if ($reservation->removed_at !== null || $reservation->order_status === 'cancelled' || bccomp($remaining, '0', 4) <= 0) {
                    $invalidReservations[$id] = true;
                }
            }
            $known = array_unique([...$balances->keys()->all(), ...array_keys($ledger), ...array_keys($reserved)]);
            $variants = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()])
                ->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()->where('track_stock', true))->orWhereIn('id', $known))
                ->orderBy('id')->get();
            $rows = [];
            $issues = 0;
            $different = 0;
            foreach ($variants as $variant) {
                $id = $variant->id;
                $balance = $balances->get($id);
                $physical = bcadd((string) ($balance->on_hand ?? '0'), '0', 4);
                $held = bcadd((string) ($balance->reserved ?? '0'), '0', 4);
                $history = $ledger[$id] ?? ['physical' => '0.0000', 'reserved' => '0.0000', 'version' => 0, 'chain_ok' => true, 'complete' => true];
                $active = $reserved[$id] ?? '0.0000';
                $differences = [];
                if (bccomp($physical, $history['physical'], 4) !== 0) {
                    $differences[] = ['code' => 'physical_ledger', 'message' => 'El saldo físico no coincide con los movimientos.'];
                }
                if (bccomp($held, $active, 4) !== 0) {
                    $differences[] = ['code' => 'reserved_active', 'message' => 'El saldo reservado no coincide con las reservas activas.'];
                }
                if (bccomp($held, $history['reserved'], 4) !== 0) {
                    $differences[] = ['code' => 'reserved_ledger', 'message' => 'El saldo reservado no coincide con los movimientos.'];
                }
                if ((int) ($balance->version ?? 0) !== $history['version']) {
                    $differences[] = ['code' => 'balance_version', 'message' => 'La versión del saldo no coincide con el último movimiento.'];
                }
                if (! $history['chain_ok']) {
                    $differences[] = ['code' => 'ledger_chain', 'message' => 'Los saldos históricos de los movimientos no son consecutivos o no coinciden.'];
                }
                if (! $history['complete']) {
                    $differences[] = ['code' => 'history_incomplete', 'message' => 'Hay movimientos históricos sin saldo o versión registrados.'];
                }
                if (isset($invalidReservations[$id])) {
                    $differences[] = ['code' => 'reservation_state', 'message' => 'Hay una reserva activa en un artículo retirado, pedido cancelado o sin unidades pendientes.'];
                }
                if ($differences) {
                    $different++;
                }
                $issues += count($differences);
                $rows[] = ['variant_id' => $id, 'sku' => $variant->sku, 'product_name' => $variant->product?->name ?? 'Producto histórico',
                    'physical' => $physical, 'reserved' => $held, 'available' => bcsub($physical, $held, 4),
                    'ledger_physical' => $history['physical'], 'ledger_reserved' => $history['reserved'], 'active_reserved' => $active,
                    'balance_version' => (int) ($balance->version ?? 0), 'ledger_version' => $history['version'], 'differences' => $differences];
            }

            return ['data' => $rows, 'summary' => ['checked' => count($rows), 'consistent' => count($rows) - $different,
                'with_differences' => $different, 'differences' => $issues, 'movements' => $movementCount], 'generated_at' => now()->toIso8601String()];
        });
    }
}
