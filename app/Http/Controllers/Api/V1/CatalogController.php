<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ClothingVariants;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\VariantRequest;
use App\Http\Resources\CatalogProductResource;
use App\Http\Resources\CatalogVariantResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Rules\DecimalAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogController extends Controller
{
    private function products()
    {
        return Product::with(['category', 'brand', 'images.asset.renditions', 'images.variant.attributeValues.attribute'])->withCount('variants')->withCount(['variants as active_variants_count' => fn ($q) => $q->where('is_active', true)])
            ->withMin(['variants as min_sale_price' => fn ($q) => $q->where('is_active', true)], 'sale_price')
            ->withMax(['variants as max_sale_price' => fn ($q) => $q->where('is_active', true)], 'sale_price')
            ->addSelect(['primary_sku' => ProductVariant::select('sku')->whereColumn('product_id', 'products.id')->where('is_active', true)->orderBy('id')->limit(1)]);
    }

    private function authorizeRead(Product $product, Request $request): void
    {
        abort_if($product->status !== 'active' && ! $request->user()->permits('catalog.manage'), 404);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'brand_id' => ['nullable', 'integer', 'exists:brands,id'], 'status' => ['nullable', Rule::in(['active', 'archived', 'all'])], 'product_type' => ['nullable', Rule::in(['physical', 'service'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,60']]);
        $status = $data['status'] ?? 'active';
        abort_if($status !== 'active' && ! $request->user()->permits('catalog.manage'), 403);
        $query = $this->products()->when($status !== 'all', fn ($q) => $q->where('status', $status));
        foreach (['category_id', 'brand_id', 'product_type'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        if (! empty($data['q'])) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q']));
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$search.'%'])
                ->orWhereHas('variants', fn ($v) => $v->where(fn ($codes) => $codes->whereRaw("sku LIKE ? ESCAPE '!'", ['%'.$search.'%'])->orWhereRaw("barcode LIKE ? ESCAPE '!'", ['%'.$search.'%']))));
        }

        return CatalogProductResource::collection($query->orderByDesc('id')->paginate($data['per_page'] ?? 24)->withQueryString());
    }

    public function show(Request $request)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $product = $this->products()->findOrFail($request->route('product'));
        $this->authorizeRead($product, $request);
        $variants = $product->variants()->with('attributeValues.attribute')->when(! $request->user()->permits('catalog.manage'), fn ($q) => $q->where('is_active', true))->orderBy('id')->paginate(24);

        return response()->json(['data' => ['product' => (new CatalogProductResource($product))->resolve($request), 'variants' => ['data' => CatalogVariantResource::collection($variants->items())->resolve($request), 'current_page' => $variants->currentPage(), 'last_page' => $variants->lastPage(), 'total' => $variants->total()]]]);
    }

    public function create(Request $request, ProductService $service, IdempotentAction $once, InventoryService $inventory, StoreAuthorizationService $stores, ClothingVariants $clothing)
    {
        $key = $request->validate(['request_key' => ['sometimes', 'required', 'uuid']])['request_key'] ?? null;
        $categoryName = $request->validate(['category_name' => ['sometimes', 'nullable', 'string', 'max:120']])['category_name'] ?? null;
        $clothingRows = $request->validate(['clothing_variants' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:100'], 'clothing_variants.*.color' => ['nullable', 'string', 'max:120'], 'clothing_variants.*.size' => ['nullable', 'string', 'max:120'], 'clothing_variants.*.sku' => ['required', 'string', 'max:90'], 'clothing_variants.*.sale_price' => ['required', new DecimalAmount], 'clothing_variants.*.barcode' => ['nullable', 'string', 'max:100'], 'clothing_variants.*.description' => ['nullable', 'string', 'max:2000'], 'clothing_variants.*.cost_price' => $request->user()->permits('catalog.costs') ? ['nullable', new DecimalAmount] : ['missing'], 'clothing_variants.*.is_active' => ['sometimes', 'boolean']])['clothing_variants'] ?? null;
        if ($clothingRows !== null && ($key === null || $request->has('variants'))) {
            throw ValidationException::withMessages(['clothing_variants' => 'La ropa requiere una clave de recuperación y no puede mezclar variantes manuales.']);
        }
        $stock = $request->validate(['initial_stock' => ['sometimes', 'required', 'array'], 'initial_stock.store_id' => ['required_with:initial_stock', 'integer', 'min:1'], 'initial_stock.warehouse_id' => ['required_with:initial_stock', 'integer', 'min:1'], 'initial_stock.items' => ['required_with:initial_stock', 'array', 'min:1', 'max:100'], 'initial_stock.items.*.sku' => ['required', 'string', 'max:90'], 'initial_stock.items.*.quantity' => ['required', new DecimalAmount], 'initial_stock.items.*.request_key' => ['required', 'uuid', 'distinct']])['initial_stock'] ?? null;
        if ($stock !== null && $key === null) {
            throw ValidationException::withMessages(['request_key' => 'La existencia inicial requiere una clave de recuperación.']);
        }
        $create = function () use ($request, $service, $inventory, $stores, $stock, $clothing, $clothingRows, $categoryName) {
            // Validate inside the idempotent transaction so a completed replay is not rejected by SKU uniqueness.
            $form = ProductRequest::createFrom($request);
            $form->setContainer(app())->setRedirector(app('redirect'));
            if (empty($request->input('category_id')) && is_string($categoryName) && trim($categoryName) !== '') {
                $cleanCat = trim($categoryName);
                $existingCat = Category::orderBy('id')->lockForUpdate()->get()->first(fn ($c) => mb_strtolower(trim($c->name)) === mb_strtolower($cleanCat));
                if (! $existingCat) {
                    $baseSlug = Str::slug($cleanCat) ?: 'categoria';
                    $slug = $baseSlug;
                    $suffix = 1;
                    while (Category::where('slug', $slug)->exists()) {
                        $slug = $baseSlug.'-'.(++$suffix);
                    }
                    $existingCat = Category::create(['name' => $cleanCat, 'slug' => $slug, 'is_active' => true]);
                } elseif (! $existingCat->is_active) {
                    $existingCat->update(['is_active' => true]);
                }
                $form->merge(['category_id' => $existingCat->id]);
            }
            if ($clothingRows !== null) {
                $form->merge(['variants' => $clothing->resolve($clothingRows, $request->user())]);
            }
            $form->validateResolved();

            $product = $service->create($form->validated(), $request->user());
            if ($stock !== null) {
                $context = new StoreContext;
                $context->useStore($stores->authorize($request->user(), (int) $stock['store_id']));
                $variants = $product->variants()->get()->keyBy('sku');
                $seen = [];
                foreach ($stock['items'] as $item) {
                    $sku = mb_strtoupper(trim($item['sku']));
                    if (isset($seen[$sku]) || ! $variants->has($sku) || bccomp($item['quantity'], '0', 4) <= 0) {
                        throw ValidationException::withMessages(['initial_stock' => 'Cada existencia debe corresponder a una variante nueva, única y con cantidad positiva.']);
                    }
                    $seen[$sku] = true;
                    $inventory->move($context, ['warehouse_id' => (int) $stock['warehouse_id'], 'variant_id' => $variants[$sku]->id, 'operation' => 'receipt', 'quantity' => $item['quantity'], 'version' => 0, 'note' => 'Existencia inicial del producto', 'request_key' => $item['request_key']], $request->user());
                }
            }

            return $product;
        };
        if ($key === null) {
            $product = $create();

            return response()->json(['data' => (new CatalogProductResource($this->products()->findOrFail($product->id)))->resolve($request)], 201);
        }
        $result = $once->run('catalog.product.create', $key, [$request->user()->id], $request->except('request_key'),
            function () use ($request, $stock) {
                $actor = User::whereKey($request->user()->id)->sharedLock()->firstOrFail();
                abort_unless($actor->permits('catalog.manage') && ($stock === null || $actor->permits('inventory.manage')), 403);
                if ($stock !== null) {
                    abort_unless(DB::table('store_user')->where('user_id', $actor->id)->where('store_id', $stock['store_id'])->sharedLock()->first(), 404);
                }
            },
            fn () => ['product_id' => $create()->id]);

        return response()->json(['data' => (new CatalogProductResource($this->products()->findOrFail($result['product_id'])))->resolve($request), 'meta' => ['replay' => $result['replay']]], $result['replay'] ? 200 : 201);
    }

    public function update(ProductRequest $request, ProductService $service)
    {
        $product = $service->update((int) $request->route('product'), $request->validated(), $request->user());

        return response()->json(['data' => (new CatalogProductResource($this->products()->findOrFail($product->id)))->resolve($request)]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['confirm_with_sales' => ['sometimes', 'boolean']]);
        $product = Product::findOrFail((int) $request->route('product'));
        $variantIds = $product->variants()->pluck('id');
        $orderItemsCount = $variantIds->isEmpty() ? 0 : DB::table('order_items')->whereIn('variant_id', $variantIds)->count();
        $invoiceItemsCount = $variantIds->isEmpty() ? 0 : DB::table('invoice_items')->whereIn('variant_id', $variantIds)->count();
        $salesRecordsCount = $orderItemsCount + $invoiceItemsCount;

        if ($salesRecordsCount > 0 && empty($data['confirm_with_sales'])) {
            return response()->json([
                'message' => "Este producto tiene {$salesRecordsCount} ".($salesRecordsCount === 1 ? 'registro' : 'registros').' de venta o pedidos asociados. Confirma nuevamente si deseas eliminarlo del catálogo.',
                'data' => [
                    'requires_double_confirmation' => true,
                    'sales_records_count' => $salesRecordsCount,
                    'order_items_count' => $orderItemsCount,
                    'invoice_items_count' => $invoiceItemsCount,
                ],
            ], 409);
        }

        DB::transaction(function () use ($product) {
            $product->variants()->update(['is_active' => false]);
            $product->status = 'archived';
            $product->save();
            $product->delete();
        });

        return response()->json([
            'data' => [
                'id' => $product->id,
                'deleted' => true,
                'sales_records_count' => $salesRecordsCount,
            ],
        ]);
    }

    public function addVariant(VariantRequest $request, ProductService $service)
    {
        $result = $service->addVariant((int) $request->route('product'), $request->validated(), $request->user());

        return response()->json(['data' => (new CatalogVariantResource($result['variant']))->resolve($request), 'meta' => ['product_version' => $result['product_version']]], 201);
    }

    public function updateVariant(VariantRequest $request, ProductService $service)
    {
        $result = $service->updateVariant((int) $request->route('product'), (int) $request->route('variant'), $request->validated(), $request->user());

        return response()->json(['data' => (new CatalogVariantResource($result['variant']))->resolve($request), 'meta' => ['product_version' => $result['product_version']]]);
    }
}
