<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'product_id' => $this->product_id, 'sku' => $this->sku, 'barcode' => $this->barcode, 'sale_price' => $this->sale_price,
            'cost_price' => $this->when($request->user()->permits('catalog.costs'), fn () => $this->cost_price),
            'description' => $this->description, 'is_active' => $this->is_active, 'version' => $this->lock_version,
            'attribute_values' => $this->attributeValues->map(fn ($value) => ['id' => $value->id, 'attribute_id' => $value->attribute_id, 'attribute_name' => $value->attribute->name, 'value' => $value->value]),
        ];
    }
}
