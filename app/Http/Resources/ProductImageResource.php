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

        return ['id' => $this->id, 'variant_id' => $this->variant_id, 'alt_text' => $this->alt_text, 'is_primary' => $this->is_primary, 'sort_order' => $this->sort_order,
            'renditions' => $this->asset->renditions->mapWithKeys(fn ($rendition) => [$rendition->rendition => ['url' => $storage->url($rendition), 'width' => $rendition->width_px, 'height' => $rendition->height_px]])->all()];
    }
}
