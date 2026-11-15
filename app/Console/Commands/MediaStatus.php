<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class MediaStatus extends Command
{
    protected $signature = 'bshop:media-status';

    protected $description = 'Show image processor and storage configuration without credentials or remote requests.';

    public function handle(): int
    {
        $disk = config('filesystems.disks.r2_catalog');
        $this->table(['Setting', 'Status'], [
            ['Provider', config('media.provider')], ['GD WebP', (gd_info()['WebP Support'] ?? false) ? 'ready' : 'unavailable'],
            ['EXIF orientation', extension_loaded('exif') ? 'ready' : 'unavailable'],
            ['R2 endpoint', filled($disk['endpoint'] ?? null) ? 'configured (hidden)' : 'not configured'],
            ['R2 key', filled($disk['key'] ?? null) ? 'configured (hidden)' : 'not configured'],
            ['R2 secret', filled($disk['secret'] ?? null) ? 'configured (hidden)' : 'not configured'],
            ['R2 bucket', filled($disk['bucket'] ?? null) ? 'configured (hidden)' : 'not configured'],
            ['R2 public domain', filled(config('media.r2_public_url')) ? 'configured (hidden)' : 'not configured'],
        ]);
        $this->info('No database query or remote storage request was performed.');

        return self::SUCCESS;
    }
}
