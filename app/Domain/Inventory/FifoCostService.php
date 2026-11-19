<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Manages FIFO cost lots and immutable movement allocations inside an active StockLedger transaction. */
final class FifoCostService
{
    private static ?bool $tablesReady = null;

    public function available(): bool
    {
        if (self::$tablesReady === null) {
            self::$tablesReady = Schema::hasTable('inventory_cost_lots') && Schema::hasTable('inventory_lot_allocations');
        }

        return self::$tablesReady;
    }

    public function recordReceipt(
        int $warehouseId,
        int $variantId,
        ?int $movementId,
        string $quantity,
        ?string $unitCost,
        ?string $note = null,
        mixed $receivedAt = null,
    ): int {
        $qty = bcadd($quantity, '0', 4);
        $cost = $unitCost !== null && trim($unitCost) !== '' ? bcadd($unitCost, '0', 4) : null;
        $now = now();

        return (int) DB::table('inventory_cost_lots')->insertGetId([
            'warehouse_id' => $warehouseId,
            'variant_id' => $variantId,
            'source_movement_id' => $movementId,
            'unit_cost' => $cost,
            'initial_quantity' => $qty,
            'remaining_quantity' => $qty,
            'received_at' => $receivedAt ?? $now,
            'note' => $note !== null ? mb_substr(trim($note), 0, 255) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Consumes oldest lots first (received_at ASC, id ASC) and writes immutable allocations.
     *
     * @return array<int, array{lot_id: int|null, quantity: string, unit_cost: string|null, received_at: mixed}>
     */
    public function consumeFifo(
        int $warehouseId,
        int $variantId,
        int $movementId,
        string $quantity,
        ?int $orderItemId = null,
    ): array {
        $remaining = bcadd($quantity, '0', 4);
        if (bccomp($remaining, '0', 4) <= 0) {
            return [];
        }

        $lots = DB::table('inventory_cost_lots')
            ->where('warehouse_id', $warehouseId)
            ->where('variant_id', $variantId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $consumed = [];
        $now = now();

        foreach ($lots as $lot) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            $lotRem = bcadd((string) $lot->remaining_quantity, '0', 4);
            $take = bccomp($lotRem, $remaining, 4) >= 0 ? $remaining : $lotRem;
            $newRem = bcsub($lotRem, $take, 4);

            DB::table('inventory_cost_lots')
                ->where('id', $lot->id)
                ->update(['remaining_quantity' => $newRem, 'updated_at' => $now]);

            $unitCost = $lot->unit_cost !== null ? bcadd((string) $lot->unit_cost, '0', 4) : null;
            $totalCost = $unitCost !== null ? bcmul($take, $unitCost, 4) : null;

            DB::table('inventory_lot_allocations')->insert([
                'lot_id' => $lot->id,
                'movement_id' => $movementId,
                'order_item_id' => $orderItemId,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'created_at' => $now,
            ]);

            $consumed[] = [
                'lot_id' => (int) $lot->id,
                'quantity' => $take,
                'unit_cost' => $unitCost,
                'received_at' => $lot->received_at,
            ];
            $remaining = bcsub($remaining, $take, 4);
        }

        // Graceful fallback if legacy stock existed without a seeded lot.
        if (bccomp($remaining, '0', 4) > 0) {
            $fallback = DB::table('product_variants')->where('id', $variantId)->value('cost_price');
            $unitCost = $fallback !== null ? bcadd((string) $fallback, '0', 4) : null;
            $totalCost = $unitCost !== null ? bcmul($remaining, $unitCost, 4) : null;

            DB::table('inventory_lot_allocations')->insert([
                'lot_id' => null,
                'movement_id' => $movementId,
                'order_item_id' => $orderItemId,
                'quantity' => $remaining,
                'unit_cost' => $unitCost,
                'total_cost' => $totalCost,
                'created_at' => $now,
            ]);

            $consumed[] = [
                'lot_id' => null,
                'quantity' => $remaining,
                'unit_cost' => $unitCost,
                'received_at' => $now,
            ];
        }

        return $consumed;
    }

    /** Restores returned units preserving the exact unit_cost of the original sale's FIFO allocations. */
    public function restoreFromReturn(
        int $warehouseId,
        int $variantId,
        int $movementId,
        string $quantity,
        ?int $orderItemId = null,
        ?string $note = null,
    ): void {
        $remaining = bcadd($quantity, '0', 4);
        if (bccomp($remaining, '0', 4) <= 0) {
            return;
        }

        if ($orderItemId !== null) {
            $allocations = DB::table('inventory_lot_allocations')
                ->where('order_item_id', $orderItemId)
                ->orderByDesc('id')
                ->get();

            foreach ($allocations as $alloc) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }
                $allocQty = bcadd((string) $alloc->quantity, '0', 4);
                $take = bccomp($allocQty, $remaining, 4) >= 0 ? $remaining : $allocQty;
                $this->recordReceipt(
                    $warehouseId,
                    $variantId,
                    $movementId,
                    $take,
                    $alloc->unit_cost !== null ? bcadd((string) $alloc->unit_cost, '0', 4) : null,
                    $note ?? 'Reingreso por devolución',
                );
                $remaining = bcsub($remaining, $take, 4);
            }
        }

        if (bccomp($remaining, '0', 4) > 0) {
            $fallback = DB::table('product_variants')->where('id', $variantId)->value('cost_price');
            $this->recordReceipt(
                $warehouseId,
                $variantId,
                $movementId,
                $remaining,
                $fallback !== null ? bcadd((string) $fallback, '0', 4) : null,
                $note ?? 'Reingreso por devolución',
            );
        }
    }

    /** Transfers lots FIFO from source warehouse to destination warehouse preserving original unit_cost and received_at. */
    public function transferLots(
        int $sourceWarehouseId,
        int $targetWarehouseId,
        int $variantId,
        int $outMovementId,
        int $inMovementId,
        string $quantity,
        ?string $note = null,
    ): void {
        $consumed = $this->consumeFifo($sourceWarehouseId, $variantId, $outMovementId, $quantity);
        foreach ($consumed as $chunk) {
            $this->recordReceipt(
                $targetWarehouseId,
                $variantId,
                $inMovementId,
                $chunk['quantity'],
                $chunk['unit_cost'],
                $note,
                $chunk['received_at'],
            );
        }
    }

    /**
     * Returns FIFO valuation per variant for a warehouse: invested_total, lots_count, priced_qty, total_qty.
     *
     * @param  array<int, int>  $variantIds
     * @return array<int, array{invested_total: string, lots_count: int, total_qty: string, unpriced_qty: string}>
     */
    public function warehouseVariantValuation(int $warehouseId, array $variantIds = []): array
    {
        if (! $this->available()) {
            return [];
        }

        $query = DB::table('inventory_cost_lots')
            ->where('warehouse_id', $warehouseId)
            ->where('remaining_quantity', '>', 0);

        if (! empty($variantIds)) {
            $query->whereIn('variant_id', $variantIds);
        }

        $result = [];
        foreach ($query->select('variant_id', 'remaining_quantity', 'unit_cost')->cursor() as $lot) {
            $vid = (int) $lot->variant_id;
            if (! isset($result[$vid])) {
                $result[$vid] = ['invested_total' => '0.0000', 'lots_count' => 0, 'total_qty' => '0.0000', 'unpriced_qty' => '0.0000'];
            }
            $rem = bcadd((string) $lot->remaining_quantity, '0', 4);
            $result[$vid]['lots_count']++;
            $result[$vid]['total_qty'] = bcadd($result[$vid]['total_qty'], $rem, 4);
            if ($lot->unit_cost !== null) {
                $result[$vid]['invested_total'] = bcadd(
                    $result[$vid]['invested_total'],
                    bcmul($rem, bcadd((string) $lot->unit_cost, '0', 4), 4),
                    4,
                );
            } else {
                $result[$vid]['unpriced_qty'] = bcadd($result[$vid]['unpriced_qty'], $rem, 4);
            }
        }

        return $result;
    }
}
