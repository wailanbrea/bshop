<?php

use App\Domain\Catalog\CatalogImportService;
use App\Models\CatalogImport;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/import-test-connection.php';
$backup = base_path('.runtime/import-schema-backup-'.gmdate('Ymd-His').'.json');
if (file_exists($backup)) {
    throw new RuntimeException('Backup path exists.');
}
$tables = DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'bshop_testing_single'");
$snapshot = [];
foreach ($tables as $table) {
    $create = (array) DB::selectOne('SHOW CREATE TABLE `'.$table->TABLE_NAME.'`');
    $snapshot[$table->TABLE_NAME] = $create['Create Table'];
}
file_put_contents($backup, json_encode($snapshot, JSON_THROW_ON_ERROR));
if (count(json_decode(file_get_contents($backup), true, flags: JSON_THROW_ON_ERROR)) !== count($tables)) {
    throw new RuntimeException('Schema backup verification failed.');
}
echo "Verified pre-migration schema backup: $backup\n";
$auditRows = DB::table('audit_logs')->orderBy('id')->get()->map(function ($row) {
    $row->entity_id = (string) $row->entity_id;

    return $row;
})->all();
$auditBackup = base_path('.runtime/import-audit-data-backup-'.gmdate('Ymd-His').'.json');
if (file_exists($auditBackup)) {
    throw new RuntimeException('Audit backup exists.');
}
file_put_contents($auditBackup, json_encode($auditRows, JSON_THROW_ON_ERROR));
if (count(json_decode(file_get_contents($auditBackup), true, flags: JSON_THROW_ON_ERROR)) !== count($auditRows)) {
    throw new RuntimeException('Audit data backup verification failed.');
}
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
$afterAudit = DB::table('audit_logs')->orderBy('id')->get()->map(function ($row) {
    $row->entity_id = (string) $row->entity_id;

    return $row;
})->all();
if (json_encode($auditRows, JSON_THROW_ON_ERROR) !== json_encode($afterAudit, JSON_THROW_ON_ERROR)) {
    throw new RuntimeException('Existing audit values changed during migration.');
}
echo "PASS: existing audit values preserved; entity IDs support numeric and opaque records.\n";
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$service = app(CatalogImportService::class);
$tag = strtoupper(bin2hex(random_bytes(4)));
DB::beginTransaction();
try {
    $before = Product::count();
    $csv = "name,sku,sale_price,cost_price,product_type,track_stock\nImport native QA,NATIVE-$tag,999999999999999.9999,0.0001,physical,1\nService native QA,SERVICE-$tag,10.00,,service,0\n";
    $batch = $service->preview(UploadedFile::fake()->createWithContent('native.csv', $csv), $owner);
    if ($batch->error_count !== 0 || Product::count() !== $before) {
        throw new RuntimeException('Native preview was not a clean dry run.');
    }
    $result = $service->confirm($batch->id, $owner);
    if ($result['invalid'] || Product::count() !== $before + 2) {
        throw new RuntimeException('Native confirmation failed.');
    }
    $service->confirm($batch->id, $owner);
    if (Product::count() !== $before + 2) {
        throw new RuntimeException('Duplicate after retry.');
    }
    $variant = ProductVariant::where('sku', 'NATIVE-'.$tag)->firstOrFail();
    if ($variant->sale_price !== '999999999999999.9999' || $variant->cost_price !== '0.0001') {
        throw new RuntimeException('Import decimal precision lost.');
    }
    echo "PASS: MySQL preview, atomic confirm/retry and full-range decimal/cost precision.\n";
    $service->revert($batch->id, $owner);
    $service->revert($batch->id, $owner);
    $ids = array_column($result['batch']->result['products'], 'id');
    if (Product::whereIn('id', $ids)->where('status', 'archived')->count() !== 2 || Product::count() !== $before + 2) {
        throw new RuntimeException('Native non-destructive reversal failed.');
    }
    if (CatalogImport::findOrFail($batch->id)->status !== 'reverted') {
        throw new RuntimeException('Import state mismatch.');
    }
    echo "PASS: MySQL reversal archives all unused products; retry is idempotent.\n";
} finally {
    DB::rollBack();
}
echo "Native smoke rows rolled back; existing fixtures retained.\n";
