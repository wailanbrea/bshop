<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\BasketService;
use App\Domain\Sales\SalesService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/import-test-connection.php';
if (($argv[1] ?? '') === 'worker') {
    [$script,$mode,$productId,$orderId,$storeId,$actorId,$key,$tag] = $argv;
    if (! Str::isUuid($key) || ! preg_match('/^[A-F0-9]{8}$/D', $tag)) {
        throw new RuntimeException('Invalid worker identity.');
    }
    $actor = User::findOrFail($actorId);
    $context = app(StoreContext::class);
    $context->useStore(Store::findOrFail($storeId));
    $variant = Product::findOrFail($productId)->variants()->firstOrFail();
    $baskets = app(BasketService::class);
    $data = ['version' => 0, 'lines' => [['variant_id' => $variant->id, 'quantity' => '1']], 'shipping' => '0', 'discount' => '0'];
    $quote = $baskets->preview($context, (int) $orderId, $data, $actor);
    file_put_contents(base_path('.runtime/basket-race-'.$tag.'/'.$key.'.ready'), 'ready');
    try {
        $baskets->save($context, (int) $orderId, [...$data, 'request_key' => $key, 'quote_hash' => $quote['quote_hash']], $actor);
        echo "RESULT:200\n";
    } catch (HttpException $e) {
        echo 'RESULT:'.$e->getStatusCode()."\n";
    }exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(4)));
$directory = base_path('.runtime/basket-race-'.$tag);
if (! mkdir($directory, 0770, true) && ! is_dir($directory)) {
    throw new RuntimeException('QA artifacts unavailable.');
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
$customer = Customer::create(['full_name' => 'Basket concurrency QA '.$tag, 'notes' => 'Synthetic concurrent-edit fixture.']);
$second = User::factory()->create(['name' => 'Basket QA '.$tag, 'email' => 'basket-'.strtolower($tag).'@example.test']);
$second->roles()->attach(Role::where('code', 'manager')->value('id'));
DB::table('store_user')->insert(['user_id' => $second->id, 'store_id' => $store->id]);
$catalog = app(ProductService::class);
$payload = ['name' => 'Basket concurrency QA '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'BASKET-RACE-'.$tag, 'sale_price' => '25', 'attribute_value_ids' => []]]];
$product = $catalog->create($payload, $owner);
$variant = $product->variants()->firstOrFail();
$stock = app(InventoryService::class);
$movement = ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '1', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'QA last unit entry'];
$stock->move($context, $movement, $owner);
$baskets = app(BasketService::class);
$workers = [];
try {
    $orders = [];
    foreach ([$owner, $second] as $actor) {
        $orders[] = $baskets->create($context, ['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id], $actor)['order'];
    }
    DB::beginTransaction();
    Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    foreach ([$owner, $second] as $i => $actor) {
        $key = (string) Str::uuid();
        $out = $directory.'/'.$key.'.out';
        $err = $directory.'/'.$key.'.err';
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $product->id, (string) $orders[$i]['id'], (string) $store->id, (string) $actor->id, $key, $tag], [0 => ['file', 'NUL', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path());
        if (! is_resource($process)) {
            throw new RuntimeException('Worker unavailable.');
        }$workers[] = compact('process', 'out', 'err', 'key');
    }
    $deadline = microtime(true) + 15;
    while (true) {
        clearstatcache();
        if (count(array_filter($workers, fn ($w) => is_file($directory.'/'.$w['key'].'.ready'))) === 2) {
            break;
        }if (microtime(true) > $deadline) {
            throw new RuntimeException('Ready barrier timeout.');
        }usleep(25000);
    }usleep(250000);
    DB::commit();
    $statuses = [];
    foreach ($workers as $w) {
        $deadline = microtime(true) + 15;
        while (proc_get_status($w['process'])['running']) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Worker timeout.');
            }usleep(25000);
        }proc_close($w['process']);
        if (! preg_match('/RESULT:(200|409)/', file_get_contents($w['out']), $match)) {
            throw new RuntimeException('Worker failed: '.file_get_contents($w['err']));
        }$statuses[] = (int) $match[1];
    }
    sort($statuses);
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($statuses !== [200, 409] || $balance->on_hand !== '1.0000' || $balance->reserved !== '1.0000' || DB::table('stock_reservations')->where('variant_id', $variant->id)->where('status', 'active')->count() !== 1) {
        throw new RuntimeException('Concurrent edit overbooked stock.');
    }
    echo "PASS: two users editing different open baskets compete for last unit -> 200/409, exactly one reserved unit.\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }$sales = app(SalesService::class);
    foreach (SalesOrder::where('customer_id', $customer->id)->whereIn('status', ['draft', 'reserved'])->get() as $order) {
        $sales->change($context, $order->id, 'cancel', ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version], $owner);
    }
    $b = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($b && bccomp((string) $b->on_hand, '0', 4) > 0 && bccomp((string) $b->reserved, '0', 4) === 0) {
        $stock->move($context, [...$movement, 'operation' => 'issue', 'quantity' => (string) $b->on_hand, 'version' => (int) $b->version, 'request_key' => (string) Str::uuid(), 'note' => 'QA complete compensating exit'], $owner);
    }
    $catalog->update($product->id, [...$payload, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
    $second->is_active = false;
    $second->save();
    $customer->status = 'inactive';
    $customer->save();
    $context->clear();
}
echo "QA baskets cancelled, entry compensated, fixtures archived/inactivated; history preserved. No provider calls.\n";
