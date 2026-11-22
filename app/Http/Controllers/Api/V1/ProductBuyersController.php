<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ProductBuyersController extends Controller
{
    public function searchProducts(Request $request, StoreContext $context): JsonResponse
    {
        $storeId = $context->store()->id;
        $q = trim((string) $request->query('q', ''));

        $query = DB::table('products as p')
            ->where('p.store_id', $storeId)
            ->whereNull('p.deleted_at');

        if ($q !== '') {
            $escaped = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $query->where(function ($sub) use ($escaped) {
                $sub->whereRaw("p.name LIKE ? ESCAPE '!'", [$escaped])
                    ->orWhereExists(function ($v) use ($escaped) {
                        $v->select(DB::raw(1))
                            ->from('product_variants as pv')
                            ->whereColumn('pv.product_id', 'p.id')
                            ->whereRaw("pv.sku LIKE ? ESCAPE '!'", [$escaped]);
                    });
            });
        }

        $products = $query
            ->orderByDesc('p.id')
            ->limit(25)
            ->get(['p.id', 'p.name', 'p.status']);

        $productIds = $products->pluck('id')->all();
        $stats = [];
        if ($productIds !== []) {
            $rows = DB::table('order_items as oi')
                ->join('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.store_id', $storeId)
                ->where('o.status', '!=', 'cancelled')
                ->whereIn('pv.product_id', $productIds)
                ->selectRaw('pv.product_id, COUNT(DISTINCT o.customer_id) as buyers_count, SUM(oi.quantity) as units_sold')
                ->groupBy('pv.product_id')
                ->get();

            foreach ($rows as $row) {
                $stats[(int) $row->product_id] = [
                    'buyers_count' => (int) $row->buyers_count,
                    'units_sold' => bcadd((string) ($row->units_sold ?? '0'), '0', 4),
                ];
            }
        }

        $skus = [];
        if ($productIds !== []) {
            foreach (DB::table('product_variants')->whereIn('product_id', $productIds)->select('product_id', 'sku')->get() as $v) {
                $skus[(int) $v->product_id][] = $v->sku;
            }
        }

        return response()->json([
            'data' => $products->map(function ($p) use ($stats, $skus) {
                $pid = (int) $p->id;
                $s = $stats[$pid] ?? ['buyers_count' => 0, 'units_sold' => '0.0000'];

                return [
                    'id' => $pid,
                    'name' => $p->name,
                    'status' => $p->status,
                    'skus' => array_slice($skus[$pid] ?? [], 0, 4),
                    'buyers_count' => $s['buyers_count'],
                    'units_sold' => $s['units_sold'],
                ];
            })->values(),
        ]);
    }

    public function buyers(Request $request, StoreContext $context): JsonResponse
    {
        $storeId = $context->store()->id;
        $productId = (int) $request->route('product');
        $scope = (string) $request->query('scope', 'all');
        if (! in_array($scope, ['all', 'invoiced', 'open'], true)) {
            $scope = 'all';
        }

        $product = DB::table('products')
            ->where('id', $productId)
            ->where('store_id', $storeId)
            ->first(['id', 'name']);

        abort_unless($product, 404, 'Producto no encontrado en esta sucursal.');

        $rowsQuery = DB::table('order_items as oi')
            ->join('product_variants as pv', 'pv.id', '=', 'oi.variant_id')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('invoices as inv', 'inv.order_id', '=', 'o.id')
            ->where('o.store_id', $storeId)
            ->where('pv.product_id', $productId)
            ->where('o.status', '!=', 'cancelled');

        if ($scope === 'invoiced') {
            $rowsQuery->whereNotNull('inv.id');
        } elseif ($scope === 'open') {
            $rowsQuery->whereNull('inv.id');
        }

        $rows = $rowsQuery
            ->orderByDesc('o.created_at')
            ->orderByDesc('oi.id')
            ->select([
                'o.id as order_id',
                'o.basket_number',
                'o.status as order_status',
                'o.order_kind',
                'o.currency',
                'o.created_at as order_created_at',
                'o.customer_id',
                'o.customer_snapshot',
                'c.full_name as customer_name',
                'c.phone as customer_phone',
                'c.email as customer_email',
                'inv.invoice_number',
                'oi.sku_snapshot',
                'oi.quantity',
                'oi.unit_price',
                'oi.line_total',
            ])
            ->get();

        $currency = BusinessProfile::current()->default_currency ?: 'USD';
        $buyersMap = [];
        $orderIds = [];
        $totalUnits = '0.0000';
        $totalRevenue = '0.0000';

        foreach ($rows as $row) {
            $snapshot = $row->customer_snapshot ? json_decode($row->customer_snapshot, true) : [];
            $cid = (int) ($row->customer_id ?? 0);
            $fullName = $row->customer_name ?: ($snapshot['full_name'] ?? 'Cliente');
            $phone = $row->customer_phone ?: ($snapshot['phone'] ?? null);
            $email = $row->customer_email ?: ($snapshot['email'] ?? null);

            $qty = bcadd((string) $row->quantity, '0', 4);
            $lineTotal = bcadd((string) $row->line_total, '0', 4);
            $totalUnits = bcadd($totalUnits, $qty, 4);
            $totalRevenue = bcadd($totalRevenue, $lineTotal, 4);
            $orderIds[(int) $row->order_id] = true;
            if (! empty($row->currency)) {
                $currency = $row->currency;
            }

            if (! isset($buyersMap[$cid])) {
                $buyersMap[$cid] = [
                    'customer_id' => $cid,
                    'full_name' => $fullName,
                    'phone' => $phone,
                    'email' => $email,
                    'orders_seen' => [],
                    'orders_count' => 0,
                    'total_quantity' => '0.0000',
                    'total_spent' => '0.0000',
                    'last_order_at' => $row->order_created_at,
                    'items' => [],
                ];
            }

            if (! isset($buyersMap[$cid]['orders_seen'][(int) $row->order_id])) {
                $buyersMap[$cid]['orders_seen'][(int) $row->order_id] = true;
                $buyersMap[$cid]['orders_count']++;
            }
            $buyersMap[$cid]['total_quantity'] = bcadd($buyersMap[$cid]['total_quantity'], $qty, 4);
            $buyersMap[$cid]['total_spent'] = bcadd($buyersMap[$cid]['total_spent'], $lineTotal, 4);
            $buyersMap[$cid]['items'][] = [
                'order_id' => (int) $row->order_id,
                'basket_number' => $row->basket_number,
                'invoice_number' => $row->invoice_number,
                'status' => $row->order_status,
                'order_kind' => $row->order_kind,
                'sku' => $row->sku_snapshot,
                'quantity' => $qty,
                'unit_price' => bcadd((string) $row->unit_price, '0', 4),
                'line_total' => $lineTotal,
                'created_at' => $row->order_created_at,
            ];
        }

        $buyers = array_values(array_map(function (array $b) {
            unset($b['orders_seen']);

            return $b;
        }, $buyersMap));

        return response()->json([
            'data' => [
                'product' => [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                ],
                'summary' => [
                    'unique_customers' => count($buyers),
                    'total_orders' => count($orderIds),
                    'total_units' => $totalUnits,
                    'total_revenue' => $totalRevenue,
                    'currency' => $currency,
                ],
                'buyers' => $buyers,
            ],
        ]);
    }
}
