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
            $activeOrders = [];
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
                $activeOrders[(int) $order->id] = $currency;
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
            if ($activeOrders !== []) {
                $itemRows = DB::table('order_items as oi')
                    ->join('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
                    ->whereIn('oi.order_id', array_keys($activeOrders))
                    ->whereNull('oi.removed_at')
                    ->whereNotNull('pv.cost_price')
                    ->select('oi.order_id', 'oi.quantity', 'pv.cost_price')
                    ->cursor();
                foreach ($itemRows as $itemRow) {
                    $curr = $activeOrders[(int) $itemRow->order_id] ?? null;
                    if ($curr === null || ! isset($summary[$curr])) {
                        continue;
                    }
                    $lineCost = bcmul(bcadd((string) $itemRow->quantity, '0', 4), bcadd((string) $itemRow->cost_price, '0', 4), 4);
                    $summary[$curr]['cost_of_goods'] = bcadd($summary[$curr]['cost_of_goods'], $lineCost, 4);
                }
            }
            foreach ($summary as &$sumItem) {
                $sumItem['gross_profit'] = bcsub($sumItem['net_total'], $sumItem['cost_of_goods'], 4);
            }
            unset($sumItem);
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

            $inventory = $this->inventorySummary($store, $profile->default_currency);

            return ['data' => $rows, 'summary' => array_values($summary ?: [$this->emptySummary($profile->default_currency)]), 'inventory' => $inventory, 'cash' => array_values($cash), 'daily' => array_values($days), 'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'timezone' => $tz], 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / 20)), 'total' => $total]];
        });
    }

    private function inventorySummary(int $store, string $currency): array
    {
        $invested = '0.0000';
        $saleValue = '0.0000';
        $onHandTotal = '0.0000';
        $availableTotal = '0.0000';
        $withoutCost = 0;

        $stockRows = DB::table('stock_balances as sb')
            ->join('warehouses as w', 'w.id', '=', 'sb.warehouse_id')
            ->join('product_variants as pv', 'pv.id', '=', 'sb.variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('w.store_id', $store)
            ->where('w.is_active', true)
            ->whereNull('pv.deleted_at')
            ->where('pv.is_active', true)
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->where('p.product_type', 'physical')
            ->where('p.track_stock', true)
            ->select('sb.on_hand', 'sb.reserved', 'pv.cost_price', 'pv.sale_price')
            ->cursor();

        foreach ($stockRows as $row) {
            $onHand = bcadd((string) ($row->on_hand ?? '0'), '0', 4);
            if (bccomp($onHand, '0', 4) <= 0) {
                continue;
            }
            $reserved = bcadd((string) ($row->reserved ?? '0'), '0', 4);
            $available = bcsub($onHand, $reserved, 4);
            if (bccomp($available, '0', 4) < 0) {
                $available = '0.0000';
            }
            $onHandTotal = bcadd($onHandTotal, $onHand, 4);
            $availableTotal = bcadd($availableTotal, $available, 4);
            if ($row->cost_price === null) {
                $withoutCost++;
            } else {
                $costUnit = bcadd((string) $row->cost_price, '0', 4);
                $invested = bcadd($invested, bcmul($onHand, $costUnit, 4), 4);
            }
            $saleUnit = bcadd((string) ($row->sale_price ?? '0'), '0', 4);
            $saleValue = bcadd($saleValue, bcmul($onHand, $saleUnit, 4), 4);
        }

        return [
            'currency' => $currency,
            'invested_cost' => $invested,
            'sale_value' => $saleValue,
            'estimated_profit' => bcsub($saleValue, $invested, 4),
            'units_on_hand' => $onHandTotal,
            'units_available' => $availableTotal,
            'without_cost_variants' => $withoutCost,
        ];
    }

    private function emptySummary(string $currency): array
    {
        return ['currency' => $currency, 'orders' => 0, 'invoices' => 0, 'cancelled' => 0, 'draft' => 0, 'pending_returns' => 0, ...array_fill_keys(['original_total', 'return_credit_total', 'net_total', 'paid_total', 'pending_total', 'credit_available', 'sales_net', 'unbilled_net', 'cost_of_goods', 'gross_profit'], '0.0000')];
    }
}
