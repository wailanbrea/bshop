<?php

namespace App\Domain\Catalog;

use App\Domain\Business\AuditTrailService;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Rules\DecimalAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductService
{
    public function __construct(private VariantCombinationService $combinations, private AuditTrailService $audit) {}

    private function authorize(User $actor): void
    {
        abort_unless($actor->fresh()?->permits('catalog.manage'), 403);
    }

    private function references(array $data, ?Product $current = null): void
    {
        foreach (['category_id' => Category::class, 'brand_id' => Brand::class] as $field => $model) {
            $id = $data[$field] ?? null;
            if ($id && (int) $current?->$field !== (int) $id && ! $model::whereKey($id)->where('is_active', true)->sharedLock()->first()) {
                throw ValidationException::withMessages([$field => 'Selecciona una opción activa.']);
            }
        }
    }

    public function create(array $data, User $actor): Product
    {
        try {
            return DB::transaction(function () use ($data, $actor) {
                $this->authorize($actor);
                $this->references($data);
                if (empty($data['store_id'])) {
                    $defaultStoreId = DB::table('store_user')->where('user_id', $actor->id)->orderBy('store_id')->value('store_id')
                        ?? DB::table('stores')->orderBy('id')->value('id');
                    if ($defaultStoreId !== null) {
                        $data['store_id'] = (int) $defaultStoreId;
                    }
                }
                $product = Product::create($data);
                foreach ($data['variants'] as $variant) {
                    $this->insertVariant($product, $variant, $actor);
                }
                $this->audit->record('catalog.product.created', $product, actor: $actor);

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['variants' => 'El SKU, código de barras o combinación ya existe.']);
        }
    }

    public function update(int $id, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $this->authorize($actor);
            $product = Product::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($product->lock_version !== (int) $data['version'], 409, 'El producto cambió. Vuelve a abrirlo antes de guardar.');
            $this->references($data, $product);
            if ($product->product_type !== $data['product_type'] || $product->track_stock !== (bool) $data['track_stock']) {
                $ids = $product->variants()->withTrashed()->pluck('id');
                $history = DB::table('inventory_movements')->whereIn('variant_id', $ids)->exists()
                    || DB::table('stock_balances')->whereIn('variant_id', $ids)->where(fn ($q) => $q->where('on_hand', '!=', '0')->orWhere('reserved', '!=', '0'))->exists()
                    || DB::table('order_items')->whereIn('variant_id', $ids)->exists();
                abort_if($history, 409, 'No se puede cambiar el tipo o seguimiento de stock de un producto con movimientos o ventas.');
            }
            $before = $product->attributesToArray();
            $product->fill($data);
            $product->lock_version++;
            $product->save();
            $this->audit->record('catalog.product.updated', $product, $before, actor: $actor);

            return $product;
        });
    }

    public function addVariant(int $productId, array $data, User $actor): array
    {
        try {
            return DB::transaction(function () use ($productId, $data, $actor) {
                $this->authorize($actor);
                $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                abort_if($product->lock_version !== (int) $data['version'], 409, 'El producto cambió. Actualiza antes de añadir una variante.');
                abort_if($product->status !== 'active', 409, 'Reactiva el producto antes de añadir variantes.');
                $variant = $this->insertVariant($product, $data, $actor);
                $product->increment('lock_version');

                return ['variant' => $variant, 'product_version' => $product->lock_version];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'El SKU, código de barras o combinación ya existe.']);
        }
    }

    private function insertVariant(Product $product, array $data, User $actor): ProductVariant
    {
        if (array_key_exists('cost_price', $data)) {
            abort_unless($actor->fresh()?->permits('catalog.costs'), 403);
        }
        $combination = $this->combinations->resolve($data['attribute_value_ids'], true);
        if ($product->variants()->withTrashed()->where('combination_key', $combination['key'])->exists()) {
            throw ValidationException::withMessages(['attribute_value_ids' => 'Esta combinación ya existe, incluso si está inactiva.']);
        }
        if ($data['is_active'] ?? true) {
            $this->consistentAttributes($product, array_keys($combination['values']));
        }
        $variant = $product->variants()->create([
            'sku' => $data['sku'], 'barcode' => $data['barcode'] ?? null, 'description' => $data['description'] ?? null, 'combination_key' => $combination['key'],
            'sale_price' => DecimalAmount::normalize($data['sale_price']),
            'cost_price' => isset($data['cost_price']) ? DecimalAmount::normalize($data['cost_price']) : null,
            'is_active' => $data['is_active'] ?? true,
        ]);
        foreach ($combination['values'] as $attribute => $value) {
            DB::table('variant_attribute_values')->insert(['variant_id' => $variant->id, 'attribute_id' => $attribute, 'value_id' => $value]);
        }
        $this->audit->record('catalog.variant.created', $variant, after: $this->variantSnapshot($variant), actor: $actor);

        return $variant->load('attributeValues.attribute');
    }

    private function consistentAttributes(Product $product, array $attributes, ?int $ignore = null): void
    {
        sort($attributes, SORT_NUMERIC);
        foreach ($product->variants()->where('is_active', true)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->with('attributeValues')->get() as $sibling) {
            $other = $sibling->attributeValues->pluck('attribute_id')->sort()->values()->all();
            if ($attributes !== $other) {
                throw ValidationException::withMessages(['attribute_value_ids' => 'Las variantes activas deben usar los mismos atributos. Desactiva la variante general antes de añadir tallas/colores.']);
            }
        }
    }

    public function updateVariant(int $productId, int $id, array $data, User $actor): array
    {
        try {
            return DB::transaction(function () use ($productId, $id, $data, $actor) {
                $this->authorize($actor);
                $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                $variant = $product->variants()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('cost_price', $data)) {
                    abort_unless($actor->fresh()?->permits('catalog.costs'), 403);
                }
                abort_if($variant->lock_version !== (int) $data['version'], 409, 'La variante cambió. Vuelve a abrirla antes de guardar.');
                if (! $data['is_active']) {
                    abort_if(DB::table('stock_reservations')->where('variant_id', $variant->id)->where('status', 'active')->exists(), 409, 'La variante tiene reservas activas.');
                }
                if ($data['is_active']) {
                    $this->consistentAttributes($product, $variant->attributeValues->pluck('attribute_id')->all(), $variant->id);
                }
                $before = $this->variantSnapshot($variant);
                $variant->fill(['sku' => $data['sku'], 'sale_price' => DecimalAmount::normalize($data['sale_price']), 'is_active' => $data['is_active']]);
                if (array_key_exists('barcode', $data)) {
                    $variant->barcode = $data['barcode'];
                }
                if (array_key_exists('description', $data)) {
                    $variant->description = $data['description'];
                }
                if (array_key_exists('cost_price', $data)) {
                    $variant->cost_price = $data['cost_price'] === null ? null : DecimalAmount::normalize($data['cost_price']);
                }
                $variant->lock_version++;
                $variant->save();
                $product->increment('lock_version');
                $this->audit->record('catalog.variant.updated', $variant, $before, $this->variantSnapshot($variant), $actor);

                return ['variant' => $variant->load('attributeValues.attribute'), 'product_version' => $product->lock_version];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'El SKU o código de barras ya pertenece a otra variante.']);
        }
    }

    private function variantSnapshot(ProductVariant $variant): array
    {
        return ['product_id' => $variant->product_id, 'sku' => $variant->sku, 'barcode' => $variant->barcode, 'description' => $variant->description, 'sale_price' => $variant->sale_price, 'cost_price' => $variant->cost_price, 'is_active' => $variant->is_active, 'version' => $variant->lock_version];
    }
}
