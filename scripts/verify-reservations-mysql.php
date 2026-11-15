<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\BasketService;
use App\Domain\Sales\ReservationExpiry;
use App\Domain\Sales\SalesService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Guard before any QA writes; no migrations, global settings changes or truncation.
try {
    require __DIR__.'/import-test-connection.php';
} catch (Throwable $error) {
    fwrite(STDERR, "BLOCKED: isolated MySQL connection or identity check failed. No QA writes started.\n");
    exit(2);
}

function reservationQaAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function reservationQaContext(int $id): StoreContext
{
    $context = new StoreContext;
    $context->useStore(Store::findOrFail($id));

    return $context;
}

function reservationQaWait(callable $ready): void
{
    $until = microtime(true) + 12;
    do {
        clearstatcache();
        if ($ready()) {
            return;
        }
        usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('QA barrier timed out.');
}

function reservationQaWorker(string $directory, string $name, array $fixture, string $action): array
{
    $manifest = $directory.'/'.$name.'.json';
    file_put_contents($manifest, json_encode([...$fixture, 'action' => $action], JSON_THROW_ON_ERROR));
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', basename($directory), $name], [0 => ['file', 'NUL', 'r'], 1 => ['file', $directory.'/'.$name.'.out', 'w'], 2 => ['file', $directory.'/'.$name.'.err', 'w']], $pipes, base_path());
    reservationQaAssert(is_resource($process), 'QA worker unavailable.');

    return ['process' => $process, 'base' => $directory.'/'.$name];
}

function reservationQaResult(array $worker): array
{
    reservationQaWait(fn () => ! proc_get_status($worker['process'])['running']);
    proc_close($worker['process']);
    $output = file_get_contents($worker['base'].'.out');
    reservationQaAssert((bool) preg_match('/^RESULT:(.+)$/m', $output, $match), 'Worker failed; inspect its private .out/.err artifacts.');

    return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
}

function reservationQaRow(array $fixture): array
{
    $report = app(InventoryReconciliation::class)->inspect($fixture['store'], $fixture['warehouse']);
    $row = collect($report['data'])->firstWhere('variant_id', $fixture['variant']);
    reservationQaAssert($row !== null && $row['differences'] === [], 'Fixture stock does not reconcile.');

    return $row;
}

if (($argv[1] ?? '') === 'worker') {
    try {
        reservationQaAssert((bool) preg_match('/^reservation-qa-[A-F0-9]{12}$/D', $argv[2] ?? ''), 'Invalid QA directory.');
        reservationQaAssert((bool) preg_match('/^[a-z0-9-]{1,40}$/D', $argv[3] ?? ''), 'Invalid QA worker name.');
        $base = base_path('.runtime/'.$argv[2].'/'.$argv[3]);
        $fixture = json_decode(file_get_contents($base.'.json'), true, flags: JSON_THROW_ON_ERROR);
        reservationQaAssert(str_starts_with(Product::findOrFail($fixture['product'])->name, 'Reservation QA '.substr($argv[2], -12)), 'Fixture identity mismatch.');
        $action = $fixture['action'];
        if ($action === 'read') {
            $connection = DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            file_put_contents($base.'.ready', (string) $connection);
            $result = ['row' => reservationQaRow($fixture)];
        } else {
            reservationQaAssert(in_array($action, ['expire', 'payment'], true), 'Invalid worker operation.');
            // Signal only AFTER the first consistent order read has established its snapshot.
            DB::listen(function (QueryExecuted $query) use ($base) {
                if (str_contains(strtolower($query->sql), 'from `orders`') && ! file_exists($base.'.ready')) {
                    file_put_contents($base.'.ready', 'snapshot-established');
                }
            });
            if ($action === 'expire') {
                $result = ['status' => app(ReservationExpiry::class)->inspect($fixture['order'], CarbonImmutable::now('UTC'), true)];
            } else {
                try {
                    app(SalesService::class)->change(reservationQaContext($fixture['store']), $fixture['order'], 'payment', ['request_key' => (string) Str::uuid(), 'version' => 0, 'amount' => '1', 'payment_method' => 'cash'], User::findOrFail($fixture['actor']));
                    $result = ['status' => 200];
                } catch (HttpException $error) {
                    $result = ['status' => $error->getStatusCode()];
                }
            }
        }
        echo 'RESULT:'.json_encode($result, JSON_THROW_ON_ERROR)."\n";
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'QA worker failed: '.$error::class."\n");
        exit(1);
    }
}

$workers = [];
try {
    reservationQaAssert(! isset($argv[1]), 'Only default verification or a guarded worker is allowed.');
    $tag = strtoupper(bin2hex(random_bytes(6)));
    $directory = base_path('.runtime/reservation-qa-'.$tag);
    reservationQaAssert(mkdir($directory, 0770, true), 'QA directory unavailable.');
    $owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
    $store = Store::whereIn('id', DB::table('store_user')->where('user_id', $owner->id)->select('store_id'))->where('is_active', true)->firstOrFail();
    $warehouse = Warehouse::where('store_id', $store->id)->where('is_active', true)->firstOrFail();
    $context = reservationQaContext($store->id);
    $sales = app(SalesService::class);
    $make = function (string $case, string $physical = '10') use ($tag, $owner, $store, $warehouse, $context, $sales) {
        $product = app(ProductService::class)->create(['name' => 'Reservation QA '.$tag.' '.$case, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'RSV-'.$tag.'-'.$case, 'sale_price' => '1', 'attribute_value_ids' => []]]], $owner);
        $variant = $product->variants()->firstOrFail();
        $customer = Customer::create(['full_name' => 'Reservation QA '.$tag.' '.$case, 'notes' => 'Synthetic isolated MySQL fixture.']);
        app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'receipt', 'quantity' => $physical, 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic reservation concurrency QA'], $owner);
        $input = ['warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $variant->id, 'quantity' => '1.0001']], 'discount' => '0', 'shipping' => '0'];
        $quote = $sales->quote($context, $input, $owner);
        $order = $sales->create($context, [...$input, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash', 'quote_hash' => $quote['quote_hash'], 'request_key' => (string) Str::uuid()], $owner)['order'];
        // Explicit past deadline belongs only to this newly-created synthetic order.
        DB::table('stock_reservations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $order['id'])->select('id'))->update(['expires_at' => now()->subHour()]);

        return ['product' => $product->id, 'variant' => $variant->id, 'order' => $order['id'], 'store' => $store->id, 'warehouse' => $warehouse->id, 'actor' => $owner->id];
    };
    foreach (['payment', 'delivery', 'edit'] as $case) {
        $fixture = $make($case);
        DB::beginTransaction();
        Product::whereKey($fixture['product'])->lockForUpdate()->firstOrFail();
        $worker = reservationQaWorker($directory, $case, $fixture, 'expire');
        $workers[] = $worker;
        reservationQaWait(fn () => file_exists($worker['base'].'.ready'));
        if ($case === 'payment') {
            $sales->change($context, $fixture['order'], 'payment', ['request_key' => (string) Str::uuid(), 'version' => 0, 'amount' => '1', 'payment_method' => 'cash'], $owner);
        } elseif ($case === 'delivery') {
            $item = SalesOrder::findOrFail($fixture['order'])->items()->firstOrFail();
            $sales->change($context, $fixture['order'], 'deliver', ['request_key' => (string) Str::uuid(), 'version' => 0, 'item_id' => $item->id, 'quantity' => '0.0001'], $owner);
        } else {
            $body = ['version' => 0, 'lines' => [['variant_id' => $fixture['variant'], 'quantity' => '2.0001']], 'shipping' => '0', 'discount' => '0'];
            $quote = app(BasketService::class)->preview($context, $fixture['order'], $body, $owner);
            app(BasketService::class)->save($context, $fixture['order'], [...$body, 'quote_hash' => $quote['quote_hash'], 'request_key' => (string) Str::uuid()], $owner);
        }
        DB::commit();
        $result = reservationQaResult($worker);
        array_pop($workers);
        reservationQaAssert($result['status'] === ($case === 'edit' ? 'expired' : 'protected'), 'Expiry ignored a concurrent '.$case.'.');
        $row = reservationQaRow($fixture);
        reservationQaAssert($row['reserved'] === ($case === 'payment' ? '1.0001' : ($case === 'delivery' ? '1.0000' : '0.0000')), 'Concurrent operation lost reserved stock.');
        echo 'PASS: expiry rechecks concurrent '.$case." after an older snapshot.\n";
    }
    $fixture = $make('expiry-first');
    DB::beginTransaction();
    reservationQaAssert(app(ReservationExpiry::class)->inspect($fixture['order'], CarbonImmutable::now('UTC'), true) === 'expired', 'Fixture not expired.');
    $worker = reservationQaWorker($directory, 'expiry-first', $fixture, 'payment');
    $workers[] = $worker;
    reservationQaWait(fn () => file_exists($worker['base'].'.ready'));
    DB::commit();
    reservationQaAssert(reservationQaResult($worker)['status'] === 409, 'A payment was accepted after committed expiry.');
    array_pop($workers);
    reservationQaAssert(! DB::table('order_payments')->where('order_id', $fixture['order'])->exists(), 'Cancelled fixture received money.');
    reservationQaRow($fixture);
    echo "PASS: expiry wins; waiting payment gets 409 with no payment created.\n";

    $fixture = $make('double-expiry');
    DB::beginTransaction();
    Product::whereKey($fixture['product'])->lockForUpdate()->firstOrFail();
    foreach (['double-a', 'double-b'] as $name) {
        $workers[] = reservationQaWorker($directory, $name, $fixture, 'expire');
    }
    reservationQaWait(fn () => count(array_filter($workers, fn ($w) => file_exists($w['base'].'.ready'))) === 2);
    DB::commit();
    $results = array_map(fn ($w) => reservationQaResult($w)['status'], $workers);
    $workers = [];
    sort($results);
    reservationQaAssert($results === ['expired', 'skipped'], 'Concurrent expiry was not idempotent.');
    reservationQaAssert(DB::table('inventory_movements')->where('variant_id', $fixture['variant'])->where('movement_type', 'expire')->count() === 1, 'Duplicate expiry movement.');
    reservationQaRow($fixture);
    echo "PASS: two expiry workers create one release.\n";

    $fixture = $make('reader');
    DB::beginTransaction();
    Product::whereKey($fixture['product'])->lockForUpdate()->firstOrFail();
    Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
    $worker = reservationQaWorker($directory, 'reader', $fixture, 'read');
    $workers[] = $worker;
    reservationQaWait(fn () => file_exists($worker['base'].'.ready'));
    $connection = (int) file_get_contents($worker['base'].'.ready');
    reservationQaWait(function () use ($connection) {
        return collect(DB::select('SHOW FULL PROCESSLIST'))->contains(fn ($p) => (int) $p->Id === $connection && str_contains(strtolower($p->Info ?? ''), 'from `stores`'));
    });
    app(InventoryService::class)->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $fixture['variant'], 'operation' => 'receipt', 'quantity' => '0.0001', 'version' => 2, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic concurrent receipt'], $owner);
    DB::commit();
    $result = reservationQaResult($worker);
    array_pop($workers);
    reservationQaAssert($result['row']['physical'] === '10.0001' && $result['row']['reserved'] === '1.0001', 'Reader missed the completed writer.');
    echo "PASS: reconciliation waits for the writer and sees one consistent committed state.\n";

    DB::beginTransaction();
    try {
        $fixture = $make('extreme', '999999999999999.9999');
        reservationQaAssert(reservationQaRow($fixture)['physical'] === '999999999999999.9999', 'DECIMAL(19,4) lost precision.');
        reservationQaAssert(app(ReservationExpiry::class)->inspect($fixture['order'], CarbonImmutable::now('UTC'), true) === 'expired', 'Extreme fixture failed expiry.');
        $row = reservationQaRow($fixture);
        reservationQaAssert($row['physical'] === '999999999999999.9999' && $row['reserved'] === '0.0000', 'Extreme expiry changed physical stock.');
    } finally {
        DB::rollBack();
    }
    echo "PASS: full DECIMAL(19,4), fractional reservation, expiry and reconciliation; extreme fixture rolled back.\n";
    echo 'Artifacts and committed synthetic race fixtures retained: '.$directory."\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: '.$error->getMessage()."\n");
    $failed = true;
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
exit(isset($failed) ? 1 : 0);
