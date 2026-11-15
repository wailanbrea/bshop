<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\SalesReturnReceipts;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReturnReceiptController extends Controller
{
    public function show(Request $request, StoreContext $context, SalesReturnReceipts $service)
    {
        return response()->json(['data' => $service->info($context, (int) $request->route('order'))])->header('Cache-Control', 'private, no-store');
    }

    public function receive(Request $request, StoreContext $context, SalesReturnReceipts $service)
    {
        $result = $service->receive($context, (int) $request->route('order'), $request->user(), $request->all());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201)->header('Cache-Control', 'private, no-store');
    }

    public function recovery(Request $request, StoreContext $context, SalesService $sales)
    {
        $row = DB::table('idempotency_requests')->where('operation', 'returns.receive')->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $data = json_decode($row->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($data['_identity'][0] ?? null) === $request->user()->id && ($data['_identity'][1] ?? null) === $context->store()->id && ($data['_identity'][2] ?? null) === (int) $request->route('order'), 404);

        return response()->json(['data' => ['order' => $sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($data['order_id'])), 'sales_return_id' => $data['sales_return_id'], 'economic_status' => 'pending_adjustment', 'replay' => true]])->header('Cache-Control','private, no-store');
    }
}
