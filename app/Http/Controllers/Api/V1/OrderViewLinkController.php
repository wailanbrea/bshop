<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\OrderViewLinks;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderViewLinkController extends Controller
{
    public function index(Request $request, StoreContext $context, OrderViewLinks $links)
    {
        $order = $links->order($context, (int) $request->route('order'));

        return response()->json(['data' => DB::table('order_view_links')->where('order_id', $order->id)
            ->orderByRaw('CASE WHEN revoked_at IS NULL AND expires_at > ? THEN 0 ELSE 1 END', [now()])
            ->orderByDesc('id')->limit(20)->get()->map(fn ($link) => $links->present($link))])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, StoreContext $context, OrderViewLinks $links)
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'hours' => ['required', 'integer', 'in:1,24,72,168']]);
        $result = $links->create($context, (int) $request->route('order'), $request->user(), $data);

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, StoreContext $context, OrderViewLinks $links)
    {
        return response()->json(['data' => $links->revoke($context, (int) $request->route('order'), (int) $request->route('link'), $request->user())])->header('Cache-Control', 'private, no-store');
    }
}
