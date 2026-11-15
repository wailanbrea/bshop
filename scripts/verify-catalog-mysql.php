<?php

use App\Domain\Catalog\ProductService;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$credentials = json_decode(file_get_contents($root.'/.runtime/mysql-test-credentials.json'), true, flags: JSON_THROW_ON_ERROR);
echo "Catalog verification: testing; mysql; 127.0.0.1:33078; bshop_testing_single.\n";
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$app->detectEnvironment(fn () => 'testing');
config(['app.env' => 'testing', 'database.default' => 'mysql', 'database.connections.mysql.url' => null, 'database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 33078, 'database.connections.mysql.database' => 'bshop_testing_single', 'database.connections.mysql.username' => $credentials['user'], 'database.connections.mysql.password' => $credentials['password']]);
DB::purge('mysql');
$server = DB::selectOne('SELECT DATABASE() AS db, VERSION() AS version, @@port AS port, @@bind_address AS bind_address, @@datadir AS directory');
if ($server->db !== 'bshop_testing_single' || ! str_starts_with($server->version, '8.4.') || (int) $server->port !== 33078 || $server->bind_address !== '127.0.0.1' || strcasecmp(rtrim(str_replace('\\', '/', $server->directory), '/'), str_replace('\\', '/', realpath($root.'/.runtime/mysql-data'))) !== 0) {
    throw new RuntimeException('Unexpected database destination.');
}
$backup = $root.'/.runtime/catalog-test-role-backup-'.gmdate('Ymd-His').'.json';
if (file_exists($backup)) {
    throw new RuntimeException('Backup already exists.');
}
file_put_contents($backup, json_encode(DB::table('roles')->get(), JSON_THROW_ON_ERROR));
if (count(json_decode(file_get_contents($backup), true, flags: JSON_THROW_ON_ERROR)) !== DB::table('roles')->count()) {
    throw new RuntimeException('Role backup verification failed.');
}
echo "Verified role backup: $backup\n";
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
DB::beginTransaction();
try {
    $owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
    $sku = 'MYSQL-CAT-'.strtoupper(bin2hex(random_bytes(4)));
    $service = app(ProductService::class);
    $product = $service->create(['name' => 'Catalog native precision QA', 'product_type' => 'physical', 'unit_code' => 'unit', 'track_stock' => true, 'status' => 'active', 'variants' => [['sku' => $sku, 'sale_price' => '999999999999999.9999', 'cost_price' => '0.0001', 'attribute_value_ids' => []]]], $owner);
    $variant = $product->variants()->firstOrFail();
    if ($variant->sale_price !== '999999999999999.9999' || $variant->cost_price !== '0.0001') {
        throw new RuntimeException('Native decimal precision failed.');
    }
    echo "PASS: native DECIMAL(19,4) preserves full range and four-decimal cost.\n";
    $store = DB::table('stores')->value('id');
    $customer = DB::table('customers')->value('id');
    $order = DB::table('orders')->insertGetId(['store_id' => $store, 'customer_id' => $customer, 'currency' => 'DOP']);
    $item = DB::table('order_items')->insertGetId(['order_id' => $order, 'store_id' => $store, 'variant_id' => $variant->id, 'quantity' => '1.0000', 'unit_price' => $variant->sale_price, 'line_total' => $variant->sale_price, 'product_name_snapshot' => $product->name, 'sku_snapshot' => $sku]);
    $service->updateVariant($product->id, $variant->id, ['sku' => $sku, 'sale_price' => '1.0000', 'cost_price' => '0.0001', 'is_active' => true, 'version' => 0], $owner);
    if (DB::table('order_items')->where('id', $item)->value('unit_price') !== '999999999999999.9999' || ProductVariant::findOrFail($variant->id)->sale_price !== '1.0000') {
        throw new RuntimeException('Price snapshot changed.');
    }
    echo "PASS: changing current price does not alter historical order item.\n";
    $blocked = false;
    try {
        $service->create(['name' => 'Duplicate QA', 'product_type' => 'physical', 'unit_code' => 'unit', 'track_stock' => true, 'status' => 'active', 'variants' => [['sku' => $sku, 'sale_price' => '1', 'attribute_value_ids' => []]]], $owner);
    } catch (ValidationException) {
        $blocked = true;
    }
    if (! $blocked) {
        throw new RuntimeException('Native unique SKU was not enforced.');
    }
    echo "PASS: native unique SKU backstop rejects duplicate transaction.\n";
} finally {
    DB::rollBack();
}
echo "Catalog QA data rolled back; existing test fixtures retained.\n";
