<?php

use App\Domain\Business\IdempotentAction;
use App\Domain\Business\StoreAuthorizationService;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Models\AttributeValue;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/import-test-connection.php';

function productQaAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function productQaCreate(array $body, User $owner)
{
    $request = Request::create('/api/v1/business/products', 'POST', $body);
    $request->headers->set('Accept', 'application/json');
    $request->setUserResolver(fn () => $owner);

    return app(CatalogController::class)->create($request, app(ProductService::class), app(IdempotentAction::class), app(InventoryService::class), app(StoreAuthorizationService::class));
}

$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
$warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
$tag = strtoupper(bin2hex(random_bytes(6)));
DB::beginTransaction();
try {
    $color = ProductAttribute::create(['name' => 'Color QA '.$tag, 'is_active' => true]);
    $green = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Verde', 'is_active' => true]);
    $black = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Negro', 'is_active' => true]);
    $body = ['name' => 'Producto inicial QA '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'request_key' => (string) Str::uuid(),
        'variants' => [['sku' => 'INIT-'.$tag.'-V', 'sale_price' => '25', 'attribute_value_ids' => [$green->id]], ['sku' => 'INIT-'.$tag.'-N', 'sale_price' => '25', 'attribute_value_ids' => [$black->id]]],
        'initial_stock' => ['store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'items' => [['sku' => 'INIT-'.$tag.'-V', 'quantity' => '5', 'request_key' => (string) Str::uuid()]]]];
    $first = productQaCreate($body, $owner);
    $second = productQaCreate($body, $owner);
    $id = $first->getData(true)['data']['id'];
    productQaAssert($first->getStatusCode() === 201 && $second->getStatusCode() === 200 && $second->getData(true)['data']['id'] === $id, 'Product replay failed.');
    $variants = ProductVariant::where('product_id', $id)->pluck('id', 'sku');
    $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variants['INIT-'.$tag.'-V'])->first();
    productQaAssert($balance && bccomp((string) $balance->on_hand, '5', 4) === 0 && bccomp((string) $balance->reserved, '0', 4) === 0, 'Initial stock mismatch.');
    productQaAssert(! DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variants['INIT-'.$tag.'-N'])->exists(), 'Omitted variant unexpectedly received stock.');
    productQaAssert(DB::table('inventory_movements')->where('variant_id', $variants['INIT-'.$tag.'-V'])->where('movement_type', 'receipt')->count() === 1, 'Initial receipt was duplicated.');
    $bad = $body;
    $bad['name'] = 'Producto fallido QA '.$tag;
    $bad['request_key'] = (string) Str::uuid();
    $bad['variants'][0]['sku'] = 'BAD-'.$tag.'-V';
    $bad['variants'][1]['sku'] = 'BAD-'.$tag.'-N';
    $bad['initial_stock']['items'] = [['sku' => 'BAD-'.$tag.'-V', 'quantity' => '2', 'request_key' => (string) Str::uuid()], ['sku' => 'MISSING-'.$tag, 'quantity' => '1', 'request_key' => (string) Str::uuid()]];
    try {
        productQaCreate($bad, $owner);
        throw new RuntimeException('Invalid initial stock was accepted.');
    } catch (ValidationException) {
        productQaAssert(! DB::table('products')->where('name', $bad['name'])->exists(), 'Failed product was not rolled back.');
        productQaAssert(! DB::table('product_variants')->where('sku', 'BAD-'.$tag.'-V')->exists(), 'Failed variant was not rolled back.');
    }
    echo "PASS: MySQL new product and variant stock are atomic and replay without duplicate movements; failed batch rolled back.\n";
} finally {
    DB::rollBack();
}
echo "QA transaction rolled back; synthetic product and stock were not retained.\n";
