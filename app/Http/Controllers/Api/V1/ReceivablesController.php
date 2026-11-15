<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\OrderSettlement;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceivablesController extends Controller
{
    public function index(Request $request, StoreContext $context)
    {
        [$rows, $summary] = $this->report($request, $context);
        $page = max(1, (int) $request->input('page', 1));

        return response()->json([
            'data' => array_slice($rows, ($page - 1) * 20, 20),
            'summary' => array_values($summary),
            'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil(count($rows) / 20)), 'total' => count($rows)],
        ]);
    }

    public function export(Request $request, StoreContext $context)
    {
        [$rows] = $this->report($request, $context);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Cliente', 'Teléfono', 'Documento', 'Fecha', 'Antigüedad (días)', 'Moneda', 'Total neto', 'Abonado aplicado', 'Pendiente', 'Conciliación de devolución', 'Total original', 'Crédito por devolución', 'Saldo a favor', 'Reembolso registrado'], ',', '"', '');
            foreach ($rows as $row) {
                $values = [$row['customer_name'], $row['phone'] ?? '', $row['reference'], $row['date'], $row['age_days'], $row['currency'], $row['total'], $row['paid'], $row['pending'], $row['return_pending'] ? 'Pendiente de ajuste económico' : '', $row['original_total'], $row['return_credit_total'], $row['credit_available'], $row['refunded_total']];
                $values = array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'".$v : $v, $values);
                fputcsv($out, $values, ',', '"', '');
            }
            fclose($out);
        }, 'cuentas-por-cobrar.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function report(Request $request, StoreContext $context): array
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->report($request, $context));
        }
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', 'in:all,invoiced,unbilled'],
            'state' => ['nullable', 'in:all,partial,unpaid'],
            'min_days' => ['nullable', 'integer', 'in:0,30,60,90'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $storeId = $context->store()->id;
        // Sum strings with BCMath: SQLite SUM would introduce floating point into money.
        $paid = [];
        foreach (DB::table('order_payments as op')->join('payments as p', 'p.id', '=', 'op.payment_id')
            ->where('op.store_id', $storeId)->where('p.store_id', $storeId)->where('p.status', 'confirmed')
            ->select('op.order_id', 'p.amount')->orderBy('op.order_id')->cursor() as $payment) {
            $paid[$payment->order_id] = bcadd($paid[$payment->order_id] ?? '0', (string) $payment->amount, 4);
        }
        $query = DB::table('orders as o')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('invoice_orders as source', 'source.order_id', '=', 'o.id')->leftJoin('invoices as i', 'i.id', '=', 'source.invoice_id')
            ->where('o.store_id', $storeId)->where('o.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereNull('i.id')->orWhereNotIn('i.status', ['void', 'refunded']))
            ->select('o.id', 'o.customer_id', 'o.customer_snapshot', 'o.basket_number', 'o.currency', 'o.grand_total', 'o.created_at', 'c.full_name', 'c.phone', 'i.invoice_number', 'i.issued_at', 'i.id as invoice_id')
            ->selectRaw("EXISTS(SELECT 1 FROM sales_returns r WHERE r.invoice_id=i.id AND r.status='received') as return_pending");
        if (($data['type'] ?? 'all') === 'invoiced') {
            $query->whereNotNull('i.id');
        }
        if (($data['type'] ?? 'all') === 'unbilled') {
            $query->whereNull('i.id');
        }
        $timezone = BusinessProfile::current()->timezone;
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $rows = [];
        $summary = [];
        $customers = [];
        $documents = [];
        $search = mb_strtolower(trim($data['q'] ?? ''));
        foreach ($query->orderBy('o.created_at')->orderBy('o.id')->cursor() as $order) {
            $position = OrderSettlement::position($order, $order->invoice_id);
            $total = $position['net_total'];
            $received = $position['paid_total'];
            $pending = $position['pending_total'];
            if (bccomp($pending, '0', 4) <= 0) {
                continue;
            }
            if (($data['state'] ?? 'all') === 'partial' && bccomp($received, '0', 4) <= 0) {
                continue;
            }
            if (($data['state'] ?? 'all') === 'unpaid' && bccomp($received, '0', 4) > 0) {
                continue;
            }
            $snapshot = json_decode($order->customer_snapshot ?? '{}', true) ?? [];
            $name = $order->full_name ?? $snapshot['full_name'] ?? 'Cliente histórico';
            $phone = $order->phone ?? $snapshot['phone'] ?? null;
            $reference = $order->invoice_number ?? 'Apartado #'.($order->basket_number ?: $order->id);
            if ($search !== '' && ! str_contains(mb_strtolower($name.' '.$phone.' '.$reference), $search)) {
                continue;
            }
            $date = CarbonImmutable::parse($order->issued_at ?? $order->created_at, config('app.timezone'))->setTimezone($timezone);
            $age = max(0, (int) $date->startOfDay()->diffInDays($today, false));
            if ($age < ($data['min_days'] ?? 0)) {
                continue;
            }
            $rows[] = ['order_id' => $order->id, 'customer_id' => $order->customer_id, 'customer_name' => $name,
                'phone' => $phone, 'reference' => $reference, 'invoiced' => $order->invoice_number !== null,
                'date' => $date->toDateString(), 'age_days' => $age, 'currency' => $order->currency,
                'total' => $total, 'original_total' => $position['original_total'], 'return_credit_total' => $position['return_credit_total'], 'credit_available' => $position['credit_available'], 'refunded_total' => $position['refunded_total'], 'paid' => $received, 'pending' => $pending, 'return_pending' => (bool) $order->return_pending];
            $currency = $order->currency;
            $summary[$currency] ??= ['currency' => $currency, 'total' => '0.0000', 'paid' => '0.0000', 'pending' => '0.0000', 'documents' => 0, 'customers' => 0];
            foreach (['total' => $total, 'paid' => $received, 'pending' => $pending] as $key => $value) {
                $summary[$currency][$key] = bcadd($summary[$currency][$key], $value, 4);
            }
            $documents[$currency][$order->invoice_id ? 'invoice:'.$order->invoice_id : 'order:'.$order->id] = true;
            $summary[$currency]['documents'] = count($documents[$currency]);
            $customers[$currency][$order->customer_id] = true;
            $summary[$currency]['customers'] = count($customers[$currency]);
        }
        usort($rows, fn ($a, $b) => ($b['age_days'] <=> $a['age_days']) ?: ($a['order_id'] <=> $b['order_id']));

        return [$rows, $summary];
    }
}
