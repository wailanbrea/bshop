<?php

namespace App\Domain\Media;

use App\Domain\Business\AuditTrailService;
use App\Models\MediaAsset;
use App\Models\MediaRendition;
use App\Models\MediaUploadSession;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MediaUploadService
{
    public function __construct(private ImageProcessor $processor, private ObjectStorage $objects, private AuditTrailService $audit) {}

    private function authorize(User $actor): void
    {
        abort_unless($actor->fresh()?->permits('catalog.manage'), 403);
    }

    /** Short atomic admission. No file/network operations are performed under these locks. */
    public function reserve(int $productId, array $input, User $actor): array
    {
        $config = $this->objects->configuration();

        return DB::transaction(function () use ($productId, $input, $actor, $config) {
            $this->authorize($actor);
            $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $quota = StorageQuota::whereKey(1)->lockForUpdate()->firstOrFail();
            $existing = MediaUploadSession::where('request_key', $input['request_key'])->lockForUpdate()->first();
            if ($existing) {
                abort_unless($existing->created_by_user_id === $actor->id && $existing->product_id === $product->id, 404);
                abort_if(! hash_equals($existing->source_sha256 ?? '', $input['source_sha256']) || $existing->variant_id != ($input['variant_id'] ?? null), 409, 'La clave de carga ya está ligada a otro archivo o variante.');
                if ($existing->status === 'verified') {
                    abort_unless(ProductImage::where('product_id', $productId)->where('media_asset_id', $existing->media_asset_id)->exists(), 409, 'La imagen de esta carga ya fue retirada. Selecciona una carga nueva.');

                    return ['session' => $existing, 'replay' => true];
                }
                abort_if(in_array($existing->status, ['issued', 'uploaded'], true), 409, 'La carga sigue en proceso. Consulta su estado antes de reintentar.');
                abort_if($existing->reserved_bytes > 0 || $existing->asset->cleanup_pending, 409, 'La carga requiere limpieza pendiente antes de reintentar.');
            }
            abort_if($product->status !== 'active', 409, 'Reactiva el producto antes de añadir fotografías.');
            if (isset($input['variant_id'])) {
                $product->variants()->where('is_active', true)->lockForUpdate()->findOrFail($input['variant_id']);
            }
            // Current reads are essential after waiting for product/quota locks under MySQL REPEATABLE READ.
            $count = ProductImage::where('product_id', $productId)->lockForUpdate()->get(['id'])->count() + MediaUploadSession::where('product_id', $productId)->whereIn('status', ['issued', 'uploaded'])->lockForUpdate()->get(['id'])->count();
            if ($count >= $quota->max_images_per_product) {
                throw ValidationException::withMessages(['file' => "Máximo {$quota->max_images_per_product} fotos por producto, incluidas variantes y cargas pendientes."]);
            }
            if ($input['declared_size'] < 1 || $input['declared_size'] > min(10485760, $quota->max_single_upload_bytes)) {
                throw ValidationException::withMessages(['file' => 'La fotografía supera el límite de carga de 10 MiB.']);
            }
            $this->space($quota, $input['declared_size']);
            $token = (string) Str::uuid();
            $stage = 'staging/'.$token.'/original';
            $asset = $existing ? $existing->asset : new MediaAsset;
            $asset->fill(['provider' => $config['provider'], 'bucket_name' => $config['bucket'], 'staging_provider' => 'local', 'staging_bucket_name' => 'media_staging', 'staging_key' => $stage, 'staging_key_hash' => hash('sha256', $stage), 'storage_token' => $token, 'status' => 'processing', 'visibility' => 'public', 'uploaded_by_user_id' => $actor->id, 'cleanup_pending' => false, 'error_code' => null, 'purged_at' => null]);
            $asset->save();
            $asset->private_cleaned_at = null;
            $asset->save();
            // Old failed attempts are safe to replace only after all their objects were verified absent.
            if ($existing) {
                MediaRendition::where('media_asset_id', $asset->id)->delete();
            }
            $session = $existing ?? new MediaUploadSession;
            $session->fill(['media_asset_id' => $asset->id, 'request_key' => $input['request_key'], 'declared_mime' => $input['declared_mime'], 'declared_size' => $input['declared_size'], 'expires_at' => now()->addMinutes(config('media.lease_minutes')), 'status' => 'issued', 'completed_at' => null, 'created_by_user_id' => $actor->id, 'product_id' => $productId, 'variant_id' => $input['variant_id'] ?? null, 'source_sha256' => $input['source_sha256'], 'reserved_bytes' => $input['declared_size'], 'attempts' => ($existing?->attempts ?? 0) + 1]);
            $session->save();
            $quota->bytes_pending += $session->reserved_bytes;
            $quota->updated_at = now();
            $quota->save();

            return ['session' => $session, 'replay' => false];
        }, 3);
    }

    public function upload(int $productId, UploadedFile $file, array $data, User $actor): array
    {
        return $this->exclusive($data['request_key'], fn () => $this->performUpload($productId, $file, $data, $actor));
    }

    /** OS lock survives long image/network work and is released automatically on process death. */
    private function exclusive(string $key, callable $callback): mixed
    {
        $directory = Storage::disk('media_staging')->path('locks');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            abort(503, 'No se pudo abrir el almacenamiento privado.');
        }
        $handle = fopen($directory.'/'.hash('sha256', $key).'.lock', 'c');
        if ($handle === false) {
            abort(503, 'No se pudo bloquear la carga.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            abort(409, 'La carga sigue en proceso. Consulta su estado.');
        }
        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function performUpload(int $productId, UploadedFile $file, array $data, User $actor): array
    {
        $this->authorize($actor);
        try {
            $this->processor->inspect($file->getRealPath());
        } catch (ImageRejected $error) {
            throw ValidationException::withMessages(['file' => $error->getMessage()]);
        }
        try {
            $admitted = $this->reserve($productId, [...$data, 'declared_size' => $file->getSize(), 'declared_mime' => $file->getMimeType() ?: 'application/octet-stream', 'source_sha256' => hash_file('sha256', $file->getRealPath())], $actor);
        } catch (\RuntimeException $error) {
            if ($error instanceof HttpExceptionInterface || $error instanceof ValidationException) {
                throw $error;
            }
            abort(503, 'El almacenamiento de imágenes no está configurado o disponible.');
        }
        $session = $admitted['session'];
        if ($admitted['replay']) {
            return ['session' => $session, 'replay' => true];
        }
        $asset = $session->asset;
        try {
            $stage = Storage::disk('media_staging');
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                $stage->put($asset->staging_key, $stream);
            } finally {
                fclose($stream);
            }
            $processed = $this->processor->process($stage->path($asset->staging_key), $stage->path('work/'.$asset->storage_token));
            $this->prepareRenditions($session->id, $processed);
            foreach (MediaRendition::where('media_asset_id', $asset->id)->orderBy('id')->get() as $rendition) {
                $this->stillProcessing($session->id);
                $this->objects->put($rendition, $processed['renditions'][$rendition->rendition]['path']);
            }
            $this->finish($session->id, $data['alt_text'] ?? null, $actor);

            return ['session' => $session->refresh(), 'replay' => false];
        } catch (ImageRejected $error) {
            $this->compensate($session->id, $error->reason);
            throw ValidationException::withMessages(['file' => $error->getMessage()]);
        } catch (\Throwable $error) {
            $this->compensate($session->id, 'processing_failed');
            if ($error instanceof ValidationException || $error instanceof HttpExceptionInterface) {
                throw $error;
            }
            abort(503, 'No se pudo completar la fotografía. La imagen no se publicó. Consulta el estado o reintenta la misma carga.');
        } finally {
            $this->clearPrivate($asset);
        }
    }

    private function space(StorageQuota $quota, int $extra): void
    {
        if ($quota->bytes_ready + $quota->bytes_pending + $quota->bytes_retained + $extra > $quota->max_bytes) {
            throw ValidationException::withMessages(['file' => 'El negocio alcanzó su cuota de imágenes de 2 GiB o tiene espacio pendiente/retener.']);
        }
    }

    private function locks(int $id): array
    {
        $reference = MediaUploadSession::findOrFail($id);
        $product = Product::withTrashed()->whereKey($reference->product_id)->lockForUpdate()->firstOrFail();
        $quota = StorageQuota::whereKey(1)->lockForUpdate()->firstOrFail();
        $asset = MediaAsset::withTrashed()->whereKey($reference->media_asset_id)->lockForUpdate()->firstOrFail();
        $session = MediaUploadSession::whereKey($id)->lockForUpdate()->firstOrFail();

        return [$product, $quota, $asset, $session];
    }

    private function stillProcessing(int $id, ?MediaUploadSession $lockedSession = null, ?MediaAsset $lockedAsset = null): void
    {
        $session = $lockedSession ?? MediaUploadSession::with('asset')->findOrFail($id);
        $asset = $lockedAsset ?? $session->asset;
        abort_unless(in_array($session->status, ['issued', 'uploaded'], true) && $asset->status === 'processing' && ! $session->expires_at->isPast(), 409, 'La carga dejó de estar activa. Consulta su estado.');
    }

    private function prepareRenditions(int $id, array $processed): void
    {
        DB::transaction(function () use ($id, $processed) {
            [$product, $quota, $asset, $session] = $this->locks($id);
            $this->stillProcessing($id, $session, $asset);
            $bytes = array_sum(array_column($processed['renditions'], 'size'));
            $this->space($quota, $bytes - $session->reserved_bytes);
            $quota->bytes_pending += $bytes - $session->reserved_bytes;
            $quota->updated_at = now();
            $quota->save();
            $session->reserved_bytes = $bytes;
            $session->status = 'uploaded';
            $session->save();
            $asset->byte_size = $bytes;
            $asset->mime_type = 'image/webp';
            $asset->width_px = $processed['width'];
            $asset->height_px = $processed['height'];
            foreach ($processed['renditions'] as $name => $rendition) {
                $key = 'products/'.$product->id.'/'.$asset->storage_token.'/v1/'.$name.'.webp';
                MediaRendition::create(['media_asset_id' => $asset->id, 'rendition' => $name, 'provider' => $asset->provider, 'namespace_name' => $asset->bucket_name, 'object_key' => $key, 'object_key_hash' => hash('sha256', $key), 'mime_type' => 'image/webp', 'width_px' => $rendition['width'], 'height_px' => $rendition['height'], 'byte_size' => $rendition['size'], 'sha256' => $rendition['sha256'], 'created_at' => now()]);
                if ($name === 'detail') {
                    $asset->object_key = $key;
                    $asset->object_key_hash = hash('sha256', $key);
                    $asset->sha256 = $rendition['sha256'];
                }
            }
            $asset->save();
        }, 3);
    }

    private function finish(int $id, ?string $alt, User $actor): void
    {
        DB::transaction(function () use ($id, $alt, $actor) {
            [$product, $quota, $asset, $session] = $this->locks($id);
            $this->stillProcessing($id, $session, $asset);
            $this->authorize($actor);
            abort_if($product->trashed() || $product->status !== 'active', 409, 'El producto fue archivado durante la carga.');
            if ($session->variant_id) {
                $product->variants()->where('is_active', true)->lockForUpdate()->findOrFail($session->variant_id);
            }
            $images = ProductImage::where('product_id', $product->id)->lockForUpdate()->get();
            $image = ProductImage::create(['product_id' => $product->id, 'variant_id' => $session->variant_id, 'media_asset_id' => $asset->id, 'alt_text' => $alt, 'is_primary' => $images->isEmpty(), 'sort_order' => ($images->max('sort_order') ?? -1) + 1]);
            $asset->status = 'ready';
            $asset->ready_at = now();
            $asset->error_code = null;
            $asset->save();
            $quota->bytes_pending -= $session->reserved_bytes;
            $quota->bytes_ready += $session->reserved_bytes;
            $quota->updated_at = now();
            $quota->save();
            $session->reserved_bytes = 0;
            $session->status = 'verified';
            $session->completed_at = now();
            $session->save();
            $product->increment('lock_version');
            $this->audit->record('media.published', $image, after: ['product_id' => $product->id, 'bytes' => $asset->byte_size, 'renditions' => 3], actor: $actor);
        }, 3);
    }

    /** Compensate outside SQL locks; quota stays reserved if deletion cannot be verified. */
    public function fail(int $id, string $reason): void
    {
        $key = MediaUploadSession::findOrFail($id)->request_key;
        $this->exclusive($key, fn () => $this->compensate($id, $reason));
    }

    private function compensate(int $id, string $reason): void
    {
        $session = MediaUploadSession::with('asset')->findOrFail($id);
        if ($session->status === 'verified') {
            return;
        }
        $clean = true;
        foreach ($session->asset->renditions as $rendition) {
            try {
                $this->objects->remove($rendition);
            } catch (\Throwable) {
                $clean = false;
            }
        }
        DB::transaction(function () use ($id, $reason, $clean) {
            [$product, $quota, $asset, $session] = $this->locks($id);
            if ($session->status === 'verified') {
                return;
            }
            if ($clean && $session->reserved_bytes > 0) {
                $quota->bytes_pending -= $session->reserved_bytes;
                $quota->updated_at = now();
                $quota->save();
                $session->reserved_bytes = 0;
            }
            $session->status = 'failed';
            $session->completed_at = now();
            $session->save();
            $asset->status = 'failed';
            $asset->error_code = $reason;
            $asset->cleanup_pending = ! $clean;
            $asset->cleanup_after = ! $clean ? now()->addMinutes(3) : null;
            $asset->save();
        }, 3);
        $this->clearPrivate($session->asset);
    }

    public function recoverExpired(?int $productId = null): int
    {
        $sessions = MediaUploadSession::where(fn ($q) => $q->where(fn ($pending) => $pending->whereIn('status', ['issued', 'uploaded'])->where('expires_at', '<=', now()->subMinutes(3)))->orWhere(fn ($failed) => $failed->where('status', 'failed')->where('reserved_bytes', '>', 0)))
            ->when($productId, fn ($q) => $q->where('product_id', $productId))->orderBy('id')->limit(20)->get();
        foreach ($sessions as $session) {
            try {
                $this->fail($session->id, 'interrupted_upload');
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        }

        return $sessions->count();
    }

    private function clearPrivate(MediaAsset $asset): void
    {
        if (! preg_match('#^staging/[a-f0-9-]{36}/original$#D', $asset->staging_key) || ! preg_match('/^[a-f0-9-]{36}$/D', $asset->storage_token ?? '')) {
            return;
        }
        try {
            $disk = Storage::disk('media_staging');
            $keys = [$asset->staging_key, ...array_map(fn ($name) => 'work/'.$asset->storage_token.'/'.$name.'.webp', array_keys(config('media.renditions')))];
            $disk->delete($keys);
            foreach ($keys as $key) {
                if ($disk->exists($key)) {
                    return;
                }
            }
            MediaAsset::withTrashed()->whereKey($asset->id)->where('storage_token', $asset->storage_token)->update(['private_cleaned_at' => now()]);
        } catch (\Throwable) { /* Private stale work is revisited by reconciliation. */
        }
    }

    public function cleanCompletedPrivate(): int
    {
        $sessions = MediaUploadSession::with('asset')->whereIn('status', ['verified', 'failed'])->whereHas('asset', fn ($q) => $q->whereNotNull('storage_token')->whereNull('private_cleaned_at'))->limit(20)->get();
        foreach ($sessions as $session) {
            try {
                $this->exclusive($session->request_key, fn () => $this->clearPrivate($session->asset));
            } catch (HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }
            }
        }

        return $sessions->count();
    }
}
