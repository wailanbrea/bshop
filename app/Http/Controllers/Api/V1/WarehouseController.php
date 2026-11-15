<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\AuditTrailService;
use App\Domain\Business\StoreContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\WarehouseRequest;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class WarehouseController extends Controller
{
    public function index(StoreContext $context)
    {
        Gate::authorize('warehouses.view');

        return response()->json(['data' => Warehouse::where('store_id', $context->store()->id)->orderBy('name')->get()]);
    }

    public function create(WarehouseRequest $request, StoreContext $context, AuditTrailService $audit)
    {
        return $this->save($request, $context, $audit);
    }

    public function update(WarehouseRequest $request, StoreContext $context, AuditTrailService $audit)
    {
        return $this->save($request, $context, $audit, (int) $request->route('warehouse'));
    }

    private function save(WarehouseRequest $request, StoreContext $context, AuditTrailService $audit, ?int $id = null)
    {
        Gate::authorize('warehouses.manage');
        $warehouse = DB::transaction(function () use ($request, $context, $audit, $id) {
            // Serialize default selection on the parent, including an empty warehouse list.
            $store = $context->store()->newQuery()->whereKey($context->store()->id)->lockForUpdate()->firstOrFail();
            $warehouse = $id ? Warehouse::where('store_id', $store->id)->findOrFail($id) : new Warehouse(['store_id' => $store->id]);
            $before = $id ? $warehouse->attributesToArray() : null;
            $warehouse->fill($request->validated());
            if ($warehouse->is_default && ! $warehouse->is_active) {
                throw ValidationException::withMessages(['is_active' => 'El almacén principal debe permanecer activo.']);
            }
            if ($warehouse->is_default) {
                $others = Warehouse::where('store_id', $store->id)->where('is_default', true)
                    ->when($id, fn ($q) => $q->where('id', '!=', $id))->get();
                foreach ($others as $other) {
                    $old = $other->attributesToArray();
                    $other->update(['is_default' => false]);
                    $audit->record('warehouse.updated', $other, $old);
                }
            }
            $warehouse->save();
            $audit->record($id ? 'warehouse.updated' : 'warehouse.created', $warehouse, $before);

            return $warehouse->refresh();
        });

        return response()->json(['data' => $warehouse], $id ? 200 : 201);
    }
}
