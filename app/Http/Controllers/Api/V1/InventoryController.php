<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Inventory\InventoryService;
use App\Domain\Media\VariantPhoto;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function reconciliation(Request $request, StoreContext $context, InventoryReconciliation $reconciliation)
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000000'], 'only_issues' => ['nullable', 'boolean']]);
        $warehouse = $this->warehouse($request, $context);
        $result = $reconciliation->inspect($context->store()->id, $warehouse->id);
        $rows = array_values(array_filter($result['data'], fn ($row) => ! ($data['only_issues'] ?? true) || count($row['differences']) > 0));
        $page = (int) ($data['page'] ?? 1);

        return response()->json(['data' => array_slice($rows, ($page - 1) * 25, 25), 'summary' => $result['summary'], 'generated_at' => $result['generated_at'],
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / 25)), 'total' => count($rows)]]);
    }

    private function warehouse(Request $request, StoreContext $context): Warehouse
    {
        return Warehouse::where('store_id', $context->store()->id)->findOrFail($request->route('warehouse'));
    }

    public function index(Request $request, StoreContext $context)
    {
        $warehouse = $this->warehouse($request, $context);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'filter' => ['nullable', 'in:all,available,empty']]);
        $query = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()->with('images.asset.renditions'), 'attributeValues.attribute'])
            ->leftJoin('stock_balances as stock', fn ($join) => $join->on('stock.variant_id', '=', 'product_variants.id')->where('stock.warehouse_id', $warehouse->id))
            ->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('status', 'active')->where('product_type', 'physical')->where('track_stock', true))->orWhereNotNull('stock.variant_id'))
            ->select('product_variants.*', 'stock.on_hand', 'stock.reserved', 'stock.version as stock_version');
        if (! empty($data['q'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("product_variants.sku LIKE ? ESCAPE '!'", [$search])->orWhereRaw("product_variants.barcode LIKE ? ESCAPE '!'", [$search])->orWhereHas('product', fn ($p) => $p->withTrashed()->whereRaw("name LIKE ? ESCAPE '!'", [$search]))->orWhereHas('attributeValues', fn ($v) => $v->whereRaw("value LIKE ? ESCAPE '!'", [$search])->orWhereHas('attribute', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$search]))));
        }
        if (($data['filter'] ?? '') === 'available') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) > COALESCE(stock.reserved, 0)');
        }
        if (($data['filter'] ?? '') === 'empty') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) = COALESCE(stock.reserved, 0)');
        }
        $page = $query->orderBy('product_variants.sku')->paginate(24);
        $photoVariants = ProductVariant::with('attributeValues.attribute')->whereIn('id', $page->getCollection()->flatMap(fn ($v) => $v->product->images->pluck('variant_id'))->filter()->unique())->get()->keyBy('id');
        $rows = $page->getCollection()->map(function ($variant) use ($warehouse, $photoVariants) {
            $onHand = bcadd((string) ($variant->on_hand ?? '0'), '0', 4);
            $reserved = bcadd((string) ($variant->reserved ?? '0'), '0', 4);

            return ['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'product_name' => $variant->product->name, 'product_id' => $variant->product_id, 'unit_code' => $variant->product->unit_code, 'image_url' => app(VariantPhoto::class)->url($variant->product, $variant, 'thumb', $photoVariants), 'active' => ! $variant->trashed() && ! $variant->product->trashed() && $variant->is_active && $variant->product->status === 'active', 'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all(), 'on_hand' => $onHand, 'reserved' => $reserved, 'available' => bcsub($onHand, $reserved, 4), 'version' => (int) ($variant->stock_version ?? 0)];
        });

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function destinations(Request $request, StoreAuthorizationService $authorization)
    {
        $stores = $authorization->accessible($request->user())->where('is_active', true)->get()->keyBy('id');
        $warehouses = Warehouse::whereIn('store_id', $stores->keys())->where('is_active', true)->orderBy('store_id')->orderBy('name')->get();

        return response()->json(['data' => $warehouses->map(fn ($w) => ['id' => $w->id, 'store_id' => $w->store_id, 'store_name' => $stores[$w->store_id]->name, 'name' => $w->name])]);
    }

    public function kardex(Request $request, StoreContext $context)
    {
        $warehouse = $this->warehouse($request, $context);
        $data = $request->validate(['variant_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        $page = InventoryMovement::where('inventory_movements.store_id', $context->store()->id)->where('inventory_movements.warehouse_id', $warehouse->id)
            ->when(isset($data['variant_id']), fn ($q) => $q->where('inventory_movements.variant_id', $data['variant_id']))
            ->join('product_variants', 'product_variants.id', '=', 'inventory_movements.variant_id')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.performed_by_user_id')
            ->select('inventory_movements.*', 'product_variants.sku', 'products.name as product_name', 'users.name as actor_name')->orderByDesc('inventory_movements.id')->paginate(25);

        return response()->json(['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function move(Request $request, StoreContext $context, InventoryService $service)
    {
        $result = $service->move($context, $request->all(), $request->user());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201);
    }

    public function status(Request $request, StoreContext $context)
    {
        $record = DB::table('idempotency_requests')->where('operation', 'inventory.manual')->where('request_key', $request->route('key'))->where('status', 'completed')->firstOrFail();
        $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless($result['source_store_id'] === $context->store()->id && $result['performed_by_user_id'] === $request->user()->id, 404);
        foreach ($result['movements'] as $movement) {
            abort_unless(DB::table('store_user')->where('user_id', $request->user()->id)->where('store_id', $movement['store_id'])->exists(), 404);
        }

        return response()->json(['data' => [...$result, 'replay' => true]]);
    }
}
