<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
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
    [$script, $mode, $productId, $storeId, $warehouseId, $customerId, $actorId, $key, $tag] = $argv;
    if (! Str::isUuid($key) || ! preg_match('/^[A-F0-9]{8}$/D', $tag)) {
        throw new RuntimeException('Invalid worker identity.');
    }
    $actor = User::findOrFail($actorId);
    $context = app(StoreContext::class);
    $context->useStore(Store::findOrFail($storeId));
    $variant = Product::findOrFail($productId)->variants()->firstOrFail();
    $sales = app(SalesService::class);
    $data = ['warehouse_id' => (int) $warehouseId, 'customer_id' => (int) $customerId, 'lines' => [['variant_id' => $variant->id, 'quantity' => '1']], 'discount' => '0', 'shipping' => '0'];
    $quote = $sales->quote($context, $data, $actor);
    file_put_contents(base_path('.runtime/sales-race-'.$tag.'/'.$key.'.ready'), 'ready');
    try {
        $result = $sales->create($context, [...$data, 'mode' => 'reservation', 'request_key' => $key, 'quote_hash' => $quote['quote_hash'], 'initial_payment' => '0', 'payment_method' => 'cash'], $actor);
        echo 'RESULT:201:'.$result['order']['id']."\n";
    } catch (HttpException $error) {
        echo 'RESULT:'.$error->getStatusCode()."\n";
    }
    exit(0);
}
$tag = strtoupper(bin2hex(random_bytes(4)));
$directory = base_path('.runtime/sales-race-'.$tag);
if (! mkdir($directory, 0770, true) && ! is_dir($directory)) {
    throw new RuntimeException('QA artifacts unavailable.');
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
$sales = app(SalesService::class);
$customer = Customer::create(['full_name' => 'Sales concurrency QA '.$tag, 'notes' => 'Synthetic last-unit reservation test; no actual customer.']);
$second = User::factory()->create(['name' => 'Sales QA '.$tag, 'email' => 'sales-'.strtolower($tag).'@example.test']);
$second->roles()->attach(Role::where('code', 'manager')->value('id'));
DB::table('store_user')->insert(['user_id' => $second->id, 'store_id' => $store->id]);
$payload = ['name' => 'Sales concurrency QA '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'SALE-RACE-'.$tag, 'sale_price' => '25', 'attribute_value_ids' => []]]];
$catalog = app(ProductService::class);
$product = $catalog->create($payload, $owner);
$variant = $product->variants()->firstOrFail();
$stock = app(InventoryService::class);
$movement = ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '1', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'QA: entrada justificada para competencia de última unidad'];
$stock->move($context, $movement, $owner);
$workers = [];
try {
    DB::beginTransaction();
    Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
    foreach ([$owner, $second] as $actor) {
        $key = (string) Str::uuid();
        $out = $directory.'/'.$key.'.out';
        $err = $directory.'/'.$key.'.err';
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $product->id, (string) $store->id, (string) $warehouse->id, (string) $customer->id, (string) $actor->id, $key, $tag], [0 => ['file', 'NUL', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path());
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
        } if (microtime(true) > $deadline) {
            throw new RuntimeException('Ready barrier timed out.');
        } usleep(25000);
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
    if ($statuses !== [201, 409] || $balance->on_hand !== '1.0000' || $balance->reserved !== '1.0000' || SalesOrder::where('customer_id', $customer->id)->count() !== 1 || DB::table('stock_reservations')->where('variant_id', $variant->id)->where('status', 'active')->count() !== 1) {
        throw new RuntimeException('Last-unit reservation invariant failed.');
    }
    echo "PASS: two distinct users/processes reserve last unit -> 201/409; physical1, reserved1, exactly one order/reservation.\n";
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach (SalesOrder::where('customer_id', $customer->id)->where('status', 'reserved')->get() as $order) {
        $sales->change($context, $order->id, 'cancel', ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version], $owner);
    }
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($balance && bccomp((string) $balance->on_hand, '0', 4) > 0 && bccomp((string) $balance->reserved, '0', 4) === 0) {
        $stock->move($context, [...$movement, 'operation' => 'issue', 'quantity' => (string) $balance->on_hand, 'version' => (int) $balance->version, 'request_key' => (string) Str::uuid(), 'note' => 'QA finalizado: salida compensatoria documentada'], $owner);
    }
    $catalog->update($product->id, [...$payload, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
    $second->is_active = false;
    $second->save();
    $customer->status = 'inactive';
    $customer->save();
    $context->clear();
}
echo "QA reservations released, compensating exit recorded, product/customer archived and synthetic second user disabled; history preserved.\n";
