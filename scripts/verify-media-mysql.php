<?php

use App\Domain\Media\GalleryService;
use App\Domain\Media\MediaUploadService;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
$snapshot = [];
foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'bshop_testing_single'") as $table) {
    $name = $table->TABLE_NAME;
    $snapshot[$name] = ['schema' => ((array) DB::selectOne('SHOW CREATE TABLE `'.$name.'`'))['Create Table'], 'rows' => DB::table($name)->get()->map(fn ($row) => (array) $row)->all()];
}
$backup = base_path('.runtime/media-mysql-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json');
$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
if (file_exists($backup) || file_put_contents($backup, $json) !== strlen($json) || ! hash_equals(hash('sha256', $json), hash_file('sha256', $backup))) {
    throw new RuntimeException('Full schema/data backup verification failed.');
}
echo 'Verified full schema/data backup: '.$backup.' | tables: '.count($snapshot)."\n";
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
foreach ($snapshot as $name => $saved) {
    if ($name === 'migrations') {
        continue;
    }
    $before = $saved['rows'];
    if ($before === []) {
        if (DB::table($name)->count() !== 0) {
            throw new RuntimeException('Unexpected rows in '.$name);
        }

continue;
    }
    $after = DB::table($name)->select(array_keys($before[0]))->get()->map(fn ($row) => (array) $row)->all();
    $canonical = fn ($rows) => collect($rows)->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
    if ($canonical($before) !== $canonical($after)) {
        throw new RuntimeException('Existing values changed in '.$name);
    }
}
echo "PASS: existing row values preserved by media migration.\n";
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$product = Product::where('status', 'active')->firstOrFail();
$tag = (string) Str::uuid();
// QA derivatives are written only to a dedicated private artifact folder, never R2.
config(['media.provider' => 'local', 'filesystems.disks.media_staging.root' => base_path('.runtime/media-qa/'.$tag.'/staging'), 'filesystems.disks.media_catalog.root' => base_path('.runtime/media-qa/'.$tag.'/catalog')]);
DB::beginTransaction();
try {
    $before = StorageQuota::findOrFail(1)->bytes_ready;
    $service = app(MediaUploadService::class);
    $file = UploadedFile::fake()->image('native.png', 80, 40);
    $result = $service->upload($product->id, $file, ['request_key' => $tag], $owner);
    $again = $service->upload($product->id, $file, ['request_key' => $tag], $owner);
    if ($result['session']->status !== 'verified' || ! $again['replay'] || StorageQuota::find(1)->bytes_ready <= $before) {
        throw new RuntimeException('Native upload/replay/quota failed.');
    }
    $image = ProductImage::where('media_asset_id', $result['session']->media_asset_id)->firstOrFail();
    $gallery = app(GalleryService::class);
    $gallery->remove($product->id, $image->id, $product->fresh()->lock_version, $owner);
    if (StorageQuota::find(1)->bytes_ready !== $before || StorageQuota::find(1)->bytes_retained < 1) {
        throw new RuntimeException('Native retained accounting failed.');
    }
    echo "PASS: MySQL upload, idempotent replay, primary uniqueness, quota and soft removal.\n";
} finally {
    DB::rollBack();
}
echo "QA rows rolled back; existing fixtures retained. Local QA derivatives retained in private .runtime/media-qa artifacts.\n";
