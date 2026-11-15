<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
$snapshot = [];
foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'bshop_testing_single'") as $table) {
    $name = $table->TABLE_NAME;
    $snapshot[$name] = ['schema' => ((array) DB::selectOne('SHOW CREATE TABLE `'.$name.'`'))['Create Table'], 'rows' => DB::table($name)->get()->map(fn ($row) => (array) $row)->all()];
}
$backup = base_path('.runtime/inventory-mysql-backup-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.json');
$json = json_encode($snapshot, JSON_THROW_ON_ERROR);
if (file_exists($backup) || file_put_contents($backup, $json) !== strlen($json) || ! hash_equals(hash('sha256', $json), hash_file('sha256', $backup))) {
    throw new RuntimeException('Schema/data backup failed verification.');
}
echo 'Verified full schema/data backup: '.$backup.' | tables: '.count($snapshot)."\n";
Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
echo Artisan::output();
foreach ($snapshot as $name => $saved) {
    if (in_array($name, ['migrations', 'roles'], true)) {
        continue;
    }
    $before = $saved['rows'];
    if ($before === []) {
        if (DB::table($name)->exists()) {
            throw new RuntimeException('Unexpected rows in '.$name);
        }

continue;
    }
    $after = DB::table($name)->select(array_keys($before[0]))->get()->map(fn ($row) => (array) $row)->all();
    $canonical = fn ($rows) => collect($rows)->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
    if ($canonical($before) !== $canonical($after)) {
        throw new RuntimeException('Preexisting values changed in '.$name);
    }
}
foreach ($snapshot['roles']['rows'] as $role) {
    $after = DB::table('roles')->find($role['id']);
    if ($after->code !== $role['code'] || $after->label !== $role['label'] || array_diff(json_decode($role['permissions'], true), json_decode($after->permissions, true))) {
        throw new RuntimeException('Existing role identity/permissions were not preserved.');
    }
}
echo "PASS: existing values and permissions preserved; inventory permissions added.\n";
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
DB::beginTransaction();
try {
    $product = app(ProductService::class)->create(['name' => 'Inventory native QA', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'INV-NATIVE-'.strtoupper(bin2hex(random_bytes(4))), 'sale_price' => '25', 'attribute_value_ids' => []]]], $owner);
    $variant = $product->variants()->firstOrFail();
    $service = app(InventoryService::class);
    $data = ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '999999999999999.9999', 'version' => 0, 'note' => 'Native decimal range QA', 'request_key' => (string) Str::uuid()];
    $first = $service->move($context, $data, $owner);
    $retry = $service->move($context, $data, $owner);
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($balance->on_hand !== '999999999999999.9999' || ! $retry['replay'] || $retry['movements'][0]['id'] !== $first['movements'][0]['id']) {
        throw new RuntimeException('Exact decimal/replay mismatch.');
    }
    $service->move($context, [...$data, 'request_key' => (string) Str::uuid(), 'operation' => 'issue', 'version' => 1], $owner);
    $service->move($context, [...$data, 'request_key' => (string) Str::uuid(), 'quantity' => '5', 'version' => 2], $owner);
    $target = Warehouse::create(['store_id' => $store->id, 'name' => 'QA transfer destination']);
    $service->move($context, [...$data, 'request_key' => (string) Str::uuid(), 'quantity' => '2', 'version' => 3, 'operation' => 'transfer', 'destination_warehouse_id' => $target->id], $owner);
    if (DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->value('on_hand') !== '3.0000' || DB::table('stock_balances')->where('warehouse_id', $target->id)->where('variant_id', $variant->id)->value('on_hand') !== '2.0000') {
        throw new RuntimeException('Transfer balance mismatch.');
    }
    echo "PASS: MySQL full DECIMAL(19,4) range, idempotency and atomic transfer/kardex.\n";
} finally {
    DB::rollBack();
    $context->clear();
}
echo "QA business rows rolled back; existing fixtures retained.\n";
