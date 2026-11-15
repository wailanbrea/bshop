<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\ReturnSettlements;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnSettlementController extends Controller
{
    public function show(Request $request, StoreContext $context, ReturnSettlements $service)
    {
        return response()->json(['data' => $service->info($context, (int) $request->route('order'), (int) $request->route('receipt'), $request->query())])->header('Cache-Control', 'private, no-store');
    }

    public function adjust(Request $request, StoreContext $context, ReturnSettlements $service)
    {
        return response()->json(['data' => $service->adjust($context, (int) $request->route('order'), (int) $request->route('receipt'), $request->user(), $request->all())])->header('Cache-Control', 'private, no-store');
    }

    public function refund(Request $request, StoreContext $context, ReturnSettlements $service)
    {
        return response()->json(['data' => $service->refund($context, (int) $request->route('order'), (int) $request->route('receipt'), $request->user(), $request->all())])->header('Cache-Control', 'private, no-store');
    }

    public function recovery(Request $request, StoreContext $context, SalesService $sales)
    {
        $data = $request->validate(['operation' => ['required', 'in:adjust,refund']]);
        $row = DB::table('idempotency_requests')->where('operation', 'returns.'.$data['operation'])->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $result = json_decode($row->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($result['_identity'][0] ?? null) === $request->user()->id && ($result['_identity'][1] ?? null) === $context->store()->id && ($result['_identity'][2] ?? null) === (int) $request->route('order') && ($result['_identity'][3] ?? null) === (int) $request->route('receipt'), 404);
        unset($result['_identity']);

        return response()->json(['data' => [...$result, 'order' => $sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($result['order_id'])), 'replay' => true]])->header('Cache-Control', 'private, no-store');
    }
}
