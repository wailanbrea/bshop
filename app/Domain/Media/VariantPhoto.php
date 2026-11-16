<?php

namespace App\Domain\Media;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/** Resolve only published derivatives: exact variant, same color, then product primary. */
final class VariantPhoto
{
    public function url(Product $product, ProductVariant $variant, string $size, ?Collection $associated = null): ?string
    {
        $color = $this->color($variant);
        $related = $associated ?? ($product->relationLoaded('variants') ? $product->variants->keyBy('id') : collect());
        $images = $product->images;
        $candidates = $images->filter(fn ($image) => (int) $image->variant_id === (int) $variant->id)
            ->concat($images->filter(function ($image) use ($related, $color, $variant) {
                if (! $color || ! $image->variant_id || (int) $image->variant_id === (int) $variant->id) {
                    return false;
                }
                $other = $related->get($image->variant_id);

                return $other && $this->color($other) === $color && $this->nonSizeAttributes($other) === $this->nonSizeAttributes($variant);
            }))
            ->concat($images->filter(fn ($image) => $image->is_primary));
        foreach ($candidates as $image) {
            $rendition = $image->asset?->renditions?->firstWhere('rendition', $size);
            if ($rendition && ($url = app(ObjectStorage::class)->url($rendition))) {
                return $url;
            }
        }

        return null;
    }

    private function color(ProductVariant $variant): ?int
    {
        $value = $variant->attributeValues->first(fn ($item) => mb_strtolower(trim($item->attribute->name)) === 'color');

        return $value ? (int) $value->id : null;
    }

    private function nonSizeAttributes(ProductVariant $variant): array
    {
        return $variant->attributeValues
            ->reject(fn ($item) => mb_strtolower(trim($item->attribute->name)) === 'talla')
            ->mapWithKeys(fn ($item) => [(int) $item->attribute_id => (int) $item->id])
            ->sortKeys()->all();
    }
}
