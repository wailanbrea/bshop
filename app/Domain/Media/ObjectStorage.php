<?php

namespace App\Domain\Media;

use App\Models\MediaRendition;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ObjectStorage
{
    public function configuration(): array
    {
        $provider = config('media.provider');
        if (! in_array($provider, ['local', 'r2'], true)) {
            throw new RuntimeException('Unsupported media provider.');
        }
        if ($provider === 'r2') {
            $this->r2();
        }

        return ['provider' => $provider, 'bucket' => $provider === 'local' ? 'media_catalog' : config('filesystems.disks.r2_catalog.bucket')];
    }

    public function put(MediaRendition $rendition, string $file): void
    {
        $this->key($rendition->object_key);
        $stream = fopen($file, 'rb');
        try {
            if ($rendition->provider === 'local') {
                Storage::disk('media_catalog')->put($rendition->object_key, $stream);
            } else {
                $this->r2($rendition->namespace_name)->putObject(['Bucket' => $rendition->namespace_name, 'Key' => $rendition->object_key, 'Body' => $stream, 'ContentLength' => $rendition->byte_size, 'ContentType' => 'image/webp', 'ContentMD5' => base64_encode(md5_file($file, true)), 'CacheControl' => 'public, max-age=31536000, immutable', 'Metadata' => ['sha256' => $rendition->sha256]]);
            }
        } finally {
            fclose($stream);
        }
        $this->verify($rendition);
    }

    public function verify(MediaRendition $rendition): void
    {
        $this->key($rendition->object_key);
        if ($rendition->provider === 'local') {
            $disk = Storage::disk('media_catalog');
            if (! $disk->exists($rendition->object_key) || $disk->size($rendition->object_key) !== (int) $rendition->byte_size || ! hash_equals($rendition->sha256, hash_file('sha256', $disk->path($rendition->object_key)))) {
                throw new RuntimeException('Local rendition verification failed.');
            }
        } else {
            $head = $this->r2($rendition->namespace_name)->headObject(['Bucket' => $rendition->namespace_name, 'Key' => $rendition->object_key]);
            if ((int) $head['ContentLength'] !== (int) $rendition->byte_size || ($head['ContentType'] ?? '') !== 'image/webp' || ! hash_equals($rendition->sha256, $head['Metadata']['sha256'] ?? '')) {
                throw new RuntimeException('Remote rendition verification failed.');
            }
        }
    }

    public function remove(MediaRendition $rendition): void
    {
        $this->key($rendition->object_key);
        if ($rendition->provider === 'local') {
            $disk = Storage::disk('media_catalog');
            $disk->delete($rendition->object_key);
            if ($disk->exists($rendition->object_key)) {
                throw new RuntimeException('Local object still exists.');
            }
        } else {
            $client = $this->r2($rendition->namespace_name);
            $client->deleteObject(['Bucket' => $rendition->namespace_name, 'Key' => $rendition->object_key]);
            if ($client->doesObjectExistV2($rendition->namespace_name, $rendition->object_key)) {
                throw new RuntimeException('Remote object still exists.');
            }
        }
    }

    public function url(MediaRendition $rendition): ?string
    {
        if ($rendition->provider === 'local') {
            return '/media/catalog/'.$rendition->media_asset_id.'/'.$rendition->rendition.'/'.$rendition->sha256.'.webp';
        }
        if ($rendition->namespace_name !== config('filesystems.disks.r2_catalog.bucket') || ! filled(config('media.r2_public_url'))) {
            return null;
        }

        return rtrim(config('media.r2_public_url'), '/').'/'.$rendition->object_key;
    }

    private function key(string $key): void
    {
        if (! preg_match('#^products/[1-9][0-9]*/[a-f0-9-]{36}/v1/(thumb|card|detail)\.webp$#D', $key)) {
            throw new RuntimeException('Invalid immutable media key.');
        }
    }

    private function r2(?string $bucket = null)
    {
        $config = config('filesystems.disks.r2_catalog');
        foreach (['key', 'secret', 'endpoint', 'bucket'] as $field) {
            if (! filled($config[$field] ?? null)) {
                throw new RuntimeException('R2 media configuration incomplete.');
            }
        }
        if ($bucket !== null && $bucket !== $config['bucket']) {
            throw new RuntimeException('R2 bucket differs from the stored namespace.');
        }
        if (parse_url($config['endpoint'], PHP_URL_SCHEME) !== 'https' || parse_url((string) config('media.r2_public_url'), PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('R2 media requires HTTPS endpoints.');
        }

        return Storage::disk('r2_catalog')->getClient();
    }
}
