<?php

namespace App\Console\Commands;

use App\Domain\Media\GalleryService;
use App\Domain\Media\MediaUploadService;
use App\Models\MediaAsset;
use App\Models\MediaUploadSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileMedia extends Command
{
    protected $signature = 'bshop:media-reconcile {--apply : Recover expired uploads and purge due retained derivatives}';

    protected $description = 'Inspect media lifecycle; explicit --apply performs bounded recovery/cleanup';

    public function handle(MediaUploadService $uploads, GalleryService $gallery): int
    {
        $db = DB::connection();
        $this->line('Environment: '.app()->environment().' | Connection: '.$db->getName().' | Host: '.($db->getConfig('host') ?: 'local').' | Database: '.$db->getDatabaseName());
        $due = MediaAsset::withTrashed()->where('status', 'deleted')->where('cleanup_pending', true)->where('cleanup_after', '<=', now())->get();
        $this->line('Due retained assets: '.$due->count().' | bytes: '.$due->sum('byte_size'));
        $this->line('Pending sessions: '.MediaUploadSession::whereIn('status', ['issued', 'uploaded'])->count());
        if (! $this->option('apply')) {
            $this->info('Inspection only. No files or database rows changed.');

            return self::SUCCESS;
        }
        $this->info('Recovered sessions: '.$uploads->recoverExpired().' | purged retained assets: '.$gallery->purgeDue());
        $this->info('Completed private-work candidates inspected: '.$uploads->cleanCompletedPrivate());

        return self::SUCCESS;
    }
}
