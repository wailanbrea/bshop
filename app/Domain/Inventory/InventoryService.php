<?php

namespace App\Domain\Inventory;

use App\Domain\Business\StoreContext;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Rules\DecimalAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class InventoryService
{
    public function __construct(private StockLedger $ledger, private FifoCostService $fifo) {}

    public function move(StoreContext $context, array $input, User $actor): array
    {
        $freshActor = $actor->fresh();
        abort_unless($freshActor?->permits('inventory.manage'), 403);
        $canSetCost = (bool) $freshActor->permits('catalog.costs');
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'], 'warehouse_id' => ['required', 'integer', 'min:1'], 'variant_id' => ['required', 'integer', 'min:1'],
            'operation' => ['required', Rule::in(['receipt', 'issue', 'count', 'transfer'])], 'quantity' => ['required', new DecimalAmount],
            'version' => ['required', 'integer', 'min:0'], 'note' => ['required', 'string', 'max:255'],
            'destination_warehouse_id' => ['required_if:operation,transfer', 'prohibited_unless:operation,transfer', 'integer', 'min:1', 'different:warehouse_id'],
            'unit_cost' => $canSetCost ? ['nullable', new DecimalAmount] : ['missing'],
        ])->validate();
        $data['quantity'] = DecimalAmount::normalize($data['quantity']);
        $unitCost = isset($data['unit_cost']) && $data['unit_cost'] !== '' ? DecimalAmount::normalize($data['unit_cost']) : null;
        if ($data['operation'] !== 'count' && bccomp($data['quantity'], '0', 4) <= 0) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser mayor que cero.']);
        }
        foreach (['warehouse_id', 'variant_id', 'version', 'destination_warehouse_id'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = (int) $data[$field];
            }
        }
        $data['note'] = trim($data['note']);
        if ($data['note'] === '') {
            throw ValidationException::withMessages(['note' => 'Indica el motivo del movimiento.']);
        }
        $storeId = $context->store()->id;
        $hash = hash('sha256', json_encode([$actor->id, $storeId, $data['warehouse_id'], $data['variant_id'], $data['operation'], $data['quantity'], $data['version'], $data['note'], $data['destination_warehouse_id'] ?? null, $unitCost], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $storeId, $hash, $unitCost) {
            // Claim and response commit together: a crash cannot leave a committed half-operation.
            DB::table('idempotency_requests')->insertOrIgnore(['operation' => 'inventory.manual', 'request_key' => $data['request_key'], 'request_hash' => $hash, 'status' => 'processing', 'created_at' => now()]);
            $request = DB::table('idempotency_requests')->where('operation', 'inventory.manual')->where('request_key', $data['request_key'])->lockForUpdate()->first();
            abort_unless(hash_equals($request->request_hash, $hash), 409, 'Esta clave ya corresponde a otra operación. Conserva la carga original al reintentar.');
            $currentActor = User::whereKey($actor->id)->sharedLock()->firstOrFail();
            abort_unless($currentActor->permits('inventory.manage'), 403);
            $source = Warehouse::findOrFail($data['warehouse_id']);
            abort_unless($source->store_id === $storeId, 404);
            $warehouseIds = [$source->id];
            if ($data['operation'] === 'transfer') {
                $warehouseIds[] = $data['destination_warehouse_id'];
            }
            sort($warehouseIds);
            $destinations = Warehouse::whereIn('id', $warehouseIds)->get();
            abort_unless($destinations->count() === count($warehouseIds), 404);
            $storeIds = $destinations->pluck('store_id')->unique()->sort()->values()->all();
            // Authorization is rechecked on replay; a stored result never bypasses revoked branch access.
            foreach ($storeIds as $id) {
                abort_unless(DB::table('store_user')->where('user_id', $actor->id)->where('store_id', $id)->sharedLock()->first(), 404);
            }
            if ($request->status === 'completed') {
                return [...json_decode($request->response_data, true, flags: JSON_THROW_ON_ERROR), 'replay' => true];
            }

            $reference = ProductVariant::withTrashed()->findOrFail($data['variant_id']);
            $product = Product::whereKey($reference->product_id)->lockForUpdate()->firstOrFail();
            $variant = ProductVariant::where('product_id', $product->id)->lockForUpdate()->findOrFail($reference->id);
            abort_if($product->status !== 'active' || ! $variant->is_active, 409, 'Reactiva el producto y la variante antes de mover existencias.');
            abort_if($product->product_type !== 'physical' || ! $product->track_stock, 422, 'Este producto o servicio no controla existencias.');
            $stores = Store::whereIn('id', $storeIds)->orderBy('id')->lockForUpdate()->get();
            $warehouses = Warehouse::whereIn('id', $warehouseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_if($stores->contains(fn ($s) => ! $s->is_active) || $warehouses->contains(fn ($w) => ! $w->is_active), 409, 'La sucursal o el almacén están inactivos.');
            foreach ($warehouses as $warehouse) {
                // The variant lock serializes first-row creation as well as later stock updates.
                DB::table('stock_balances')->insertOrIgnore(['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'on_hand' => '0.0000', 'reserved' => '0.0000', 'version' => 0]);
            }
            $balances = DB::table('stock_balances')->whereIn('warehouse_id', $warehouseIds)->where('variant_id', $variant->id)->orderBy('warehouse_id')->lockForUpdate()->get()->keyBy('warehouse_id');
            $balance = $balances[$source->id];
            abort_if((int) $balance->version !== $data['version'], 409, 'Las existencias cambiaron. Actualiza el saldo antes de registrar el movimiento.');
            $old = bcadd((string) $balance->on_hand, '0', 4);
            $reserved = bcadd((string) $balance->reserved, '0', 4);
            $new = match ($data['operation']) {
                'receipt' => bcadd($old, $data['quantity'], 4), 'count' => $data['quantity'], default => bcsub($old, $data['quantity'], 4)
            };
            abort_if(bccomp($new, $reserved, 4) < 0, 409, 'No hay existencias disponibles suficientes; no se puede consumir stock reservado.');
            $delta = bcsub($new, $old, 4);
            if (bccomp($delta, '0', 4) === 0) {
                throw ValidationException::withMessages(['quantity' => 'El conteo coincide con el saldo; no se generó movimiento.']);
            }
            $isTransfer = $data['operation'] === 'transfer';
            $movements = [$this->ledger->record($warehouses[$source->id], $variant->id, $balance, $delta, '0.0000', $isTransfer ? 'transfer_out' : $data['operation'], $data['request_key'].':out', $data['note'], $actor, null, null, $unitCost, null, $isTransfer)];
            if ($isTransfer) {
                $target = $warehouses[$data['destination_warehouse_id']];
                $targetBalance = $balances[$target->id];
                $movements[] = $this->ledger->record($target, $variant->id, $targetBalance, $data['quantity'], '0.0000', 'transfer_in', $data['request_key'].':in', $data['note'], $actor, null, null, null, null, true);
                if ($this->fifo->available()) {
                    $this->fifo->transferLots($source->id, $target->id, $variant->id, $movements[0]['id'], $movements[1]['id'], $data['quantity'], $data['note']);
                }
            } elseif ($data['operation'] === 'receipt' && $unitCost !== null) {
                // Keep variant's reference purchase cost aligned with the latest received lot (never alters past lots or past profits).
                $variant->cost_price = $unitCost;
                $variant->save();
            }
            $product->increment('lock_version');
            $result = ['request_key' => $data['request_key'], 'source_store_id' => $storeId, 'performed_by_user_id' => $actor->id, 'operation' => $data['operation'], 'movements' => $movements];
            DB::table('idempotency_requests')->where('id', $request->id)->update(['status' => 'completed', 'resource_type' => 'inventory_movement', 'resource_id' => $movements[0]['id'], 'response_data' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now()]);

            return [...$result, 'replay' => false];
        }, 3);
    }
}
