<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\CreditApplicationReversals;
use App\Domain\Sales\CreditApplications;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreditApplicationController extends Controller
{
    public function reversalInfo(Request $request, StoreContext $context, CreditApplicationReversals $service)
    {
        return response()->json(['data' => $service->info($context, (int) $request->route('order'), (int) $request->route('application'))])->header('Cache-Control', 'private, no-store');
    }

    public function reverse(Request $request, StoreContext $context, CreditApplicationReversals $service)
    {
        return response()->json(['data' => $service->reverse($context, (int) $request->route('order'), (int) $request->route('application'), $request->user(), $request->all())])->header('Cache-Control', 'private, no-store');
    }

    public function reversalRecovery(Request $request, StoreContext $context, SalesService $sales)
    {
        $row = DB::table('idempotency_requests')->where('operation', 'credits.reverse')->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $data = json_decode($row->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($data['_identity'] ?? null) === [$request->user()->id, $context->store()->id, (int) $request->route('order'), (int) $request->route('application')], 404);

        return response()->json(['data' => ['order' => $sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($data['order_id'])), 'credit_application_id' => $data['credit_application_id'], 'reversal_id' => $data['reversal_id'], 'replay' => true]])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, StoreContext $context, CreditApplications $service)
    {
        return response()->json(['data' => $service->info($context, (int) $request->route('order'))])->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, StoreContext $context, CreditApplications $service)
    {
        return response()->json(['data' => $service->apply($context, (int) $request->route('order'), $request->user(), $request->all())])->header('Cache-Control', 'private, no-store');
    }

    public function recovery(Request $request, StoreContext $context, SalesService $sales)
    {
        $row = DB::table('idempotency_requests')->where('operation', 'credits.apply')->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $data = json_decode($row->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($data['_identity'][0] ?? null) === $request->user()->id && ($data['_identity'][1] ?? null) === $context->store()->id && ($data['_identity'][2] ?? null) === (int) $request->route('order'), 404);

        return response()->json(['data' => ['order' => $sales->present(SalesOrder::where('store_id', $context->store()->id)->findOrFail($data['order_id'])), 'credit_application_id' => $data['credit_application_id'], 'replay' => true]])->header('Cache-Control','private, no-store');
    }
}
