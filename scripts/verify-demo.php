<?php

use App\Domain\Sales\FinancialDocument;
use App\Domain\Sales\OrderSettlement;
use App\Domain\Sales\SalesControl;
use App\Domain\Sales\SalesService;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = DB::connection();
if (! app()->environment('local') || $db->getDriverName() !== 'sqlite' || str_replace('\\', '/', $db->getDatabaseName()) !== str_replace('\\', '/', database_path('bshop-business.sqlite'))) {
    throw new RuntimeException('Read-only demo verification is restricted to the isolated local SQLite file.');
}
echo 'Read-only verification: local | sqlite | host: local | database: '.$db->getDatabaseName()."\n";
$sum = function ($rows, string $field): string {
    $total = '0.0000';
    foreach ($rows as $row) {
        $total = bcadd($total, (string) $row->$field, 4);
    }

    return $total;
};
$probe = SalesOrder::orderBy('id')->first();
if ($probe) {
    app(SalesService::class)->present($probe);
    if (DB::transactionLevel() !== 0) {
        throw new RuntimeException('Read snapshot did not close its transaction.');
    }
}
DB::transaction(function () use ($sum) {
    if (BusinessProfile::current()->default_currency !== 'USD') {
        throw new RuntimeException('Demo currency is not USD.');
    }
    foreach (['orders', 'invoices', 'payments', 'payment_allocations', 'customer_credit_movements'] as $table) {
        if (DB::table($table)->where('currency', '!=', 'USD')->exists()) {
            throw new RuntimeException('Mixed currency in '.$table);
        }
    }
    $physical = '0.0000';
    $reserved = '0.0000';
    foreach (DB::table('stock_balances')->get() as $balance) {
        $movements = DB::table('inventory_movements')->where('warehouse_id', $balance->warehouse_id)->where('variant_id', $balance->variant_id)->get();
        $onHand = $sum($movements, 'on_hand_delta');
        $held = $sum($movements, 'reserved_delta');
        $reservations = '0.0000';
        foreach (DB::table('stock_reservations')->where('warehouse_id', $balance->warehouse_id)->where('variant_id', $balance->variant_id)->where('status', 'active')->get() as $r) {
            $reservations = bcadd($reservations, bcsub(bcsub((string) $r->quantity, (string) $r->fulfilled_quantity, 4), (string) $r->released_quantity, 4), 4);
        }
        if (bccomp($onHand, (string) $balance->on_hand, 4) !== 0 || bccomp($held, (string) $balance->reserved, 4) !== 0 || bccomp($held, $reservations, 4) !== 0 || bccomp($held, '0', 4) < 0 || bccomp($held, $onHand, 4) > 0) {
            throw new RuntimeException('Kardex/reservation discrepancy for variant '.$balance->variant_id);
        }
        $physical = bcadd($physical, $onHand, 4);
        $reserved = bcadd($reserved, $held, 4);
    }
    $orders = [];
    $controlExpected = [];
    $timezone = BusinessProfile::current()->timezone;
    $controlTo = CarbonImmutable::now($timezone)->toDateString();
    $controlFrom = CarbonImmutable::now($timezone)->startOfMonth()->toDateString();
    $total = '0.0000';
    $paid = '0.0000';
    $pending = '0.0000';
    $sales = app(SalesService::class);
    foreach (SalesOrder::orderBy('id')->get() as $order) {
        $presented = $sales->present($order);
        $created = CarbonImmutable::parse($order->created_at, config('app.timezone'))->setTimezone($timezone)->toDateString();
        if ($created >= $controlFrom && $created <= $controlTo && ! in_array($order->status, ['draft', 'cancelled'], true)) {
            $controlExpected[$order->store_id] ??= ['net_total' => '0.0000', 'paid_total' => '0.0000', 'pending_total' => '0.0000'];
            foreach (array_keys($controlExpected[$order->store_id]) as $field) {
                $controlExpected[$order->store_id][$field] = bcadd($controlExpected[$order->store_id][$field], $presented[$field], 4);
            }
        }
        $funds = bcsub(bcsub(bcadd($presented['gross_received'], $presented['credit_received_total'], 4), $presented['credit_applied_total'], 4), $presented['refunded_total'], 4);
        if (bccomp($funds, bcadd($presented['paid_total'], $presented['credit_available'], 4), 4) !== 0) {
            throw new RuntimeException('Order cash/credit conservation mismatch for '.$order->id);
        }
        $orders[] = ['id' => $order->id, 'customer' => $order->customer_snapshot['full_name'], 'status' => $order->status, 'total' => $presented['net_total'], 'original_total' => $order->grand_total, 'return_credit_total' => $presented['return_credit_total'], 'credit_available' => $presented['credit_available'], 'refunded_total' => $presented['refunded_total'], 'paid' => $presented['paid_total'], 'pending' => $presented['pending_total']];
        if ($order->status === 'cancelled') {
            continue;
        }
        $total = bcadd($total, $presented['net_total'], 4);
        $paid = bcadd($paid, $presented['paid_total'], 4);
        $pending = bcadd($pending, $presented['pending_total'], 4);
    }
    if (bccomp(bcadd($paid, $pending, 4), $total, 4) !== 0) {
        throw new RuntimeException('Order financial totals disagree.');
    }
    $controls = [];
    foreach (DB::table('stores')->orderBy('id')->pluck('id') as $storeId) {
        $control = app(SalesControl::class)->report($storeId, ['from' => $controlFrom, 'to' => $controlTo]);
        foreach (['net_total', 'paid_total', 'pending_total'] as $field) {
            $value = OrderSettlement::sum(array_column($control['summary'], $field));
            if (bccomp($value, $controlExpected[$storeId][$field] ?? '0', 4) !== 0) {
                throw new RuntimeException('Sales control/order snapshot mismatch for store '.$storeId);
            }
        }
        $controls[] = ['store_id' => $storeId, 'filters' => $control['filters'], 'summary' => $control['summary'], 'cash' => $control['cash'], 'history_count' => $control['meta']['total']];
    }
    foreach (DB::table('payments')->where('status', 'confirmed')->get() as $payment) {
        $fund = OrderSettlement::payment($payment->id);
        if (bccomp($fund['available'], '0', 4) < 0 || bccomp($fund['released'], $fund['allocated'], 4) > 0 || bccomp(bcadd(bcadd($fund['effective_allocated'], $fund['available'], 4), $fund['refunded'], 4), (string) $payment->amount, 4) !== 0) {
            throw new RuntimeException('Payment/credit discrepancy for '.$payment->id);
        }
    }
    foreach (DB::table('payments')->where('status', 'reversed')->get() as $payment) {
        $reversal = DB::table('payment_reversals')->where('payment_id', $payment->id)->first();
        $credit = $sum(DB::table('customer_credit_movements')->where('payment_id', $payment->id)->get(), 'amount_delta');
        $allocated = $sum(DB::table('payment_allocations')->where('payment_id', $payment->id)->get(), 'amount');
        if (! $reversal || bccomp((string) $reversal->amount, (string) $payment->amount, 4) !== 0 || bccomp((string) $reversal->allocated_amount, $allocated, 4) !== 0 || bccomp(bcadd((string) $reversal->allocated_amount, (string) $reversal->credit_voided, 4), (string) $payment->amount, 4) !== 0 || bccomp($credit, '0', 4) !== 0) {
            throw new RuntimeException('Reversed payment/credit discrepancy for '.$payment->id);
        }
    }
    foreach (DB::table('invoice_documents')->where('status', 'ready')->get() as $document) {
        // Existing historical documents remain independently checksum-verifiable.
        $disk = Storage::disk('private_invoices');
        if (! $disk->exists($document->object_key) || ! hash_equals($document->sha256, hash_file('sha256', $disk->path($document->object_key)))) {
            throw new RuntimeException('Invoice PDF checksum mismatch.');
        }
    }
    $financialDocuments = ['pending' => 0, 'ready' => 0, 'failed' => 0];
    foreach (DB::table('credit_application_reversals as v')->join('credit_applications as c', 'c.id', '=', 'v.credit_application_id')->select('v.*', 'c.amount as original_amount')->orderBy('v.id')->get() as $reversal) {
        $snapshot = json_decode($reversal->snapshot, true, flags: JSON_THROW_ON_ERROR);
        if (! hash_equals($reversal->data_hash, hash('sha256', app(FinancialDocument::class)->canonical($snapshot))) || ($snapshot['application']['id'] ?? null) !== (int) $reversal->credit_application_id || bccomp((string) $reversal->amount, (string) $reversal->original_amount, 4) !== 0) {
            throw new RuntimeException('Credit reversal history is inconsistent.');
        }
    }
    foreach (DB::table('financial_documents')->get() as $document) {
        $service = app(FinancialDocument::class);
        $service->snapshot($document);
        if (! array_key_exists($document->status, $financialDocuments)) {
            throw new RuntimeException('Unknown financial document state.');
        }
        $financialDocuments[$document->status]++;
        if ($document->status === 'ready') {
            $service->ready($document->return_adjustment_id ? 'adjustment' : 'credit', (int) ($document->return_adjustment_id ?: $document->credit_application_id));
        }
    }
    foreach (DB::table('sales_return_receipts')->get() as $receipt) {
        foreach (DB::table('sales_return_items')->where('sales_return_id', $receipt->sales_return_id)->get() as $item) {
            $movements = DB::table('inventory_movements')->where('return_item_id', $item->id)->get();
            if (! $item->restock && $movements->isNotEmpty()) {
                throw new RuntimeException('Unrestocked return has an inventory movement.');
            }
            if ($item->restock) {
                $movement = $movements->first();
                if ($movements->count() !== 1 || $movement->movement_type !== 'customer_return' || (int) $movement->warehouse_id !== (int) $receipt->warehouse_id || bccomp((string) $movement->on_hand_delta, (string) $item->quantity, 4) !== 0 || bccomp((string) $movement->reserved_delta, '0', 4) !== 0) {
                    throw new RuntimeException('Return receipt/stock provenance mismatch.');
                }
            }
        }
    }
    echo json_encode(['currency' => 'USD', 'active_products' => Product::where('status', 'active')->count(), 'customers' => Customer::where('status', 'active')->count(), 'stock_balance_rows' => DB::table('stock_balances')->count(), 'physical' => $physical, 'reserved' => $reserved, 'available' => bcsub($physical, $reserved, 4), 'orders' => $orders, 'active_order_total' => $total, 'received' => $paid, 'pending' => $pending, 'invoices' => DB::table('invoices')->count(), 'payments' => DB::table('payments')->count(), 'financial_documents' => $financialDocuments, 'sales_control' => $controls], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
});
echo "PASS: USD, kardex, balances, active reservations, order totals, payments/credit and private PDF checksums. No rows or files changed.\n";
