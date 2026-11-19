<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\StoreContext;
use App\Domain\Inventory\FifoCostService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Inventory\InventoryService;
use App\Domain\Media\VariantPhoto;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
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
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1'], 'filter' => ['nullable', 'in:all,available,low,empty']]);
        $query = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed()->with('images.asset.renditions'), 'attributeValues.attribute'])
            ->leftJoin('stock_balances as stock', fn ($join) => $join->on('stock.variant_id', '=', 'product_variants.id')->where('stock.warehouse_id', $warehouse->id))
            ->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('store_id', $context->store()->id)->where('status', 'active')->where('product_type', 'physical')->where('track_stock', true))->orWhereNotNull('stock.variant_id'))
            ->select('product_variants.*', 'stock.on_hand', 'stock.reserved', 'stock.version as stock_version');
        if (! empty($data['q'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("product_variants.sku LIKE ? ESCAPE '!'", [$search])->orWhereRaw("product_variants.barcode LIKE ? ESCAPE '!'", [$search])->orWhereHas('product', fn ($p) => $p->withTrashed()->whereRaw("name LIKE ? ESCAPE '!'", [$search]))->orWhereHas('attributeValues', fn ($v) => $v->whereRaw("value LIKE ? ESCAPE '!'", [$search])->orWhereHas('attribute', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$search]))));
        }
        if (($data['filter'] ?? '') === 'available') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) > COALESCE(stock.reserved, 0)');
        }
        if (($data['filter'] ?? '') === 'low') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) - COALESCE(stock.reserved, 0) <= 2');
        }
        if (($data['filter'] ?? '') === 'empty') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) = COALESCE(stock.reserved, 0)');
        }
        $canSeeCosts = (bool) $request->user()?->permits('catalog.costs');
        $fifoValuation = app(FifoCostService::class)->warehouseVariantValuation($warehouse->id);
        $currency = BusinessProfile::current()->default_currency;
        $invested = '0.0000';
        $saleValue = '0.0000';
        $unitsOnHand = '0.0000';
        $unitsAvailable = '0.0000';
        $lowStockCount = 0;
        $outOfStockCount = 0;
        foreach ((clone $query)->cursor() as $item) {
            $oh = bcadd((string) ($item->on_hand ?? '0'), '0', 4);
            $res = bcadd((string) ($item->reserved ?? '0'), '0', 4);
            $avail = bcsub($oh, $res, 4);
            if (bccomp($avail, '0', 4) <= 0) {
                $outOfStockCount++;
                $avail = '0.0000';
            } elseif (bccomp($avail, '2', 4) <= 0) {
                $lowStockCount++;
            }
            if (bccomp($oh, '0', 4) <= 0) {
                continue;
            }
            $unitsOnHand = bcadd($unitsOnHand, $oh, 4);
            $unitsAvailable = bcadd($unitsAvailable, $avail, 4);
            $lotVal = $fifoValuation[(int) $item->id] ?? null;
            if ($lotVal !== null) {
                $invested = bcadd($invested, $lotVal['invested_total'], 4);
            } elseif ($item->cost_price !== null) {
                $invested = bcadd($invested, bcmul($oh, bcadd((string) $item->cost_price, '0', 4), 4), 4);
            }
            $saleValue = bcadd($saleValue, bcmul($oh, bcadd((string) ($item->sale_price ?? '0'), '0', 4), 4), 4);
        }
        $page = $query->orderBy('product_variants.sku')->paginate(24);
        $photoVariants = ProductVariant::with('attributeValues.attribute')->whereIn('id', $page->getCollection()->flatMap(fn ($v) => $v->product->images->pluck('variant_id'))->filter()->unique())->get()->keyBy('id');
        $rows = $page->getCollection()->map(function ($variant) use ($warehouse, $photoVariants, $canSeeCosts, $fifoValuation) {
            $onHand = bcadd((string) ($variant->on_hand ?? '0'), '0', 4);
            $reserved = bcadd((string) ($variant->reserved ?? '0'), '0', 4);
            $costPrice = $canSeeCosts && $variant->cost_price !== null ? bcadd((string) $variant->cost_price, '0', 4) : null;
            $salePrice = bcadd((string) ($variant->sale_price ?? '0'), '0', 4);
            $positiveOnHand = bccomp($onHand, '0', 4) > 0 ? $onHand : '0.0000';
            $lotVal = $fifoValuation[(int) $variant->id] ?? null;
            $investedTotal = null;
            if ($canSeeCosts) {
                if ($lotVal !== null) {
                    $investedTotal = $lotVal['invested_total'];
                } elseif ($costPrice !== null) {
                    $investedTotal = bcmul($positiveOnHand, $costPrice, 4);
                }
            }

            return ['variant_id' => $variant->id, 'warehouse_id' => $warehouse->id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'product_name' => $variant->product->name, 'product_id' => $variant->product_id, 'unit_code' => $variant->product->unit_code, 'image_url' => app(VariantPhoto::class)->url($variant->product, $variant, 'thumb', $photoVariants), 'active' => ! $variant->trashed() && ! $variant->product->trashed() && $variant->is_active && $variant->product->status === 'active', 'attributes' => $variant->attributeValues->map(fn ($v) => ['name' => $v->attribute->name, 'value' => $v->value])->all(), 'on_hand' => $onHand, 'reserved' => $reserved, 'available' => bcsub($onHand, $reserved, 4), 'unit_cost' => $costPrice, 'sale_price' => $salePrice, 'invested_total' => $investedTotal, 'sale_total' => bcmul($positiveOnHand, $salePrice, 4), 'lots_count' => $canSeeCosts ? ($lotVal['lots_count'] ?? 0) : 0, 'version' => (int) ($variant->stock_version ?? 0), 'variant_version' => (int) ($variant->lock_version ?? 0)];
        });

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'valuation' => ['currency' => $currency, 'invested_cost' => $canSeeCosts ? $invested : null, 'sale_value' => $saleValue, 'estimated_profit' => $canSeeCosts ? bcsub($saleValue, $invested, 4) : null, 'units_on_hand' => $unitsOnHand, 'units_available' => $unitsAvailable, 'low_stock_count' => $lowStockCount, 'out_of_stock_count' => $outOfStockCount]]]);
    }

    public function lots(Request $request, StoreContext $context, FifoCostService $fifo)
    {
        abort_unless($request->user()?->permits('catalog.costs'), 403);
        $warehouse = $this->warehouse($request, $context);
        $variantId = (int) $request->route('variant');
        if (! $fifo->available()) {
            return response()->json(['data' => []]);
        }

        $lots = DB::table('inventory_cost_lots')
            ->where('warehouse_id', $warehouse->id)
            ->where('variant_id', $variantId)
            ->where('remaining_quantity', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->map(function ($lot) {
                $rem = bcadd((string) $lot->remaining_quantity, '0', 4);
                $init = bcadd((string) $lot->initial_quantity, '0', 4);
                $cost = $lot->unit_cost !== null ? bcadd((string) $lot->unit_cost, '0', 4) : null;

                return [
                    'id' => (int) $lot->id,
                    'unit_cost' => $cost,
                    'initial_quantity' => $init,
                    'remaining_quantity' => $rem,
                    'invested_total' => $cost !== null ? bcmul($rem, $cost, 4) : null,
                    'received_at' => $lot->received_at,
                    'note' => $lot->note,
                ];
            })
            ->values();

        return response()->json(['data' => $lots]);
    }

    public function export(Request $request, StoreContext $context)
    {
        $warehouse = $this->warehouse($request, $context);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'filter' => ['nullable', 'in:all,available,low,empty']]);
        $query = ProductVariant::withTrashed()->with(['product' => fn ($q) => $q->withTrashed(), 'attributeValues.attribute'])
            ->leftJoin('stock_balances as stock', fn ($join) => $join->on('stock.variant_id', '=', 'product_variants.id')->where('stock.warehouse_id', $warehouse->id))
            ->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('store_id', $context->store()->id)->where('status', 'active')->where('product_type', 'physical')->where('track_stock', true))->orWhereNotNull('stock.variant_id'))
            ->select('product_variants.*', 'stock.on_hand', 'stock.reserved');
        if (! empty($data['q'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(fn ($q) => $q->whereRaw("product_variants.sku LIKE ? ESCAPE '!'", [$search])->orWhereRaw("product_variants.barcode LIKE ? ESCAPE '!'", [$search])->orWhereHas('product', fn ($p) => $p->withTrashed()->whereRaw("name LIKE ? ESCAPE '!'", [$search]))->orWhereHas('attributeValues', fn ($v) => $v->whereRaw("value LIKE ? ESCAPE '!'", [$search])->orWhereHas('attribute', fn ($a) => $a->whereRaw("name LIKE ? ESCAPE '!'", [$search]))));
        }
        if (($data['filter'] ?? '') === 'available') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) > COALESCE(stock.reserved, 0)');
        }
        if (($data['filter'] ?? '') === 'low') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) - COALESCE(stock.reserved, 0) <= 2');
        }
        if (($data['filter'] ?? '') === 'empty') {
            $query->whereRaw('COALESCE(stock.on_hand, 0) = COALESCE(stock.reserved, 0)');
        }
        $canSeeCosts = (bool) $request->user()?->permits('catalog.costs');
        $items = $query->orderBy('product_variants.sku')->get();
        $safe = static fn (?string $val): string => ($val !== null && $val !== '' && in_array($val[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'".$val : (string) $val;

        return response()->streamDownload(function () use ($items, $canSeeCosts, $safe) {
            $out = fopen('php://output', 'w');
            $headers = ['SKU', 'Codigo de barras', 'Producto', 'Atributos', 'Fisico', 'Reservado', 'Disponible'];
            if ($canSeeCosts) {
                $headers[] = 'Precio de compra';
            }
            $headers[] = 'Precio de venta';
            if ($canSeeCosts) {
                $headers[] = 'Capital invertido';
            }
            $headers[] = 'Valor en venta';
            $headers[] = 'Estado';
            fputcsv($out, $headers);
            foreach ($items as $variant) {
                $onHand = bcadd((string) ($variant->on_hand ?? '0'), '0', 4);
                $reserved = bcadd((string) ($variant->reserved ?? '0'), '0', 4);
                $available = bcsub($onHand, $reserved, 4);
                $positiveOnHand = bccomp($onHand, '0', 4) > 0 ? $onHand : '0.0000';
                $costPrice = $canSeeCosts && $variant->cost_price !== null ? bcadd((string) $variant->cost_price, '0', 4) : '';
                $salePrice = bcadd((string) ($variant->sale_price ?? '0'), '0', 4);
                $investedTotal = ($canSeeCosts && $variant->cost_price !== null) ? bcmul($positiveOnHand, $costPrice, 4) : '';
                $saleTotal = bcmul($positiveOnHand, $salePrice, 4);
                $attrs = $variant->attributeValues->map(fn ($v) => $v->attribute->name.': '.$v->value)->implode(' | ');
                $active = ! $variant->trashed() && ! $variant->product->trashed() && $variant->is_active && $variant->product->status === 'active';
                $row = [
                    $safe($variant->sku),
                    $safe($variant->barcode),
                    $safe($variant->product->name),
                    $safe($attrs),
                    $onHand,
                    $reserved,
                    $available,
                ];
                if ($canSeeCosts) {
                    $row[] = $costPrice;
                }
                $row[] = $salePrice;
                if ($canSeeCosts) {
                    $row[] = $investedTotal;
                }
                $row[] = $saleTotal;
                $row[] = $active ? 'Activo' : 'Inactivo';
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'inventario-valorado.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
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
