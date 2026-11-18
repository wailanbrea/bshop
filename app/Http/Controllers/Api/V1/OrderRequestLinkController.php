<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\OrderRequestLinks;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderRequestLinkController extends Controller
{
    public function index(Request $request, StoreContext $context, OrderRequestLinks $links)
    {
        $data = $request->validate(['customer_id' => ['required', 'integer', 'min:1']]);
        $rows = DB::table('share_links')->where('store_id', $context->store()->id)->where('customer_id', $data['customer_id'])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('share_link_catalogs')->whereColumn('share_link_id', 'share_links.id'))
            ->orderByRaw("CASE WHEN status = 'active' AND expires_at > ? THEN 0 ELSE 1 END", [now()])->orderByDesc('id')->limit(20)->get();
        $openOrders = DB::table('orders as o')
            ->leftJoin('invoice_orders as io', 'io.order_id', '=', 'o.id')
            ->where('o.store_id', $context->store()->id)
            ->where('o.customer_id', $data['customer_id'])
            ->where('o.order_kind', 'reservation')
            ->whereIn('o.status', ['draft', 'reserved'])
            ->whereNull('o.closed_at')
            ->whereNull('io.invoice_id')
            ->orderByDesc('o.id')
            ->get(['o.id', 'o.basket_number', 'o.warehouse_id', 'o.shipping_total', 'o.grand_total'])
            ->map(fn ($o) => [
                'id' => (int) $o->id,
                'number' => $o->basket_number ?: (string) $o->id,
                'warehouse_id' => (int) $o->warehouse_id,
                'shipping_total' => bcadd((string) $o->shipping_total, '0', 4),
                'total' => bcadd((string) $o->grand_total, '0', 4),
            ])
            ->all();

        return response()->json(['data' => $rows->map(fn ($link) => $links->present($link)), 'open_orders' => $openOrders])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, StoreContext $context, OrderRequestLinks $links)
    {
        $result = $links->issue($context, $request->user(), $request->all());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, StoreContext $context, OrderRequestLinks $links)
    {
        return response()->json(['data' => $links->revoke($context, $request->user(), (int) $request->route('link'))])->header('Cache-Control', 'private, no-store');
    }

    public function info(Request $request, OrderRequestLinks $links)
    {
        return response()->json(['data' => $links->info($request->route('token'))]);
    }

    public function catalog(Request $request, OrderRequestLinks $links)
    {
        return response()->json($links->catalog($request->route('token'), $request->query()));
    }

    public function quote(Request $request, OrderRequestLinks $links)
    {
        return response()->json(['data' => $links->quote($request->route('token'), $request->all())]);
    }

    public function confirm(Request $request, OrderRequestLinks $links)
    {
        $result = $links->confirm($request->route('token'), $request->all());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201);
    }
}
