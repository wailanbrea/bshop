<?php

use App\Domain\Business\StoreContext;
use App\Domain\Catalog\ProductService;
use App\Domain\Inventory\InventoryReconciliation;
use App\Domain\Inventory\InventoryService;
use App\Domain\Sales\OrderInvoice;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\SalesService;
use App\Domain\Sales\WeeklyAccounts;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WeeklyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require __DIR__.'/import-test-connection.php';
require __DIR__.'/weekly-concurrency-support.php';
$check = FinancialConcurrencyOperations::check(...);
$rounds = 2;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--preflight') {
        continue;
    }
    $check((bool) preg_match('/^--rounds=([1-5])$/D', $argument, $match), 'Use --preflight or --rounds=1..5.');
    $rounds = (int) $match[1];
}
$check(app()->environment('testing') && DB::getDriverName() === 'mysql' && DB::connection()->getDatabaseName() === 'bshop_testing_single', 'Weekly races require the dedicated QA connection.');
foreach (['weekly_accounts', 'weekly_cycle_locks', 'invoice_orders', 'return_adjustment_reversals'] as $table) {
    $check(Schema::hasTable($table), 'Apply reviewed QA migrations first: '.$table);
}
$owner = User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('code', 'owner'))->firstOrFail();
$manager = Role::where('code', 'manager')->firstOrFail();
foreach (['sales.create', 'payments.record', 'invoices.view', 'inventory.manage'] as $permission) {
    $check($owner->permits($permission) && in_array($permission, $manager->permissions, true), 'QA actors lack '.$permission);
}
$parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$check(collect(DB::select('SHOW FULL PROCESSLIST'))->contains(fn ($r) => (int) $r->Id === $parent), 'Own SQL sessions must be observable.');
if (in_array('--preflight', $argv, true)) {
    echo "PASS: protected MySQL 8.4 destination, weekly schema/permissions and own-session visibility. No fixtures created.\n";
    exit(0);
}
// Share the financial runner mutex so full-row preservation checks cannot overlap.
$check((int) DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['bshop:financial-concurrency-qa'])->acquired === 1, 'Another financial QA runner is active.');
$tag = strtoupper(bin2hex(random_bytes(6)));
$directory = WeeklyConcurrencyOperations::directory($tag);
$check(! file_exists($directory) && mkdir($directory, 0770), 'Cannot create weekly artifact directory.');
$report = ['tag' => $tag, 'destination' => 'testing/mysql/127.0.0.1:33078/bshop_testing_single', 'started_at' => gmdate(DATE_ATOM), 'rounds' => $rounds, 'cases' => [], 'failures' => []];
$snapshot = [];
$store = $warehouse = $actor = $product = $variant = null;
$customers = [];
$runner = new WeeklyConcurrencyOperations;
$context = app(StoreContext::class);
$sales = app(SalesService::class);
$weekly = app(WeeklyAccounts::class);
$catalog = app(ProductService::class);
$stock = app(InventoryService::class);
try {
    $snapshot = WeeklyConcurrencyOperations::snapshot();
    FinancialConcurrencyOperations::writeJson($directory.'/backup.json', $snapshot);
    $report['backup_sha256'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $check(hash_equals($report['backup_sha256'], hash_file('sha256', $directory.'/backup.json')), 'Weekly backup checksum mismatch.');
    echo 'Verified full schema/data backup: '.$directory.'/backup.json | tables: '.count($snapshot).PHP_EOL;
    [$store, $warehouse, $actor, $product, $variant, $productData] = DB::transaction(function () use ($tag, $owner, $manager, $context, $catalog, $stock) {
        $s = Store::create(['name' => 'Synthetic weekly QA '.$tag, 'code' => 'QA-WEEK-'.$tag, 'is_active' => true]);
        $w = Warehouse::create(['store_id' => $s->id, 'name' => 'Synthetic weekly warehouse', 'is_active' => true, 'is_default' => true]);
        $u = User::factory()->create(['name' => 'Weekly QA '.$tag, 'email' => 'weekly-'.strtolower($tag).'@example.test']);
        $u->roles()->attach($manager->id);
        DB::table('store_user')->insert([['store_id' => $s->id, 'user_id' => $owner->id], ['store_id' => $s->id, 'user_id' => $u->id]]);
        $context->useStore($s);
        $data = ['name' => 'Synthetic weekly product '.$tag, 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'WEEK-RACE-'.$tag, 'sale_price' => '10', 'attribute_value_ids' => []]]];
        $p = $catalog->create($data, $owner);
        $v = $p->variants()->firstOrFail();
        $stock->move($context, ['warehouse_id' => $w->id, 'variant_id' => $v->id, 'operation' => 'receipt', 'quantity' => '1000', 'version' => 0, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic weekly opening stock '.$tag], $owner);

        return [$s, $w, $u, $p, $v, $data];
    });
    $report['fixtures'] = ['store_id' => $store->id, 'warehouse_id' => $warehouse->id, 'actor_id' => $actor->id, 'product_id' => $product->id, 'variant_id' => $variant->id];
    FinancialConcurrencyOperations::writeJson($directory.'/manifest.json', ['tag' => $tag, 'owner_id' => $owner->id, ...$report['fixtures']]);
    // Current local day ensures all freshly created reservations fall inside the account.
    $period = CarbonImmutable::now(BusinessProfile::current()->timezone)->format('Y-m-d');
    $scenarios = [
        'same-period' => ['create', 'create'],
        'overlapping-periods' => ['create', 'create'],
        'replay-create' => ['create', 'create'],
        'two-attachments' => ['attach', 'attach'],
        'replay-attach' => ['attach', 'attach'],
        'attach-invoice' => ['attach', 'invoice'],
        'double-close' => ['close', 'close'],
        'replay-close' => ['close', 'close'],
        'close-attach' => ['close', 'attach'],
        'close-detach' => ['close', 'detach'],
        'close-payment' => ['close', 'payment'],
        'close-invoice' => ['close', 'invoice'],
        'same-member-payments' => ['payment', 'payment'],
        'different-member-payments' => ['payment', 'payment'],
        'replay-payment' => ['payment', 'payment'],
        'cancel-attach' => ['cancel', 'attach'],
    ];
    foreach (range(1, $rounds) as $round) {
        foreach ($scenarios as $name => $operations) {
            if ($round % 2 === 0 && count(array_unique($operations)) > 1) {
                $operations = array_reverse($operations);
            }
            $case = 'r'.$round.'-'.$name;
            $customer = Customer::create(['full_name' => 'Synthetic weekly '.$tag.' '.$case, 'notes' => 'Noncommercial concurrent QA fixture; preserve history.']);
            $customers[] = $customer->id;
            $createRace = in_array('create', $operations, true);
            $orders = [];
            if (! $createRace) {
                for ($i = 0; $i < ($name === 'close-attach' ? 3 : 2); $i++) {
                    $data = ['warehouse_id' => $warehouse->id, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $variant->id, 'quantity' => '1']], 'discount' => '0', 'shipping' => '0'];
                    $quote = $sales->quote($context, $data, $owner);
                    $orders[] = $sales->create($context, [...$data, 'quote_hash' => $quote['quote_hash'], 'request_key' => (string) Str::uuid(), 'mode' => 'reservation', 'initial_payment' => '2', 'payment_method' => 'cash'], $owner)['order']['id'];
                }
            }
            $accountId = null;
            if (! $createRace) {
                $accountId = $weekly->create($context, $owner, ['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'period_start' => $period])['account']['id'];
                $empty = in_array($name, ['two-attachments', 'replay-attach', 'attach-invoice', 'cancel-attach'], true);
                if (! $empty) {
                    foreach (array_slice($orders, 0, 2) as $orderId) {
                        $r = $weekly->find($context, $accountId);
                        $weekly->membership($context, $accountId, 'attach', $owner, ['request_key' => (string) Str::uuid(), 'version' => $r->lock_version, 'order_id' => $orderId]);
                    }
                }
                if (in_array($name, ['same-member-payments', 'different-member-payments', 'replay-payment'], true)) {
                    $r = $weekly->present($weekly->find($context, $accountId));
                    $weekly->close($context, $accountId, $owner, ['request_key' => (string) Str::uuid(), 'version' => $r['lock_version'], 'review_hash' => $r['review_hash']]);
                }
            } else {
                DB::table('weekly_cycle_locks')->insertOrIgnore(['store_id' => $store->id, 'customer_id' => $customer->id]);
            }
            $balanceBefore = (array) DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
            $movesBefore = DB::table('inventory_movements')->where('warehouse_id', $warehouse->id)->count();
            $paymentsBefore = DB::table('payments')->where('customer_id', $customer->id)->get();
            $invoiceColumns = ['id', 'invoice_number', 'grand_total', 'subtotal', 'discount_total', 'tax_total', 'shipping_total', 'currency', 'business_snapshot', 'issued_at'];
            $invoicesBefore = DB::table('invoices')->where('customer_id', $customer->id)->select($invoiceColumns)->get();
            $accountsBefore = $accountId ? $weekly->find($context, $accountId)->toArray() : null;
            $samePayload = str_starts_with($name, 'replay-');
            $sharedKey = (string) Str::uuid();
            $specs = [];
            foreach ($operations as $slot => $operation) {
                $orderId = $orders[0] ?? null;
                if (($name === 'two-attachments' || $name === 'different-member-payments') && $slot === 1) {
                    $orderId = $orders[1];
                }
                if ($name === 'close-attach' && $operation === 'attach') {
                    $orderId = $orders[2];
                }
                $start = $name === 'overlapping-periods' && $slot === 1 ? CarbonImmutable::parse($period)->subDay()->format('Y-m-d') : $period;
                $specs[] = ['tag' => $tag, 'operation' => $operation, 'request_key' => $samePayload ? $sharedKey : (string) Str::uuid(), 'store_id' => $store->id, 'customer_id' => $customer->id, 'actor_id' => $samePayload || $slot === 0 ? $owner->id : $actor->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'account_id' => $accountId, 'order_id' => $orderId, 'period_start' => $start, 'amount' => '8'];
            }
            DB::beginTransaction();
            if ($createRace) {
                DB::table('weekly_cycle_locks')->where('store_id', $store->id)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();
            } elseif (! array_diff($operations, ['attach', 'detach', 'close', 'cancel'])) {
                WeeklyAccount::whereKey($accountId)->lockForUpdate()->firstOrFail();
            } elseif (array_unique($operations) === ['payment']) {
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            } else {
                SalesOrder::whereKey($orders[0])->lockForUpdate()->firstOrFail();
            }
            $race = $runner->race($tag, $case, $specs, count(array_unique($operations)) > 1, $samePayload);
            $entry = ['case' => $case, 'operations' => $operations, 'customer_id' => $customer->id, 'account_id' => $accountId, 'order_ids' => $orders, ...$race];
            $report['cases'][] = $entry;
            $statuses = array_column($race['results'], 'status');
            sort($statuses);
            $both = $samePayload || $name === 'different-member-payments';
            $check($statuses === ($both ? [200, 200] : [200, 409]), 'Unexpected weekly outcomes: '.$case);
            $check(count(array_filter($race['results'], fn ($r) => $r['replay'])) === ($samePayload ? 1 : 0), 'Wrong replay count: '.$case);
            $winners = [];
            $extraCash = '0.0000';
            foreach ($race['results'] as $slot => $r) {
                if ($r['status'] === 200 && ! $r['replay']) {
                    $winners[] = $operations[$slot];
                    if ($operations[$slot] === 'payment') {
                        $extraCash = bcadd($extraCash, $specs[$slot]['amount'], 4);
                    }
                }
            }
            $report['cases'][array_key_last($report['cases'])]['committed_operations'] = $winners;
            if ($samePayload) {
                $check(count(array_unique(array_column($race['results'], 'entity_id'))) === 1, 'Replay returned a different entity.');
            }
            $report['ordering_coverage'][$name] = array_values(array_unique([...($report['ordering_coverage'][$name] ?? []), ...$winners]));
            $accounts = WeeklyAccount::where('store_id', $store->id)->where('customer_id', $customer->id)->get();
            $check($accounts->count() === 1, 'Duplicate weekly cycles.');
            $account = $accounts->first();
            if ($createRace) {
                $winningSlot = array_search(200, array_column($race['results'], 'status'), true);
                $start = $specs[$winningSlot]['period_start'];
                $check($account->period_start === $start && $account->period_end === CarbonImmutable::parse($start)->addDays(6)->format('Y-m-d'), 'Wrong winning period or seven-day bounds.');
                $check($account->timezone === BusinessProfile::current()->timezone && $account->currency === BusinessProfile::current()->default_currency, 'Cycle lost timezone/currency context.');
                $check(DB::table('weekly_cycle_locks')->where('store_id', $store->id)->where('customer_id', $customer->id)->count() === 1, 'Duplicate customer-cycle mutex.');
            }
            $linked = SalesOrder::where('weekly_account_id', $account->id)->count();
            $expectedMembers = match ($name) {
                'two-attachments', 'replay-attach' => 1,
                'attach-invoice', 'cancel-attach' => in_array('attach', $winners, true) ? 1 : 0,
                'close-attach' => in_array('attach', $winners, true) ? 3 : 2,
                'close-detach' => in_array('detach', $winners, true) ? 1 : 2,
                default => $createRace ? 0 : 2,
            };
            $check($linked === $expectedMembers, 'Wrong member set after race.');
            $expectedClosed = in_array('close', $winners, true) || $name === 'same-member-payments' || $name === 'different-member-payments' || $name === 'replay-payment';
            $check($account->status === ($expectedClosed ? 'closed' : (in_array('cancel', $winners, true) ? 'cancelled' : 'open')), 'Wrong account state.');
            $invoices = DB::table('invoices')->where('customer_id', $customer->id)->get();
            $expectedInvoices = ($expectedClosed ? 1 : 0) + (in_array('invoice', $winners, true) ? 1 : 0);
            $check($invoices->count() === $expectedInvoices, 'Wrong number of invoices.');
            $cash = OrderSettlement::sum(DB::table('payments')->where('customer_id', $customer->id)->where('status', 'confirmed')->pluck('amount'));
            $check(bccomp($cash, bcadd(OrderSettlement::sum($paymentsBefore->pluck('amount')), $extraCash, 4), 4) === 0, 'Race created or lost cash.');
            foreach ($paymentsBefore as $payment) {
                $check((array) DB::table('payments')->find($payment->id) === (array) $payment, 'Original payment changed.');
            }
            foreach ($invoicesBefore as $invoice) {
                $check((array) DB::table('invoices')->select($invoiceColumns)->find($invoice->id) === (array) $invoice, 'Original fiscal snapshot changed.');
            }
            if ($expectedClosed) {
                $check(bccomp($account->paid_at_close, '4', 4) === 0, 'Closing snapshot includes a later payment or lost original deposits.');
                $check($account->customer_snapshot['full_name'] === $customer->full_name, 'Wrong weekly customer snapshot.');
            }
            if ($accountsBefore && $accountsBefore['status'] === 'closed') {
                $check($account->toArray() === $accountsBefore, 'Post-close payment rewrote the frozen account.');
            }
            foreach ($invoices as $invoice) {
                $sources = DB::table('invoice_orders')->where('invoice_id', $invoice->id)->get();
                $check($sources->count() === ($invoice->weekly_account_id ? 2 : 1), 'Invoice has missing or duplicate order sources.');
                $check(DB::table('invoice_items')->where('invoice_id', $invoice->id)->count() === $sources->count(), 'Invoice items do not match sources.');
                $check(DB::table('invoice_documents')->where('invoice_id', $invoice->id)->where('status', 'pending')->count() === 1, 'Missing or duplicate pending document.');
                $total = OrderSettlement::sum(SalesOrder::whereIn('id', $sources->pluck('order_id'))->pluck('grand_total'));
                $check(bccomp($total, (string) $invoice->grand_total, 4) === 0, 'Invoice total duplicated or omitted a member.');
                $position = OrderSettlement::invoice($invoice);
                $check(bccomp(bcadd($position['paid_total'], $position['pending_total'], 4), $position['net_total'], 4) === 0, 'Invoice conservation failed.');
                $expectedStatus = bccomp($position['pending_total'], '0', 4) === 0 ? 'paid' : (bccomp($position['paid_total'], '0', 4) > 0 ? 'partially_paid' : 'issued');
                $check($invoice->status === $expectedStatus, 'Aggregated invoice status is stale.');
            }
            $positions = [];
            foreach ($orders as $orderId) {
                $o = SalesOrder::findOrFail($orderId);
                $source = OrderInvoice::query($orderId)->first();
                $p = OrderSettlement::position($o, $source?->id);
                $check(bccomp(bcadd($p['paid_total'], $p['pending_total'], 4), $p['net_total'], 4) === 0, 'Order conservation failed.');
                $positions[] = ['order_id' => $orderId, 'invoice_id' => $source?->id, 'paid' => $p['paid_total'], 'pending' => $p['pending_total'], 'net' => $p['net_total']];
                foreach (DB::table('order_payments')->where('order_id', $orderId)->get() as $link) {
                    $allocations = DB::table('payment_allocations')->where('payment_id', $link->payment_id)->get();
                    $check($allocations->count() === ($source ? 1 : 0), 'Payment allocation duplicated or missing.');
                    if ($source) {
                        $payment = DB::table('payments')->find($link->payment_id);
                        $check((int) $allocations[0]->invoice_id === (int) $source->id && bccomp((string) $allocations[0]->amount, (string) $payment->amount, 4) === 0, 'Payment allocated to the wrong invoice/amount.');
                    }
                    $check(DB::table('customer_credit_movements')->where('payment_id', $link->payment_id)->where('event_type', 'receipt')->count() === 1, 'Missing or duplicate cash ledger entry.');
                    $check(DB::table('customer_credit_movements')->where('payment_id', $link->payment_id)->where('event_type', 'allocation')->count() === ($source ? 1 : 0), 'Missing or duplicate allocation ledger entry.');
                }
            }
            $check((array) DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first() === $balanceBefore && DB::table('inventory_movements')->where('warehouse_id', $warehouse->id)->count() === $movesBefore, 'Weekly race changed stock/reservations.');
            $after = ['account' => $account->toArray(), 'statuses' => $statuses, 'cash' => $cash, 'invoice_ids' => $invoices->pluck('id')->all(), 'members' => $linked, 'positions' => $positions];
            // A 409 must be recoverable by explicitly reviewing the committed state.
            if (str_starts_with($name, 'close-') && ! $expectedClosed) {
                $review = $weekly->present($weekly->find($context, $account->id));
                $included = collect($review['orders'])->where('included', true)->pluck('id')->all();
                $closed = $weekly->close($context, $account->id, $owner, ['request_key' => (string) Str::uuid(), 'version' => $review['lock_version'], 'review_hash' => $review['review_hash']])['account'];
                $check($closed['status'] === 'closed' && bccomp($closed['paid_at_close'], $review['totals']['paid_total'], 4) === 0, 'Refreshed closing snapshot is stale.');
                $invoice = DB::table('invoices')->find($closed['invoice']['id']);
                $sources = DB::table('invoice_orders')->where('invoice_id', $invoice->id)->orderBy('order_id')->pluck('order_id')->all();
                sort($included);
                $check($sources === $included && bccomp((string) $invoice->grand_total, $review['totals']['grand_total'], 4) === 0, 'Refreshed close duplicated or lost a member.');
                foreach ($invoices as $old) {
                    $check((array) DB::table('invoices')->find($old->id) === (array) $old, 'Refreshed close changed a prior individual invoice.');
                }
                foreach ($included as $orderId) {
                    foreach (DB::table('order_payments')->where('order_id', $orderId)->get() as $link) {
                        $payment = DB::table('payments')->find($link->payment_id);
                        $allocations = DB::table('payment_allocations')->where('payment_id', $link->payment_id)->get();
                        $check($allocations->count() === 1 && (int) $allocations[0]->invoice_id === (int) $invoice->id && bccomp((string) $allocations[0]->amount, (string) $payment->amount, 4) === 0, 'Refreshed close misallocated a prior payment.');
                    }
                }
                $check(bccomp(OrderSettlement::invoice($invoice)['paid_total'], $review['totals']['paid_total'], 4) === 0, 'Refreshed invoice lost an earlier payment.');
                $check(bccomp(OrderSettlement::sum(DB::table('payments')->where('customer_id', $customer->id)->pluck('amount')), $cash, 4) === 0, 'Refreshed close created cash.');
                $check((array) DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first() === $balanceBefore, 'Refreshed close changed stock.');
                $after['refreshed_close'] = ['invoice_id' => $invoice->id, 'order_ids' => $sources, 'paid_at_close' => $closed['paid_at_close'], 'grand_total' => $closed['totals']['grand_total']];
                $report['cases'][array_key_last($report['cases'])]['refreshed_close_verified'] = true;
            }
            $issued = DB::table('invoices')->where('store_id', $store->id)->count();
            $sequence = DB::table('document_sequences')->where('store_id', $store->id)->where('document_type', 'invoice')->first();
            $check($issued ? $sequence && (int) $sequence->next_number === $issued + 1 : ! $sequence, 'Failed race consumed an invoice number or duplicated the sequence.');
            FinancialConcurrencyOperations::writeJson($directory.'/'.$case.'.after.json', $after);
            echo 'PASS '.$case.' | SQL waiters: '.count($race['simultaneous_waiters']).' | outcomes: '.implode('/', $statuses).' | committed: '.implode(',', $winners).' | exact attribution'.PHP_EOL;
        }
    }
    if ($rounds >= 2) {
        foreach ($scenarios as $name => $operations) {
            $required = array_values(array_unique($operations));
            if (count($required) > 1) {
                $covered = $report['ordering_coverage'][$name] ?? [];
                sort($required);
                sort($covered);
                $check($required === $covered, 'Both commit orders were not covered: '.$name);
            }
        }
    }
} catch (Throwable $e) {
    $report['failures'][] = get_class($e).': '.$e->getMessage();
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    try {
        $runner->stop();
        if ($store) {
            $check($store->code === 'QA-WEEK-'.$tag, 'Weekly archive store mismatch.');
            $context->useStore($store);
            // Complete only synthetic deliveries through the real service to release reservations.
            foreach (SalesOrder::where('store_id', $store->id)->whereIn('status', ['reserved', 'confirmed'])->orderBy('id')->get() as $order) {
                $sales->change($context, $order->id, 'deliver', ['request_key' => (string) Str::uuid(), 'version' => $order->lock_version], $owner);
            }
            $b = DB::table('stock_balances')->where('warehouse_id', $warehouse->id)->where('variant_id', $variant->id)->first();
            $check(bccomp((string) $b->reserved, '0', 4) === 0, 'Synthetic reservations remain.');
            if (bccomp((string) $b->on_hand, '0', 4) > 0) {
                $stock->move($context, ['warehouse_id' => $warehouse->id, 'variant_id' => $variant->id, 'operation' => 'issue', 'quantity' => (string) $b->on_hand, 'version' => (int) $b->version, 'request_key' => (string) Str::uuid(), 'note' => 'Synthetic weekly compensating exit '.$tag], $owner);
            }
            $catalog->update($product->id, [...$productData, 'status' => 'archived', 'version' => $product->fresh()->lock_version], $owner);
            $check(app(InventoryReconciliation::class)->inspect($store->id, $warehouse->id)['summary']['differences'] === 0, 'Weekly QA stock reconciliation failed.');
            foreach ($customers as $id) {
                $c = Customer::findOrFail($id);
                $check(str_contains($c->full_name, $tag), 'Weekly archive customer mismatch.');
                $c->status = 'archived';
                $c->save();
            }
            $actor->is_active = false;
            $actor->save();
            $warehouse->is_active = false;
            $warehouse->save();
            $store->is_active = false;
            $store->save();
            $report['fixtures_archived'] = true;
        }
        WeeklyConcurrencyOperations::verifyPreserved($snapshot);
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
echo 'Weekly QA report: '.$directory.'/report.json'.PHP_EOL;
if ($report['failures']) {
    foreach ($report['failures'] as $failure) {
        fwrite(STDERR, $failure.PHP_EOL);
    }
    exit(1);
}
echo 'PASS: '.count($report['cases'])." real weekly multiprocess races; prior rows preserved, fixtures archived. No HTTP/PDF/provider calls; not a load benchmark.\n";
