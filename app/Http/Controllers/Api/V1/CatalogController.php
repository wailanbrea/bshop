<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\ProductService;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Http\Requests\VariantRequest;
use App\Http\Resources\CatalogProductResource;
use App\Http\Resources\CatalogVariantResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    private function products()
    {
        return Product::with(['category', 'brand', 'images.asset.renditions'])->withCount('variants')->withCount(['variants as active_variants_count' => fn ($q) => $q->where('is_active', true)])
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

    public function create(ProductRequest $request, ProductService $service)
    {
        $product = $service->create($request->validated(), $request->user());

        return response()->json(['data' => (new CatalogProductResource($this->products()->findOrFail($product->id)))->resolve($request)], 201);
    }

    public function update(ProductRequest $request, ProductService $service)
    {
        $product = $service->update((int) $request->route('product'), $request->validated(), $request->user());

        return response()->json(['data' => (new CatalogProductResource($this->products()->findOrFail($product->id)))->resolve($request)]);
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
