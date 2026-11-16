<?php

use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Catalog\ClothingVariants;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';

function clothingQaAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function clothingQaWait(callable $ready): void
{
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if ($ready()) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Clothing QA barrier timed out.');
}

if (($argv[1] ?? '') === 'worker') {
    try {
        clothingQaAssert((bool) preg_match('/^[A-F0-9]{12}$/D', $argv[2] ?? ''), 'Invalid QA tag.');
        clothingQaAssert(in_array($argv[3] ?? '', ['a', 'b'], true), 'Invalid QA worker.');
        $tag = $argv[2];
        $worker = $argv[3];
        $directory = base_path('.runtime/clothing-options-'.$tag);
        $actor = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
        $connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::listen(function ($query) use ($directory, $worker, $connection) {
            if (str_contains(strtolower($query->sql), 'from `roles`') && ! file_exists($directory.'/'.$worker.'.ready')) {
                file_put_contents($directory.'/'.$worker.'.ready', (string) $connection);
            }
        });
        $body = ['request_key' => (string) Str::uuid(), 'name' => 'Clothing option QA '.$tag.' '.$worker, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'clothing_variants' => [['sku' => 'CLO-OPT-'.$tag.'-'.$worker, 'sale_price' => '25', 'color' => 'QA color '.$tag, 'size' => 'QA size '.$tag]]];
        $request = Request::create('/api/v1/business/products', 'POST', $body);
        $request->headers->set('Accept', 'application/json');
        $request->setUserResolver(fn () => $actor);
        $response = app(CatalogController::class)->create($request, app(ProductService::class), app(IdempotentAction::class), app(InventoryService::class), app(StoreAuthorizationService::class), app(ClothingVariants::class));
        echo 'RESULT:'.$response->getStatusCode().':'.$response->getData(true)['data']['id']."\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Worker failed: '.$error::class."\n");
        exit(1);
    }
}

$tag = strtoupper(bin2hex(random_bytes(6)));
$directory = base_path('.runtime/clothing-options-'.$tag);
clothingQaAssert(mkdir($directory, 0770, true), 'QA artifact directory unavailable.');
$workers = [];
try {
    DB::beginTransaction();
    BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
    foreach (['a', 'b'] as $worker) {
        $out = $directory.'/'.$worker.'.out';
        $err = $directory.'/'.$worker.'.err';
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $tag, $worker], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path());
        clothingQaAssert(is_resource($process), 'QA worker unavailable.');
        $workers[] = ['process' => $process, 'base' => $directory.'/'.$worker, 'out' => $out];
    }
    clothingQaWait(fn () => count(array_filter($workers, fn ($worker) => is_file($worker['base'].'.ready'))) === 2);
    $connections = array_map(fn ($worker) => (int) file_get_contents($worker['base'].'.ready'), $workers);
    clothingQaWait(fn () => collect(DB::select('SHOW FULL PROCESSLIST'))->filter(fn ($process) => in_array((int) $process->Id, $connections, true) && str_contains(strtolower($process->Info ?? ''), 'for update') && str_contains(strtolower($process->Info ?? ''), '`business_profile`'))->count() === 2);
    DB::commit();
    $ids = [];
    foreach ($workers as $worker) {
        clothingQaWait(fn () => ! proc_get_status($worker['process'])['running']);
        proc_close($worker['process']);
        clothingQaAssert((bool) preg_match('/^RESULT:201:(\d+)$/m', file_get_contents($worker['out']), $match), 'Worker did not create its QA product; inspect private artifacts.');
        $ids[] = (int) $match[1];
    }
    $workers = [];
    clothingQaAssert(count(array_unique($ids)) === 2, 'Both independent products were not created.');
    $colorValues = DB::table('attribute_values')->where('value', 'QA color '.$tag)->count();
    $sizeValues = DB::table('attribute_values')->where('value', 'QA size '.$tag)->count();
    clothingQaAssert($colorValues === 1 && $sizeValues === 1, 'Concurrent catalogue values were duplicated.');
    clothingQaAssert(DB::table('product_variants')->whereIn('product_id', $ids)->count() === 2, 'QA variants missing.');
    echo "PASS: two simultaneous clothing creations reuse one color and one size without duplicate catalogue values.\n";
    echo 'Synthetic QA products, values and private artifacts retained: '.$directory."\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($workers as $worker) {
        if (is_resource($worker['process'])) {
            if (proc_get_status($worker['process'])['running']) {
                proc_terminate($worker['process']);
            }
            proc_close($worker['process']);
        }
    }
}
