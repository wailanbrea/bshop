<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\BasketService;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Api\V1\BasketController;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
DB::beginTransaction();
try {
    BusinessProfile::current()->update(['default_currency' => 'USD']);
    $customer = Customer::create(['full_name' => 'Basket native QA', 'phone' => '+1 202-555-0195']);
    $product = app(ProductService::class)->create(['name' => 'Basket native article', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'BASKET-'.strtoupper(bin2hex(random_bytes(4))), 'sale_price' => '25', 'attribute_value_ids' => []]]], $owner);
    $variant = $product->variants()->firstOrFail();
    app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Native basket QA justified entry'], $owner);
    $baskets = app(BasketService::class);
    $sales = app(SalesService::class);
    $order = $baskets->create($context, ['request_key' => (string) Str::uuid(), 'warehouse_id' => $warehouse->id, 'customer_id' => $customer->id], $owner)['order'];
    $save = function (string $qty) use ($baskets, $context, $owner, $variant, &$order) {
        $data = ['version' => $order['lock_version'], 'lines' => [['variant_id' => $variant->id, 'quantity' => $qty]], 'shipping' => '0', 'discount' => '0'];
        $quote = $baskets->preview($context, $order['id'], $data, $owner);
        $order = $baskets->save($context, $order['id'], [...$data, 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash']], $owner)['order'];
    };
    $save('2');
    $order = $sales->change($context, $order['id'], 'payment', ['request_key' => (string) Str::uuid(), 'version' => $order['lock_version'], 'amount' => '20', 'payment_method' => 'cash'], $owner)['order'];
    $variant->sale_price = '99';
    $variant->save();
    $save('3');
    $save('1');
    $save('3');
    if ($order['grand_total'] !== '75.0000' || $order['paid_total'] !== '20.0000' || $order['pending_total'] !== '55.0000') {
        throw new RuntimeException('Native amount/deposit preservation failed.');
    }
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
    if ($balance->on_hand !== '10.0000' || $balance->reserved !== '3.0000') {
        throw new RuntimeException('Native reservation delta mismatch.');
    }
    $order = $baskets->number($context, $order['id'], ['request_key' => (string) Str::uuid(), 'version' => $order['lock_version'], 'number' => 'QA-'.strtoupper(bin2hex(random_bytes(4)))], $owner)['order'];
    $request = Request::create('/', 'GET', ['q' => $order['basket_number'], 'filter' => 'open']);
    $cards = app(BasketController::class)->index($request, $context)->getData(true)['data'];
    if (count($cards) !== 1 || $cards[0]['deposit'] !== '20.0000' || $cards[0]['quantity_count'] !== '3.0000') {
        throw new RuntimeException('Native board aggregation/filter mismatch.');
    }
    $order = $sales->change($context, $order['id'], 'invoice', ['request_key' => (string) Str::uuid(), 'version' => $order['lock_version']], $owner)['order'];
    if (! $order['closed_at'] || ! $order['invoice']) {
        throw new RuntimeException('Native basket close did not freeze/issue invoice.');
    }
    echo "PASS: MySQL board aggregation, rename, price snapshot, deposit20, reserve2->3->1->3 and single-invoice close.\n";
} finally {
    DB::rollBack();
    $context->clear();
}
echo "QA rows/profile rolled back. No HTTP, PDF file or WhatsApp request was performed.\n";
