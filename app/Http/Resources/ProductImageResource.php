<?php

namespace App\Http\Resources;

use App\Domain\Media\ObjectStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $storage = app(ObjectStorage::class);
        $variant = $this->relationLoaded('variant') ? $this->variant : null;
        $variantLabel = null;
        if ($variant && $variant->relationLoaded('attributeValues') && $variant->attributeValues->isNotEmpty()) {
            $variantLabel = $variant->attributeValues
                ->map(fn ($v) => trim(($v->attribute?->name ? $v->attribute->name.': ' : '').$v->value))
                ->implode(' · ');
        }

        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'variant_sale_price' => $variant?->sale_price,
            'variant_available_stock' => $variant?->getAttribute('available_stock'),
            'variant_label' => $variantLabel,
            'alt_text' => $this->alt_text,
            'is_primary' => $this->is_primary,
            'sort_order' => $this->sort_order,
            'renditions' => $this->asset->renditions->mapWithKeys(fn ($rendition) => [$rendition->rendition => ['url' => $storage->url($rendition), 'width' => $rendition->width_px, 'height' => $rendition->height_px]])->all(),
        ];
    }
}
