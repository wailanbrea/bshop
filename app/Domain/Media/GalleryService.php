<?php

namespace App\Domain\Media;

use App\Domain\Business\AuditTrailService;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GalleryService
{
    public function __construct(private AuditTrailService $audit, private ObjectStorage $objects) {}

    public function update(int $productId, array $data, User $actor): void
    {
        DB::transaction(function () use ($productId, $data, $actor) {
            abort_unless($actor->fresh()?->permits('catalog.manage'), 403);
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            abort_if($product->lock_version !== $data['version'], 409, 'Las fotos cambiaron. Recarga la galería y vuelve a intentar.');
            $images = ProductImage::where('product_id', $productId)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $ids = array_column($data['images'], 'id');
            $expected = $images->keys()->all();
            sort($ids);
            sort($expected);
            abort_unless($ids === $expected, 409, 'Envía la galería completa y actual.');
            abort_unless(! $images->count() || $images->has($data['primary_id']), 422, 'Selecciona una foto principal de esta galería.');
            foreach ($data['images'] as $item) {
                if (isset($item['variant_id'])) {
                    $product->variants()->where('is_active', true)->lockForUpdate()->findOrFail($item['variant_id']);
                }
            }
            // Release the unique primary key before assigning the new primary.
            ProductImage::where('product_id', $productId)->update(['is_primary' => false]);
            foreach ($data['images'] as $position => $item) {
                ProductImage::whereKey($item['id'])->update(['is_primary' => $item['id'] === $data['primary_id'], 'variant_id' => $item['variant_id'] ?? null, 'alt_text' => $item['alt_text'] ?? null, 'sort_order' => $position]);
            }
            $product->increment('lock_version');
            $this->audit->record('media.gallery.updated', $product, after: ['image_ids' => array_column($data['images'], 'id'), 'primary_id' => $data['primary_id']], actor: $actor);
        }, 3);
    }

    public function remove(int $productId, int $imageId, int $version, User $actor): void
    {
        DB::transaction(function () use ($productId, $imageId, $version, $actor) {
            abort_unless($actor->fresh()?->permits('catalog.manage'), 403);
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            abort_if($product->lock_version !== $version, 409, 'Las fotos cambiaron. Recarga la galería.');
            $quota = StorageQuota::whereKey(1)->lockForUpdate()->firstOrFail();
            $image = ProductImage::where('product_id', $productId)->lockForUpdate()->findOrFail($imageId);
            $asset = MediaAsset::whereKey($image->media_asset_id)->lockForUpdate()->firstOrFail();
            $wasPrimary = $image->is_primary;
            $image->is_primary = false;
            $image->save();
            $image->delete();
            if ($wasPrimary) {
                ProductImage::where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->lockForUpdate()->first()?->update(['is_primary' => true]);
            }
            $asset->status = 'deleted';
            $asset->cleanup_pending = true;
            $asset->cleanup_after = now()->addHours(config('media.retention_hours'));
            $asset->save();
            $asset->delete();
            $quota->bytes_ready -= $asset->byte_size;
            $quota->bytes_retained += $asset->byte_size;
            $quota->updated_at = now();
            $quota->save();
            $product->increment('lock_version');
            $this->audit->record('media.image.removed', $image, after: ['retained_bytes' => $asset->byte_size, 'cleanup_after' => $asset->cleanup_after->toIso8601String()], actor: $actor);
        }, 3);
    }

    public function purgeDue(): int
    {
        $assets = MediaAsset::withTrashed()->where('status', 'deleted')->where('cleanup_pending', true)->where('cleanup_after', '<=', now())->with('renditions')->orderBy('id')->limit(20)->get();
        $count = 0;
        foreach ($assets as $asset) {
            try {
                foreach ($asset->renditions as $rendition) {
                    $this->objects->remove($rendition);
                }
            } catch (\Throwable) {
                continue;
            }
            DB::transaction(function () use ($asset, &$count) {
                $quota = StorageQuota::whereKey(1)->lockForUpdate()->firstOrFail();
                $current = MediaAsset::withTrashed()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
                if (! $current->cleanup_pending || $current->status !== 'deleted') {
                    return;
                }
                $quota->bytes_retained -= $current->byte_size;
                $quota->updated_at = now();
                $quota->save();
                $current->cleanup_pending = false;
                $current->purged_at = now();
                $current->save();
                $count++;
            }, 3);
        }

        return $count;
    }
}
