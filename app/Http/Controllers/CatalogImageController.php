<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

class CatalogImageController extends Controller
{
    public function __invoke(string $asset, string $rendition, string $hash)
    {
        $media = MediaAsset::where('status', 'ready')->where('provider', 'local')->where('visibility', 'public')->findOrFail($asset);
        abort_unless(ProductImage::where('media_asset_id', $media->id)->whereHas('product', fn ($q) => $q->where('status', 'active'))->exists(), 404);
        $file = $media->renditions()->where('provider', 'local')->where('rendition', $rendition)->where('sha256', $hash)->firstOrFail();
        abort_unless(preg_match('#^products/[1-9][0-9]*/[a-f0-9-]{36}/v1/(thumb|card|detail)\.webp$#D', $file->object_key), 404);
        $disk = Storage::disk('media_catalog');
        abort_unless($disk->exists($file->object_key), 404);

        return $disk->response($file->object_key, null, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable'])->setPublic();
    }
}
