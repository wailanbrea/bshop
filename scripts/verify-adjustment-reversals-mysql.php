<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\RefundReversals;
use App\Domain\Sales\ReturnAdjustmentReversals;
use App\Domain\Sales\ReturnSettlements;
use App\Domain\Sales\SalesReturnReceipts;
use App\Domain\Sales\SalesService;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
if (! Schema::hasTable('return_adjustment_reversals')) {
    throw new RuntimeException('Apply reviewed test migrations before this verification.');
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$context = app(StoreContext::class);
$context->useStore($store);
$tables = ['orders', 'payments', 'payment_allocations', 'customer_credit_movements', 'sales_returns', 'return_adjustments', 'return_adjustment_reversals', 'return_adjustment_revision_items', 'inventory_movements'];
$before = [];
foreach ($tables as $table) {
    $before[$table] = DB::table($table)->count();
}
$profileBefore = BusinessProfile::all()->toJson();
$check = function (bool $condition, string $message) {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
DB::beginTransaction();
try {
    BusinessProfile::current()->update(['default_currency' => 'USD']);
    DB::table('business_settings')->where('id', 1)->update(['sales_tax_rate' => '0']);
    $sales = app(SalesService::class);
    $receipts = app(SalesReturnReceipts::class);
    $settlements = app(ReturnSettlements::class);
    $reversals = app(ReturnAdjustmentReversals::class);
    $customer = Customer::create(['full_name' => 'Native adjustment reversal QA', 'phone' => '+1 202-555-0198']);
    $create = function (string $price, string $qty, string $shipping) use ($owner, $context, $warehouse, $customer, $sales) {
        $p = app(ProductService::class)->create(['name' => 'Adjustment native QA', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'ADJ-NATIVE-'.strtoupper(bin2hex(random_bytes(5))), 'sale_price' => $price, 'attribute_value_ids' => []]]], $owner);
        $variant = $p->variants()->firstOrFail();
        app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => '10', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Native QA stock fixture'], $owner);
        $data = ['warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $variant->id, 'quantity' => $qty]], 'shipping' => $shipping, 'discount' => '0'];
        $quote = $sales->quote($context, $data, $owner);

        return $sales->create($context, [...$data, 'quote_hash' => $quote['quote_hash'], 'request_key' => (string) Str::uuid(), 'mode' => 'sale', 'initial_payment' => $quote['grand_total'], 'payment_method' => 'cash'], $owner)['order'];
    };
    $receive = function (array $order) use ($receipts, $context, $owner) {
        $r = $receipts->info($context, $order['id']);

        return $receipts->receive($context, $order['id'], $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Native receipt fixture', 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])['sales_return_id'];
    };
    $adjust = function (int $order, int $receipt, string $shipping = '0') use ($settlements, $context, $owner) {
        $r = $settlements->info($context, $order, $receipt, ['shipping_credit' => $shipping])['proposal'];

        return $settlements->adjust($context, $order, $receipt, $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'shipping_credit' => $shipping, 'reason' => 'Native adjustment fixture']);
    };
    $undo = function (int $order, int $receipt, int $id) use ($reversals, $context, $owner) {
        $r = $reversals->info($context, $order, $receipt, $id);

        return $reversals->reverse($context, $order, $receipt, $id, $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Native adjustment compensation']);
    };
    $order = $create('10', '3', '5');
    $receipt = $receive($order);
    $a = $adjust($order['id'], $receipt);
    $payment = DB::table('order_payments')->where('order_id', $order['id'])->value('payment_id');
    $source = DB::table('return_adjustments')->where('id', $a['adjustment_id'])->first();
    $stock = DB::table('stock_balances')->get()->toJson();
    $allocations = DB::table('payment_allocations')->get()->toJson();
    $refund = $settlements->refund($context, $order['id'], $receipt, $owner, ['request_key' => (string) Str::uuid(), 'version' => $a['order']['lock_version'], 'payment_id' => $payment, 'amount' => '5', 'method' => 'cash', 'external_reference' => null, 'reason' => 'Deliberately erroneous native QA registration']);
    $check(! $reversals->info($context, $order['id'], $receipt, $a['adjustment_id'])['allowed'], 'Effective refund did not block adjustment compensation.');
    $refundService = app(RefundReversals::class);
    $review = $refundService->info($context, $order['id'], $receipt, $refund['refund_id']);
    $refundService->reverse($context, $order['id'], $receipt, $refund['refund_id'], $owner, ['request_key' => (string) Str::uuid(), 'version' => $review['version'], 'review_hash' => $review['review_hash'], 'reason' => 'No money was delivered in native QA', 'confirmed_no_money' => true]);
    $u = $undo($order['id'], $receipt, $a['adjustment_id']);
    $check($u['order']['net_total'] === '35.0000' && $u['order']['paid_total'] === '35.0000' && $u['order']['credit_available'] === '0.0000' && $u['order']['return_pending'], 'Native correction restored incorrect order totals.');
    $b = $adjust($order['id'], $receipt, '2');
    $check($b['adjustment_id'] !== $a['adjustment_id'] && $b['order']['net_total'] === '23.0000' && $b['order']['credit_available'] === '12.0000', 'Native revision or shipping budget failed.');
    $check((array) $source === (array) DB::table('return_adjustments')->find($a['adjustment_id']), 'Original adjustment changed.');
    $check($stock === DB::table('stock_balances')->get()->toJson() && $allocations === DB::table('payment_allocations')->get()->toJson(), 'Compensation changed stock or original allocations.');
    $check((int) DB::table('return_adjustment_heads')->where('sales_return_id', $receipt)->value('active_adjustment_id') === $b['adjustment_id'], 'Native active revision pointer failed.');
    $large = $create('99999999999999.99', '2', '0');
    $r = $receive($large);
    $a = $adjust($large['id'], $r);
    $check($a['order']['return_credit_total'] === '99999999999999.9900', 'Native DECIMAL credit lost precision.');
    $u = $undo($large['id'], $r, $a['adjustment_id']);
    $check($u['order']['net_total'] === '199999999999999.9800' && $u['order']['paid_total'] === '199999999999999.9800', 'Native extreme compensation lost precision.');
    $check(DB::transactionLevel() === 1, 'QA transaction was committed unexpectedly.');
    echo "PASS: native MySQL adjustments/revision, erroneous refund correction, original allocation/stock preservation and extreme DECIMAL compensation.\n";
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }$context->clear();
}
foreach ($before as $table => $count) {
    $check(DB::table($table)->count() === $count, 'QA rows remained in '.$table);
}
$check($profileBefore === BusinessProfile::all()->toJson(), 'QA changed the existing business profile.');
echo "PASS: all QA rows/profile changes rolled back. No HTTP, PDF file or bank/WhatsApp request. Concurrency was not simulated.\n";
