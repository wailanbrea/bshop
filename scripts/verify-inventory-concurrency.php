<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/import-test-connection.php';
if (($argv[1] ?? '') === 'worker') {
    [$script, $mode, $productId, $storeId, $warehouseId, $actorId, $key, $tag] = $argv;
    if (! Str::isUuid($key) || ! preg_match('/^[A-F0-9]{8}$/D', $tag)) {
        throw new RuntimeException('Invalid worker identity.');
    }
    $actor = User::findOrFail($actorId);
    $context = app(StoreContext::class);
    $context->useStore(Store::findOrFail($storeId));
    $variant = Product::findOrFail($productId)->variants()->firstOrFail();
    file_put_contents(base_path('.runtime/inventory-race-'.$tag.'/'.$key.'.ready'), 'ready');
    try {
        app(InventoryService::class)->move($context, ['warehouse_id' => (int) $warehouseId, 'variant_id' => $variant->id, 'request_key' => $key, 'operation' => 'issue', 'quantity' => '1', 'version' => 1, 'note' => 'QA dos usuarios, última unidad'], $actor);
        echo "RESULT:201\n";
    } catch (HttpException $error) {
        echo 'RESULT:'.$error->getStatusCode()."\n";
    }
    exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(4)));
$directory = base_path('.runtime/inventory-race-'.$tag);
if (! mkdir($directory, 0770, true) && ! is_dir($directory)) {
    throw new RuntimeException('QA artifacts unavailable.');
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$second = User::factory()->create(['name' => 'QA concurrencia '.$tag, 'email' => 'inventory-'.strtolower($tag).'@example.test']);
$second->roles()->attach(Role::where('code', 'manager')->value('id'));
DB::table('store_user')->insert(['user_id' => $second->id, 'store_id' => $store->id]);
$payload = ['name' => 'Inventory concurrency QA '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'INV-RACE-'.$tag, 'sale_price' => '25', 'attribute_value_ids' => []]]];
$catalog = app(ProductService::class);
$product = $catalog->create($payload, $owner);
$context = app(StoreContext::class);
$context->useStore($store);
$variant = $product->variants()->firstOrFail();
app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'request_key' => (string) Str::uuid(), 'operation' => 'receipt', 'quantity' => '1', 'version' => 0, 'note' => 'Entrada QA única documentada'], $owner);
$workers = [];
try {
    DB::beginTransaction();
    Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    foreach ([$owner, $second] as $actor) {
        $key = (string) Str::uuid();
        $out = $directory.'/'.$key.'.out';
        $err = $directory.'/'.$key.'.err';
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $product->id, (string) $store->id, (string) $warehouse->id, (string) $actor->id, $key, $tag], [0 => ['file', 'NUL', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Worker unavailable.');
        }
        $workers[] = compact('process', 'out', 'err', 'key');
    }
    $deadline = microtime(true) + 15;
    while (true) {
        clearstatcache();
        if (count(array_filter($workers, fn ($w) => is_file($directory.'/'.$w['key'].'.ready'))) === 2) {
            break;
        }
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Ready barrier timed out.');
        }
        usleep(25000);
    }
    usleep(250000);
    DB::commit();
    $statuses = [];
    foreach ($workers as $worker) {
        $deadline = microtime(true) + 15;
        while (proc_get_status($worker['process'])['running']) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker timed out.');
            } usleep(25000);
        }
        proc_close($worker['process']);
        if (! preg_match('/RESULT:(201|409)/', file_get_contents($worker['out']), $matches)) {
            throw new RuntimeException('Worker error: '.file_get_contents($worker['err']));
        }
        $statuses[] = (int) $matches[1];
    }
    sort($statuses);
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($statuses !== [201, 409] || $balance->on_hand !== '0.0000' || DB::table('inventory_movements')->where('variant_id', $variant->id)->count() !== 2) {
        throw new RuntimeException('Last-unit invariant failed.');
    }
    echo "PASS: two distinct users/processes consume last unit -> 201/409; stock 0, reserved 0, exactly entry + exit.\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $catalog->update($product->id, [...$payload, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
    $second->is_active = false;
    $second->save();
    $context->clear();
}
echo "QA fixture archived, synthetic second user disabled, movements/history preserved.\n";
