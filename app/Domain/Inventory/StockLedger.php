<?php

namespace App\Domain\Inventory;

use App\Domain\Business\AuditTrailService;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Caller owns product/variant/location/balance locks in a transaction. No standalone writes. */
final class StockLedger
{
    public function __construct(private AuditTrailService $audit, private FifoCostService $fifo) {}

    public function record(Warehouse $warehouse, int $variantId, object $balance, string $onHandDelta, string $reservedDelta, string $type, string $eventKey, string $note, ?User $actor, ?int $reservationId = null, ?int $returnItemId = null, ?string $unitCost = null, ?int $orderItemId = null, bool $skipFifo = false): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Stock changes require an active transaction and ordered locks.');
        }
        $new = bcadd((string) $balance->on_hand, $onHandDelta, 4);
        $reserved = bcadd((string) $balance->reserved, $reservedDelta, 4);
        abort_if(bccomp($new, '0', 4) < 0 || bccomp($reserved, '0', 4) < 0 || bccomp($reserved, $new, 4) > 0, 409, 'El movimiento excede las existencias disponibles o reservadas.');
        if (bccomp($new, '999999999999999.9999', 4) > 0) {
            throw ValidationException::withMessages(['quantity' => 'El saldo supera la capacidad decimal permitida.']);
        }
        $version = (int) $balance->version + 1;
        DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variantId)->update(['on_hand' => $new, 'reserved' => $reserved, 'version' => $version, 'updated_at' => now()]);
        $movement = InventoryMovement::create(['warehouse_id' => $warehouse->id, 'store_id' => $warehouse->store_id, 'variant_id' => $variantId, 'reservation_id' => $reservationId, 'return_item_id' => $returnItemId, 'movement_type' => $type, 'on_hand_delta' => $onHandDelta, 'reserved_delta' => $reservedDelta, 'event_key' => $eventKey, 'note' => $note, 'performed_by_user_id' => $actor?->id, 'occurred_at' => now(), 'on_hand_after' => $new, 'reserved_after' => $reserved, 'balance_version' => $version]);
        if (! $skipFifo && $this->fifo->available() && bccomp($onHandDelta, '0', 4) !== 0) {
            if (bccomp($onHandDelta, '0', 4) > 0) {
                if ($type === 'customer_return') {
                    $this->fifo->restoreFromReturn($warehouse->id, $variantId, $movement->id, $onHandDelta, $orderItemId, $note);
                } else {
                    $resolvedCost = $unitCost;
                    if ($resolvedCost === null) {
                        $fallback = DB::table('product_variants')->where('id', $variantId)->value('cost_price');
                        $resolvedCost = $fallback !== null ? bcadd((string) $fallback, '0', 4) : null;
                    }
                    $this->fifo->recordReceipt($warehouse->id, $variantId, $movement->id, $onHandDelta, $resolvedCost, $note);
                }
            } else {
                $this->fifo->consumeFifo($warehouse->id, $variantId, $movement->id, ltrim($onHandDelta, '-'), $orderItemId);
            }
        }
        $this->audit->record('inventory.'.$type, $movement, after: ['warehouse_id' => $warehouse->id, 'variant_id' => $variantId, 'on_hand_before' => bcadd((string) $balance->on_hand, '0', 4), 'on_hand_after' => $new, 'reserved_before' => bcadd((string) $balance->reserved, '0', 4), 'reserved_after' => $reserved], actor: $actor, system: $actor === null);

        return ['id' => $movement->id, 'store_id' => $warehouse->store_id, 'warehouse_id' => $warehouse->id, 'variant_id' => $variantId, 'movement_type' => $type, 'on_hand_delta' => $onHandDelta, 'reserved_delta' => $reservedDelta, 'on_hand_after' => $new, 'reserved_after' => $reserved, 'balance_version' => $version, 'note' => $note];
    }
}
