<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\CreditApplications;
use App\Domain\Sales\Money;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\RefundState;
use App\Domain\Sales\ReturnSettlements;
use App\Domain\Sales\SalesReturnReceipts;
use App\Domain\Sales\SalesService;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
require __DIR__.'/financial-concurrency-support.php';
$check = FinancialConcurrencyOperations::check(...);
$rounds = 2;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--preflight') {
        continue;
    }
    $check((bool) preg_match('/^--rounds=([1-5])$/D', $argument, $match), 'Use --preflight or --rounds=1..5.');
    $rounds = (int) $match[1];
}
$check(app()->environment('testing') && DB::getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'bshop_testing_single', 'Financial races require the dedicated test connection.');
foreach (['return_adjustment_heads', 'return_adjustment_reversals', 'refund_reversals', 'credit_application_reversals', 'return_receipt_reversal_items'] as $table) {
    $check(Schema::hasTable($table), 'Apply reviewed QA migrations first: '.$table);
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$manager = Role::where('code', 'manager')->firstOrFail();
foreach (['sales.create', 'payments.record', 'credits.apply', 'credits.reverse', 'returns.receive', 'returns.adjust', 'returns.reverse', 'refunds.record', 'refunds.reverse', 'adjustments.reverse'] as $permission) {
    $check($owner->permits($permission) && in_array($permission, $manager->permissions, true), 'QA actors lack '.$permission);
}
$parentConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$visible = collect(DB::select('SHOW FULL PROCESSLIST'))->contains(fn ($row) => (int) ((array) $row)['Id'] === $parentConnection);
$check($visible, 'Own SQL sessions must be observable; no unverified timing-only races.');
if (in_array('--preflight', $argv, true)) {
    echo "PASS: protected MySQL 8.4 test destination, required schema/permissions and own-session visibility. No fixtures created.\n";
    exit(0);
}
$check((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['bshop:financial-concurrency-qa'])->acquired === 1, 'Another financial QA runner is active.');
$tag = strtoupper(bin2hex(random_bytes(6)));
$directory = FinancialConcurrencyOperations::directory($tag);
$check(! file_exists($directory) && mkdir($directory, 0770), 'Cannot create unique financial QA artifact directory.');
$report = ['tag' => $tag, 'destination' => 'testing/mysql/127.0.0.1:33078/bshop_testing_single', 'started_at' => gmdate(DATE_ATOM), 'rounds' => $rounds, 'cases' => [], 'failures' => []];
$snapshot = [];
$store = $warehouse = $second = $product = $variant = null;
$customers = [];
$workers = [];
$context = app(StoreContext::class);
$catalog = app(ProductService::class);
$stock = app(InventoryService::class);
$sales = app(SalesService::class);
$checkProcess = function (array &$worker): bool {
    $status = proc_get_status($worker['process']);
    if (! $status['running'] && $status['exitcode'] >= 0) {
        $worker['exit_code'] = $status['exitcode'];
    }

    return $status['running'];
};
try {
    $snapshot = DB::transaction(function () {
        $saved = [];
        foreach (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='bshop_testing_single' AND TABLE_TYPE='BASE TABLE'") as $row) {
            $name = $row->TABLE_NAME;
            FinancialConcurrencyOperations::check((bool) preg_match('/^[a-z][a-z0-9_]*$/D', $name), 'Unexpected QA table name.');
            $saved[$name] = ['schema' => ((array) DB::selectOne('SHOW CREATE TABLE `'.$name.'`'))['Create Table'], 'rows' => DB::table($name)->get()->map(fn ($r) => (array) $r)->all()];
        }

        return $saved;
    });
    FinancialConcurrencyOperations::writeJson($directory.'/backup.json', $snapshot);
    $backupHash = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $check(hash_equals($backupHash, hash_file('sha256', $directory.'/backup.json')), 'QA backup checksum mismatch.');
    $report['backup_sha256'] = $backupHash;
    echo 'Verified full schema/data backup: '.$directory.'/backup.json | tables: '.count($snapshot).PHP_EOL;
    [$store,$warehouse,$second,$product,$variant,$catalogPayload] = DB::transaction(function () use ($tag, $owner, $manager, $context, $catalog, $stock) {
        $s = Store::create(['name' => 'Synthetic finance QA '.$tag, 'code' => 'QA-FIN-'.$tag, 'is_active' => true]);
        $w = Warehouse::create(['store_id' => $s->id, 'name' => 'Synthetic financial race warehouse', 'is_active' => true, 'is_default' => true]);
        $actor = User::factory()->create(['name' => 'Finance QA '.$tag, 'email' => 'finance-'.strtolower($tag).'@example.test']);
        $actor->roles()->attach($manager->id);
        DB::table('store_user')->insert([['store_id' => $s->id, 'user_id' => $owner->id], ['store_id' => $s->id, 'user_id' => $actor->id]]);
        $context->useStore($s);
        $payload = ['name' => 'Synthetic financial race product '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'FIN-RACE-'.$tag, 'sale_price' => '10', 'attribute_value_ids' => []]]];
        $p = $catalog->create($payload, $owner);
        $v = $p->variants()->firstOrFail();
        $stock->move($context, ['warehouse_id' => $w->id, 'variant_id' => $v->id, 'operation' => 'receipt', 'quantity' => '1000', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic finance QA initial stock '.$tag], $owner);

        return [$s, $w, $actor, $p, $v, $payload];
    });
    $report['fixtures'] = ['store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'actor_id' => $second->id, 'product_id' => $product->id, 'variant_id' => $variant->id];
    FinancialConcurrencyOperations::writeJson($directory.'/manifest.json', ['tag' => $tag, 'owner_id' => $owner->id, ...$report['fixtures']]);
    $sale = function (Customer $customer, string $qty, bool $funded, bool $partial = false) use ($sales, $context, $warehouse, $variant, $owner) {
        $data = ['warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $variant->id, 'quantity' => $qty]], 'discount' => '0', 'shipping' => '0'];
        $quote = $sales->quote($context, $data, $owner);
        $paid = $partial ? Money::round(bcdiv($quote['grand_total'], '4', 8)) : ($funded ? $quote['grand_total'] : '0');

        return $sales->create($context, [...$data, 'request_key' => (string) Str::uuid(), 'quote_hash' => $quote['quote_hash'], 'mode' => 'sale', 'initial_payment' => $paid, 'payment_method' => 'cash'], $owner)['order'];
    };
    $scenarios = [
        'two-applications' => ['apply', 'apply'],
        'application-refund' => ['apply', 'refund'],
        'application-adjustment' => ['apply', 'undo_adjustment'],
        'double-adjustment' => ['undo_adjustment', 'undo_adjustment'],
        'same-uuid-adjustment' => ['undo_adjustment', 'undo_adjustment'],
        'double-refund-correction' => ['undo_refund', 'undo_refund'],
        'double-credit-reversal' => ['undo_credit', 'undo_credit'],
        'double-physical-reversal' => ['undo_receipt', 'undo_receipt'],
        'payment-adjustment' => ['payment', 'undo_adjustment'],
        'same-uuid-other-actor' => ['apply', 'apply'],
        'four-applications' => ['apply', 'apply', 'apply', 'apply'],
    ];
    foreach (range(1, $rounds) as $round) {
        foreach ($scenarios as $name => $operations) {
            if ($round % 2 === 0 && count(array_unique($operations)) > 1) {
                $operations = array_reverse($operations);
            }
            $case = 'r'.$round.'-'.$name;
            $customer = Customer::create(['full_name' => 'Synthetic finance '.$tag.' '.$case, 'notes' => 'Noncommercial concurrent QA fixture; preserve financial history.']);
            $customers[] = $customer->id;
            $source = $sale($customer, '3', true, $name === 'payment-adjustment');
            $payment = (int) DB::table('order_payments')->where('order_id', $source['id'])->value('payment_id');
            $receiptService = app(SalesReturnReceipts::class);
            $r = $receiptService->info($context, $source['id']);
            $receipt = $receiptService->receive($context, $source['id'], $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'reason' => 'Synthetic concurrent return '.$tag, 'lines' => [['invoice_item_id' => $r['lines'][0]['invoice_item_id'], 'quantity' => '1', 'restock' => true]]])['sales_return_id'];
            $adjustment = $application = $refund = null;
            if ($name !== 'double-physical-reversal') {
                $r = app(ReturnSettlements::class)->info($context, $source['id'], $receipt, [])['proposal'];
                $adjustment = app(ReturnSettlements::class)->adjust($context, $source['id'], $receipt, $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'shipping_credit' => '0', 'reason' => 'Synthetic concurrent adjustment '.$tag])['adjustment_id'];
            }
            $source = $sales->present(SalesOrder::findOrFail($source['id']));
            $credit = $source['credit_available'];
            $targets = [];
            if (in_array('apply', $operations, true) || $name === 'double-credit-reversal') {
                $count = $name === 'four-applications' ? 4 : ($name === 'two-applications' ? 2 : 1);
                for ($i = 0; $i < $count; $i++) {
                    $targets[] = $sale($customer, '2', false);
                }
            }
            if ($name === 'double-credit-reversal') {
                $r = app(CreditApplications::class)->info($context, $targets[0]['id']);
                $application = app(CreditApplications::class)->apply($context, $targets[0]['id'], $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['version'], 'review_hash' => $r['review_hash'], 'payment_id' => $payment, 'amount' => $credit, 'reason' => 'Synthetic original application '.$tag])['credit_application_id'];
            }
            if ($name === 'double-refund-correction') {
                $current = $sales->present(SalesOrder::findOrFail($source['id']));
                $refund = app(ReturnSettlements::class)->refund($context, $source['id'], $receipt, $owner, ['request_key' => (string) Str::uuid(), 'version' => $current['lock_version'], 'payment_id' => $payment, 'amount' => $credit, 'method' => 'cash', 'external_reference' => null, 'reason' => 'Synthetic erroneous refund; no actual delivery '.$tag])['refund_id'];
            }
            $originalPayment = (array) DB::table('payments')->find($payment);
            $originalAdjustment = $adjustment ? (array) DB::table('return_adjustments')->find($adjustment) : null;
            $originalRefund = $refund ? (array) DB::table('refunds')->find($refund) : null;
            $balanceBefore = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
            $moveCount = DB::table('inventory_movements')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->count();
            $orderIds = [$source['id'], ...array_column($targets, 'id')];
            $invoiceCount = DB::table('invoices')->where('customer_id', $customer->id)->count();
            $frozenInvoiceColumns = ['id', 'invoice_number', 'grand_total', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'currency', 'issued_at', 'business_snapshot'];
            $originalInvoices = DB::table('invoices')->where('customer_id', $customer->id)->select($frozenInvoiceColumns)->orderBy('id')->get()->toJson();
            $frozenOrderColumns = ['id', 'customer_id', 'customer_snapshot', 'grand_total', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'currency', 'warehouse_id', 'created_at'];
            $originalOrders = DB::table('orders')->whereIn('id', $orderIds)->select($frozenOrderColumns)->orderBy('id')->get()->toJson();
            $originalDocuments = DB::table('financial_documents')->whereIn('order_id', $orderIds)->get();
            $sharedKey = (string) Str::uuid();
            $sharedUuid = str_starts_with($name, 'same-uuid');
            $sameIdentity = $name === 'same-uuid-adjustment';
            $entry = ['case' => $case, 'operations' => $operations, 'customer_id' => $customer->id, 'source_id' => $source['id'], 'targets' => array_column($targets, 'id'), 'credit' => $credit];
            FinancialConcurrencyOperations::writeJson($directory.'/'.$case.'.before.json', $entry);
            DB::beginTransaction();
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            try {
                foreach ($operations as $slot => $operation) {
                    $job = $case.'-s'.$slot;
                    $actor = $sameIdentity || $slot % 2 === 0 ? $owner : $second;
                    $target = $source['id'];
                    if ($operation === 'apply' || $operation === 'undo_credit') {
                        $target = $targets[in_array($name, ['two-applications', 'four-applications'], true) ? $slot : 0]['id'];
                    }
                    $amount = $operation === 'payment' ? $source['pending_total'] : $credit;
                    $spec = ['tag' => $tag, 'operation' => $operation, 'request_key' => $sharedUuid ? $sharedKey : (string) Str::uuid(), 'reason' => 'Synthetic finance '.$case.' '.$tag, 'store_id' => $store->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'actor_id' => $actor->id, 'source_id' => $source['id'], 'order_id' => $target, 'payment_id' => $payment, 'receipt_id' => $receipt, 'adjustment_id' => $adjustment, 'application_id' => $application, 'refund_id' => $refund, 'amount' => $amount];
                    FinancialConcurrencyOperations::writeJson($directory.'/'.$job.'.json', $spec);
                    $out = $directory.'/'.$job.'.out';
                    $err = $directory.'/'.$job.'.err';
                    $pipes = [];
                    $process = proc_open([PHP_BINARY, __DIR__.'/financial-concurrency-worker.php', $tag, $job], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, base_path(), null, ['bypass_shell' => true]);
                    $check(is_resource($process), 'Worker could not start.');
                    $workers[] = ['process' => $process, 'job' => $job, 'out' => $out, 'err' => $err, 'exit_code' => null];
                    if ($slot === 0 && count(array_unique($operations)) > 1) {
                        // Queue the selected ordering first, but keep it blocked while
                        // its competitor reviews the same unchanged pre-operation state.
                        $deadline = microtime(true) + 20;
                        do {
                            clearstatcache();
                            $readyPath = $directory.'/'.$job.'.ready.json';
                            $check(microtime(true) <= $deadline, 'First queued worker did not become ready.');
                            if (! is_file($readyPath)) {
                                usleep(25000);
                            }
                        } while (! is_file($readyPath));
                        $first = json_decode(file_get_contents($readyPath), true, flags: JSON_THROW_ON_ERROR);
                        $deadline = microtime(true) + 10;
                        do {
                            $firstWait = FinancialConcurrencyOperations::blockedSessions([(int) $first['connection_id']], false);
                            $check(microtime(true) <= $deadline, 'First queued worker did not reach the product barrier.');
                            if (count($firstWait) !== 1) {
                                usleep(25000);
                            }
                        } while (count($firstWait) !== 1);
                        $entry['first_queued_operation'] = $operation;
                    }
                }
                $deadline = microtime(true) + 20;
                do {
                    clearstatcache();
                    $ready = [];
                    foreach ($workers as &$worker) {
                        if (is_file($directory.'/'.$worker['job'].'.ready.json')) {
                            $ready[] = json_decode(file_get_contents($directory.'/'.$worker['job'].'.ready.json'), true, flags: JSON_THROW_ON_ERROR);
                        } else {
                            $check($checkProcess($worker), 'Worker failed before the ready barrier; inspect '.$worker['err']);
                        }
                    }unset($worker);
                    $check(microtime(true) <= $deadline, 'Worker ready barrier timeout.');
                    if (count($ready) !== count($workers)) {
                        usleep(25000);
                    }
                } while (count($ready) !== count($workers));
                $connections = array_column($ready, 'connection_id');
                $check(count(array_unique($connections)) === count($workers) && count(array_unique(array_column($ready, 'pid'))) === count($workers), 'Workers do not have independent SQL/process identities.');
                if ($sharedUuid) {
                    $check(count(array_unique(array_column($ready, 'payload_hash'))) === 1, 'Shared-UUID workers reviewed different payloads.');
                }
                $deadline = microtime(true) + 10;
                do {
                    $observed = FinancialConcurrencyOperations::blockedSessions($connections, $sharedUuid);
                    $check(microtime(true) <= $deadline, 'Could not prove simultaneous SQL waiters; timing-only races are rejected.');
                    if (count($observed) !== count($workers)) {
                        usleep(25000);
                    }
                } while (count($observed) !== count($workers));
                $entry['simultaneous_waiters'] = $observed;
                FinancialConcurrencyOperations::writeJson($directory.'/'.$case.'.overlap.json', ['parent_connection_id' => $parentConnection, 'workers' => $ready, 'simultaneous_waiters' => $observed]);
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
            }
            $results = [];
            foreach ($workers as &$worker) {
                $deadline = microtime(true) + 35;
                while ($checkProcess($worker)) {
                    $check(microtime(true) <= $deadline, 'Worker completion timeout.');
                    usleep(25000);
                }
                $closed = proc_close($worker['process']);
                $worker['process'] = null;
                $code = $closed >= 0 ? $closed : $worker['exit_code'];
                $result = FinancialConcurrencyOperations::parseWorkerResult(file_get_contents($worker['out']));
                $results[] = $result;
                $check($code === 0 && in_array($result['status'], [200, 409], true), 'Worker returned an unexpected failure; inspect '.$worker['out']);
            }unset($worker);
            $workers = [];
            $entry['results'] = $results;
            $winners = [];
            foreach ($results as $slot => $result) {
                if ($result['status'] === 200 && ! $result['replay']) {
                    $winners[] = $operations[$slot];
                }
            }
            $entry['committed_operations'] = $winners;
            $report['ordering_coverage'][$name] = array_values(array_unique([...($report['ordering_coverage'][$name] ?? []), ...$winners]));
            $report['cases'][] = $entry;
            $statuses = array_column($results, 'status');
            sort($statuses);
            $expected = $sameIdentity ? [200, 200] : [200, ...array_fill(0, count($operations) - 1, 409)];
            $check($statuses === $expected, 'Unexpected race outcomes in '.$case);
            if ($sameIdentity) {
                $check(count(array_filter($results, fn ($r) => $r['replay'])) === 1, 'Shared UUID did not return exactly one replay.');
            }
            $apps = DB::table('credit_applications')->where('payment_id', $payment)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_application_reversals as v')->whereColumn('v.credit_application_id', 'credit_applications.id'))->get();
            $effectiveRefunds = RefundState::effective(DB::table('refunds')->where('original_payment_id', $payment)->where('status', 'confirmed'))->get();
            $compensations = $adjustment ? DB::table('return_adjustment_reversals')->where('adjustment_id', $adjustment)->count() : 0;
            if (in_array($name, ['two-applications', 'four-applications', 'same-uuid-other-actor'], true)) {
                $check($apps->count() === 1, 'Credit was applied more than once.');
            }
            if ($name === 'application-refund') {
                $check($apps->count() + $effectiveRefunds->count() === 1, 'Both application and refund consumed the same pool.');
            }
            if ($name === 'application-adjustment') {
                $check($apps->count() + $compensations === 1, 'Application and compensation both committed.');
            }
            if (in_array($name, ['double-adjustment', 'same-uuid-adjustment'], true)) {
                $check($compensations === 1, 'Adjustment was compensated more than once.');
            }
            if ($name === 'double-refund-correction') {
                $check(DB::table('refund_reversals')->where('refund_id', $refund)->count() === 1 && $effectiveRefunds->isEmpty(), 'Refund correction count/effect failed.');
            }
            if ($name === 'double-credit-reversal') {
                $check(DB::table('credit_application_reversals')->where('credit_application_id', $application)->count() === 1 && $apps->isEmpty(), 'Application reversal count/effect failed.');
            }
            if ($name === 'double-physical-reversal') {
                $check(DB::table('return_receipt_reversals')->where('sales_return_id', $receipt)->count() === 1, 'Physical receipt reversal count failed.');
            }
            if ($name === 'payment-adjustment') {
                $check(DB::table('payments')->where('customer_id', $customer->id)->count() + $compensations === 2, 'Payment and adjustment compensation both committed.');
            }
            if ($apps->isNotEmpty()) {
                $check(bccomp(OrderSettlement::sum($apps->pluck('amount')), $credit, 4) === 0, 'The application consumed a different amount than requested.');
            }
            if ($effectiveRefunds->isNotEmpty()) {
                $check(bccomp(OrderSettlement::sum($effectiveRefunds->pluck('amount')), $credit, 4) === 0, 'The refund consumed a different amount than requested.');
            }
            $received = OrderSettlement::sum(DB::table('payments')->where('customer_id', $customer->id)->where('status', 'confirmed')->pluck('amount'));
            $expectedCash = bcadd((string) $originalPayment['amount'], in_array('payment', $winners, true) ? $source['pending_total'] : '0', 4);
            $check(bccomp($received, $expectedCash, 4) === 0, 'A race created unrequested cash.');
            $check((array) DB::table('payments')->find($payment) === $originalPayment, 'Original payment row changed.');
            if ($adjustment) {
                $check((array) DB::table('return_adjustments')->find($adjustment) === $originalAdjustment, 'Original adjustment row changed.');
            }
            if ($refund) {
                $check((array) DB::table('refunds')->find($refund) === $originalRefund, 'Original refund row changed.');
            }
            $balance = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
            $delta = $name === 'double-physical-reversal' ? '-1.0000' : '0.0000';
            $check(bccomp((string) $balance->on_hand, bcadd((string) $balanceBefore->on_hand, $delta, 4), 4) === 0 && bccomp((string) $balance->reserved, '0', 4) === 0, 'Financial race changed stock incorrectly.');
            $check(DB::table('inventory_movements')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->count() === $moveCount + ($name === 'double-physical-reversal' ? 1 : 0), 'Wrong inverse stock movement count.');
            $check(DB::table('invoices')->where('customer_id', $customer->id)->count() === $invoiceCount, 'A race issued another invoice.');
            $check(DB::table('invoices')->where('customer_id', $customer->id)->select($frozenInvoiceColumns)->orderBy('id')->get()->toJson() === $originalInvoices, 'A race changed original fiscal snapshots or totals.');
            $check(DB::table('orders')->whereIn('id', $orderIds)->select($frozenOrderColumns)->orderBy('id')->get()->toJson() === $originalOrders, 'A race changed original order/customer snapshots or totals.');
            foreach ($originalDocuments as $document) {
                $check((array) DB::table('financial_documents')->find($document->id) === (array) $document, 'A race changed the original financial document snapshot.');
            }
            foreach (DB::table('payments')->where('customer_id', $customer->id)->where('status', 'confirmed')->get() as $p) {
                $f = OrderSettlement::payment($p->id);
                $check(bccomp($f['available'], '0', 4) >= 0 && bccomp($f['effective_allocated'], '0', 4) >= 0 && bccomp(bcadd(bcadd($f['available'], $f['effective_allocated'], 4), $f['refunded'], 4), (string) $p->amount, 4) === 0, 'Payment conservation failed.');
            }
            $positions = [];
            foreach ($orderIds as $id) {
                $p = $sales->present(SalesOrder::findOrFail($id));
                $funds = bcsub(bcsub(bcadd($p['gross_received'], $p['credit_received_total'], 4), $p['credit_applied_total'], 4), $p['refunded_total'], 4);
                $check(bccomp($funds, bcadd($p['paid_total'], $p['credit_available'], 4), 4) === 0 && bccomp(bcadd($p['paid_total'], $p['pending_total'], 4), $p['net_total'], 4) === 0, 'Order conservation failed.');
                $positions[] = ['id' => $id, 'net' => $p['net_total'], 'paid' => $p['paid_total'], 'pending' => $p['pending_total'], 'available' => $p['credit_available'], 'refunded' => $p['refunded_total']];
            }
            FinancialConcurrencyOperations::writeJson($directory.'/'.$case.'.after.json', ['statuses' => $statuses, 'positions' => $positions, 'simultaneous_waiters' => $observed]);
            echo 'PASS '.$case.' | concurrent SQL waiters: '.count($observed).' | outcomes: '.implode('/', $statuses).' | committed: '.implode(',', $winners).' | exact conservation'.PHP_EOL;
        }
    }
    if ($rounds >= 2) {
        foreach ($scenarios as $name => $operations) {
            $required = array_values(array_unique($operations));
            if (count($required) > 1) {
                $covered = $report['ordering_coverage'][$name] ?? [];
                sort($required);
                sort($covered);
                $check($required === $covered, 'Commit-order coverage incomplete for '.$name.'; preserve this report and run more bounded rounds.');
            }
        }
    }
} catch (Throwable $e) {
    $report['failures'][] = get_class($e).': '.$e->getMessage();
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $ownedWorkersStopped = true;
    foreach ($workers as &$worker) {
        if (! is_resource($worker['process'])) {
            continue;
        }if ($checkProcess($worker)) {
            proc_terminate($worker['process']);
        }$deadline = microtime(true) + 5;
        while ($checkProcess($worker) && microtime(true) < $deadline) {
            usleep(25000);
        }if ($checkProcess($worker)) {
            $report['failures'][] = 'Owned worker did not stop; manual fixture inspection required.';
            $ownedWorkersStopped = false;

            continue;
        }proc_close($worker['process']);
        $worker['process'] = null;
    }unset($worker);
    try {
        $check($ownedWorkersStopped, 'Live workers remain; fixture state preserved for manual inspection.');
        if ($store) {
            $check($store->code === 'QA-FIN-'.$tag, 'Cleanup store identity mismatch.');
            $context->useStore($store);
            if ($variant) {
                $b = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
                $check(bccomp((string) $b->reserved, '0', 4) === 0, 'Unexpected QA reservations remain.');
                if (bccomp((string) $b->on_hand, '0', 4) > 0) {
                    $stock->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'issue', 'quantity' => (string) $b->on_hand, 'version' => (int) $b->version, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic financial QA compensating exit '.$tag], $owner);
                }$catalog->update($product->id, [...$catalogPayload, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
                $check(app(InventoryReconciliation::class)->inspect($store->id, $warehouse->id)['summary']['differences'] === 0, 'QA stock reconciliation failed.');
            }
            foreach ($customers as $id) {
                $customer = Customer::findOrFail($id);
                $check(str_contains($customer->full_name, $tag), 'Cleanup customer identity mismatch.');
                $customer->status = 'archived';
                $customer->save();
            }
            $second->is_active = false;
            $second->save();
            $warehouse->is_active = false;
            $warehouse->save();
            $store->is_active = false;
            $store->save();
            $report['fixtures_archived'] = true;
        }
        foreach ($snapshot as $table => $saved) {
            if (! $saved['rows']) {
                continue;
            }$counts = [];
            foreach (DB::table($table)->select(array_keys($saved['rows'][0]))->get() as $row) {
                $key = hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }foreach ($saved['rows'] as $row) {
                $key = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
                $check(($counts[$key] ?? 0) > 0, 'A preexisting row changed or disappeared in '.$table);
                $counts[$key]--;
            }
        }
        $report['preexisting_rows_preserved'] = true;
    } catch (Throwable $e) {
        $report['failures'][] = 'Final verification: '.$e->getMessage();
    }
    $context->clear();
    DB::selectOne('SELECT RELEASE_LOCK(?) AS released', ['bshop:financial-concurrency-qa']);
    $report['finished_at'] = gmdate(DATE_ATOM);
    $report['status'] = $report['failures'] ? 'failed' : 'passed';
    FinancialConcurrencyOperations::writeJson($directory.'/report.json', $report);
}
echo 'Financial QA report: '.$directory.'/report.json'.PHP_EOL;
if ($report['failures']) {
    foreach ($report['failures'] as $failure) {
        fwrite(STDERR, $failure.PHP_EOL);
    }exit(1);
}
echo 'PASS: '.count($report['cases'])." real multiprocess races. Prior rows preserved; synthetic fixtures archived with financial history intact. No HTTP/PDF/provider calls; not a production load benchmark.\n";
