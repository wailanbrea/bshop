<?php

use App\Domain\Catalog\ProductService;
use App\Domain\Media\MediaUploadService;
use App\Models\MediaUploadSession;
use App\Models\Product;
use App\Models\StorageQuota;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/import-test-connection.php';
config(['media.provider' => 'local']);
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$service = app(MediaUploadService::class);
$input = ['declared_size' => 1024, 'declared_mime' => 'image/png', 'source_sha256' => str_repeat('a', 64)];
if (($argv[1] ?? '') === 'worker') {
    if (! Str::isUuid($argv[3] ?? '') || ! preg_match('/^[A-F0-9]{8}$/D', $argv[4] ?? '')) {
        throw new RuntimeException('Invalid worker identifier.');
    }
    file_put_contents(base_path('.runtime/media-race-'.$argv[4].'/'.$argv[3].'.ready'), 'ready');
    echo "READY\n";
    flush();
    try {
        $service->reserve((int) $argv[2], [...$input, 'request_key' => $argv[3]], $owner);
        echo "RESULT:201\n";
    } catch (ValidationException) {
        echo "RESULT:422\n";
    }
    exit(0);
}
$catalog = app(ProductService::class);
if (in_array($argv[1] ?? '', ['inspect', 'recover-qa'], true)) {
    foreach (Product::where('name', 'like', 'Media concurrency QA %')->where('status', 'active')->get() as $fixture) {
        echo 'QA fixture '.$fixture->id.' | pending: '.MediaUploadSession::where('product_id', $fixture->id)->where('status', 'issued')->count()."\n";
        if ($argv[1] === 'recover-qa') {
            foreach (MediaUploadSession::where('product_id', $fixture->id)->where('status', 'issued')->get() as $session) {
                $service->fail($session->id, 'interrupted_qa_complete');
            }
            $catalog->update($fixture->id, [...$fixture->only(['name', 'product_type', 'track_stock', 'unit_code']), 'status' => 'archived', 'version' => $fixture->lock_version], $owner);
            echo "QA reservations released; fixture archived with history preserved.\n";
        }
    }
    exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(4)));
$payload = ['name' => 'Media concurrency QA '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'MEDIA-RACE-'.$tag, 'sale_price' => '1', 'attribute_value_ids' => []]]];
$product = $catalog->create($payload, $owner);
$before = StorageQuota::findOrFail(1)->bytes_pending;
$processes = [];
$directory = base_path('.runtime/media-race-'.$tag);
if (! mkdir($directory, 0770, true) && ! is_dir($directory)) {
    throw new RuntimeException('QA output directory unavailable.');
}
try {
    for ($i = 0; $i < 4; $i++) {
        $service->reserve($product->id, [...$input, 'request_key' => (string) Str::uuid()], $owner);
    }
    DB::beginTransaction();
    Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    for ($i = 0; $i < 2; $i++) {
        $key = (string) Str::uuid();
        $pipes = [];
        $out = $directory.'/'.$key.'.out';
        $err = $directory.'/'.$key.'.err';
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $product->id, $key, $tag], [0 => ['file', 'NUL', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Worker could not start.');
        }
        $processes[] = ['process' => $process, 'out' => $out, 'err' => $err, 'ready' => $directory.'/'.$key.'.ready'];
    }
    $deadline = microtime(true) + 15;
    do {
        if (count(array_filter($processes, fn ($w) => is_file($w['ready']))) === 2) {
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Workers did not become ready.');
        }
        usleep(25000);
    } while (true);
    usleep(250000);
    DB::commit();
    $results = [];
    foreach ($processes as &$worker) {
        $deadline = microtime(true) + 15;
        while (proc_get_status($worker['process'])['running']) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker timed out.');
            } usleep(25000);
        }
        proc_close($worker['process']);
        if (! preg_match('/RESULT:(201|422)/', file_get_contents($worker['out']), $match)) {
            throw new RuntimeException('Worker failed: '.file_get_contents($worker['err']));
        }
        $results[] = (int) $match[1];
    } unset($worker);
    sort($results);
    if ($results !== [201, 422] || MediaUploadSession::where('product_id', $product->id)->where('status', 'issued')->count() !== 5 || StorageQuota::find(1)->bytes_pending !== $before + 5120) {
        throw new RuntimeException('Concurrent slot/quota invariant failed.');
    }
    echo "PASS: two MySQL processes competing for slot 5 return 201/422, exactly 5 pending slots and 5120 reserved bytes.\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (MediaUploadSession::where('product_id', $product->id)->where('status', 'issued')->get() as $session) {
        $service->fail($session->id, 'qa_reservation_complete');
    }
    $catalog->update($product->id, [...$payload, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
}
if (StorageQuota::find(1)->bytes_pending !== $before) {
    throw new RuntimeException('QA reservations were not released.');
}
echo "PASS: reservations released; QA product archived and audit/history preserved.\n";
