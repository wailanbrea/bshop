<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variantSummary = null;
        if ($this->relationLoaded('variants') && $this->variants->isNotEmpty()) {
            $grouped = [];
            foreach ($this->variants as $variant) {
                if (! $variant->relationLoaded('attributeValues')) {
                    continue;
                }
                foreach ($variant->attributeValues as $av) {
                    $attrName = trim((string) ($av->attribute?->name ?? ''));
                    $val = trim((string) $av->value);
                    if ($attrName === '' || $val === '') {
                        continue;
                    }
                    $grouped[$attrName][$val] = $val;
                }
            }
            if (! empty($grouped)) {
                $parts = [];
                foreach ($grouped as $name => $vals) {
                    $parts[] = $name.': '.implode(', ', array_values($vals));
                }
                $variantSummary = implode(' · ', $parts);
            }
        }

        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'product_type' => $this->product_type, 'unit_code' => $this->unit_code, 'track_stock' => $this->track_stock, 'status' => $this->status, 'version' => $this->lock_version,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'brand' => $this->brand ? ['id' => $this->brand->id, 'name' => $this->brand->name] : null,
            'primary_sku' => $this->primary_sku, 'variants_count' => $this->variants_count, 'active_variants_count' => $this->active_variants_count,
            'variant_summary' => $variantSummary,
            'available_stock' => $this->track_stock ? $this->getAttribute('available_stock') : null,
            'min_sale_price' => $this->min_sale_price, 'max_sale_price' => $this->max_sale_price,
            'images' => ProductImageResource::collection($this->whenLoaded('images')),
        ];
    }
}
