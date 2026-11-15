<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\SalesService;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/mysql-migration-check.php';
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
DB::beginTransaction();
try {
    BusinessProfile::current()->update(['default_currency' => 'USD']); // Test-local transaction only; original fixture profile restored by rollback.
    $customer = Customer::create(['full_name' => 'Cliente nativo QA', 'phone' => '+1 202-555-0191', 'notes' => 'Synthetic transaction fixture']);
    $ids = [];
    $tag = strtoupper(bin2hex(random_bytes(4)));
    foreach (['CAM' => '25', 'JEA' => '45', 'GAF' => '22', 'PER' => '49'] as $name => $price) {
        $product = app(ProductService::class)->create(['name' => 'Sales native '.$name, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'SALES-'.$tag.'-'.$name, 'sale_price' => $price, 'attribute_value_ids' => []]]], $owner);
        $variant = $product->variants()->firstOrFail();
        $ids[] = $variant->id;
        app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '3', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Entrada de QA documentada'], $owner);
    }
    $sales = app(SalesService::class);
    $data = ['warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'lines' => array_map(fn ($id) => ['variant_id' => $id, 'quantity' => '1'], $ids), 'discount' => '0', 'shipping' => '5'];
    $quote = $sales->quote($context, $data, $owner);
    $payload = [...$data, 'mode' => 'reservation', 'initial_payment' => '50', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']];
    $result = $sales->create($context, $payload, $owner);
    $again = $sales->create($context, $payload, $owner);
    $id = $result['order']['id'];
    if ($result['order']['grand_total'] !== '146.0000' || $result['order']['pending_total'] !== '96.0000' || ! $again['replay'] || $again['order']['id'] !== $id) {
        throw new RuntimeException('Native quote/reserve/payment/retry failed.');
    }
    $sales->change($context, $id, 'invoice', ['request_key' => (string) Str::uuid(), 'version' => 0, 'deliver_now' => false], $owner);
    $sales->change($context, $id, 'payment', ['request_key' => (string) Str::uuid(), 'version' => 1, 'amount' => '96', 'payment_method' => 'cash'], $owner);
    $final = $sales->change($context, $id, 'deliver', ['request_key' => (string) Str::uuid(), 'version' => 2], $owner);
    foreach (DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->whereIn('variant_id', $ids)->get() as $balance) {
        if ($balance->on_hand !== '2.0000' || $balance->reserved !== '0.0000') {
            throw new RuntimeException('Native reservation fulfillment mismatch.');
        }
    }
    if ($final['order']['status'] !== 'fulfilled' || $final['order']['pending_total'] !== '0.0000' || DB::table('invoices')->where('order_id', $id)->count() !== 1) {
        throw new RuntimeException('Native invoice/payment mismatch.');
    }
    echo "PASS: MySQL USD total146/deposit50/pending96, idempotent reserve, one invoice, final payment and fulfillment without double stock deduction.\n";
} finally {
    DB::rollBack();
    $context->clear();
}
echo "Sales QA rows/profile changes rolled back; original fixtures and currencies preserved.\n";
