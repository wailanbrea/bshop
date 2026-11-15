<?php

namespace App\Domain\Sales;

use App\Models\BusinessProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SalesControl
{
    public function report(int $store, array $input, bool $export = false): array
    {
        $filters = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:120'], 'kind' => ['nullable', 'in:all,sale,reservation'],
            'state' => ['nullable', 'in:all,active,invoiced,unbilled,reserved,confirmed,fulfilled,cancelled,draft'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ])->validate();
        $profile = BusinessProfile::current();
        $tz = $profile->timezone;
        $to = CarbonImmutable::parse($filters['to'] ?? CarbonImmutable::now($tz)->toDateString(), $tz)->startOfDay();
        $from = CarbonImmutable::parse($filters['from'] ?? $to->startOfMonth()->toDateString(), $tz)->startOfDay();
        if ($from->greaterThan($to) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['from' => __('sales_control.range')]);
        }
        $bounds = [$from->utc()->format('Y-m-d H:i:s'), $to->addDay()->utc()->format('Y-m-d H:i:s')];

        return DB::transaction(function () use ($store, $filters, $export, $profile, $tz, $from, $to, $bounds) {
            $query = DB::table('orders as o')->leftJoin('invoice_orders as io', 'io.order_id', '=', 'o.id')->leftJoin('invoices as i', 'i.id', '=', 'io.invoice_id')->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
                ->where('o.store_id', $store)->where('o.created_at', '>=', $bounds[0])->where('o.created_at', '<', $bounds[1])
                ->select('o.*', 'i.id as invoice_id', 'i.invoice_number', 'c.full_name as live_name')
                ->selectRaw("EXISTS(SELECT 1 FROM sales_returns r WHERE r.invoice_id=i.id AND r.status='received') as return_pending");
            $kind = $filters['kind'] ?? 'all';
            $state = $filters['state'] ?? 'all';
            if ($kind !== 'all') {
                $query->where('o.order_kind', $kind);
            }
            if ($state === 'active') {
                $query->whereNotIn('o.status', ['draft', 'cancelled']);
            } elseif ($state === 'invoiced') {
                $query->whereNotNull('i.id');
            } elseif ($state === 'unbilled') {
                $query->whereNull('i.id')->whereNotIn('o.status', ['draft', 'cancelled']);
            } elseif ($state !== 'all') {
                $query->where('o.status', $state);
            }
            $search = mb_strtolower(trim($filters['q'] ?? ''));
            $page = (int) ($filters['page'] ?? 1);
            $rows = [];
            $total = 0;
            $summary = [];
            $days = [];
            $invoices = [];
            foreach ($query->orderByDesc('o.created_at')->orderByDesc('o.id')->cursor() as $order) {
                $customer = json_decode($order->customer_snapshot ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                $name = $customer['full_name'] ?? $order->live_name ?? 'Cliente histórico';
                if ($search !== '' && ! str_contains(mb_strtolower($name.' '.$order->live_name.' '.($customer['phone'] ?? '').' '.$order->id.' '.$order->basket_number.' '.$order->invoice_number), $search)) {
                    continue;
                }
                $position = OrderSettlement::position($order, $order->invoice_id);
                $date = CarbonImmutable::parse($order->created_at, config('app.timezone'))->setTimezone($tz)->toDateString();
                $row = ['id' => (int) $order->id, 'number' => $order->basket_number ?: (string) $order->id, 'customer_name' => $name, 'created_at' => $date, 'kind' => $order->order_kind, 'status' => $order->status, 'invoice_number' => $order->invoice_number, 'currency' => $order->currency, 'return_pending' => (bool) $order->return_pending, ...$position];
                if ($export || ($total >= ($page - 1) * 20 && $total < $page * 20)) {
                    $rows[] = $row;
                }
                $total++;
                $currency = $order->currency;
                $summary[$currency] ??= $this->emptySummary($currency);
                $summary[$currency]['orders']++;
                if (in_array($order->status, ['cancelled', 'draft'], true)) {
                    $summary[$currency][$order->status]++;

                    continue;
                }
                foreach (['original_total', 'return_credit_total', 'net_total', 'paid_total', 'pending_total', 'credit_available'] as $field) {
                    $summary[$currency][$field] = bcadd($summary[$currency][$field], $position[$field], 4);
                }
                if ($order->invoice_id) {
                    $invoices[$currency][$order->invoice_id] = true;
                    $summary[$currency]['invoices'] = count($invoices[$currency]);
                    $summary[$currency]['sales_net'] = bcadd($summary[$currency]['sales_net'], $position['net_total'], 4);
                } else {
                    $summary[$currency]['unbilled_net'] = bcadd($summary[$currency]['unbilled_net'], $position['net_total'], 4);
                }
                $summary[$currency]['pending_returns'] += (int) $order->return_pending;
                $key = $date.'|'.$currency;
                $days[$key] ??= ['date' => $date, 'currency' => $currency, 'orders' => 0, 'net_total' => '0.0000'];
                $days[$key]['orders']++;
                $days[$key]['net_total'] = bcadd($days[$key]['net_total'], $position['net_total'], 4);
            }
            ksort($days);
            $cash = [];
            foreach (DB::table('payments')->where('store_id', $store)->where('paid_at', '>=', $bounds[0])->where('paid_at', '<', $bounds[1])->whereIn('status', ['confirmed', 'reversed'])->orderBy('id')->cursor() as $payment) {
                $currency = $payment->currency;
                $cash[$currency] ??= ['currency' => $currency, 'confirmed' => '0.0000', 'reversed' => '0.0000', 'refunded' => '0.0000', 'net_cash' => '0.0000', 'methods' => []];
                $field = $payment->status === 'confirmed' ? 'confirmed' : 'reversed';
                $cash[$currency][$field] = bcadd($cash[$currency][$field], (string) $payment->amount, 4);
                if ($field === 'confirmed') {
                    $cash[$currency]['methods'][$payment->payment_method] = bcadd($cash[$currency]['methods'][$payment->payment_method] ?? '0', (string) $payment->amount, 4);
                }
            }
            foreach (RefundState::effective(DB::table('refunds')->where('store_id', $store)->where('status', 'confirmed')->where('refunded_at', '>=', $bounds[0])->where('refunded_at', '<', $bounds[1])->orderBy('id'))->cursor() as $refund) {
                $currency = $refund->currency;
                $cash[$currency] ??= ['currency' => $currency, 'confirmed' => '0.0000', 'reversed' => '0.0000', 'refunded' => '0.0000', 'net_cash' => '0.0000', 'methods' => []];
                $cash[$currency]['refunded'] = bcadd($cash[$currency]['refunded'], (string) $refund->amount, 4);
            }
            foreach ($cash as &$value) {
                $value['net_cash'] = bcsub($value['confirmed'], $value['refunded'], 4);
                $value['methods'] = array_map(fn ($method, $amount) => ['method' => $method, 'amount' => $amount], array_keys($value['methods']), array_values($value['methods']));
            } unset($value);

            return ['data' => $rows, 'summary' => array_values($summary ?: [$this->emptySummary($profile->default_currency)]), 'cash' => array_values($cash), 'daily' => array_values($days), 'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz], 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / 20)), 'total' => $total]];
        });
    }

    private function emptySummary(string $currency): array
    {
        return ['currency' => $currency, 'orders' => 0, 'invoices' => 0, 'cancelled' => 0, 'draft' => 0, 'pending_returns' => 0, ...array_fill_keys(['original_total', 'return_credit_total', 'net_total', 'paid_total', 'pending_total', 'credit_available', 'sales_net', 'unbilled_net'], '0.0000')];
    }
}
