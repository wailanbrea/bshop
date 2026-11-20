<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\BasketWhatsAppDispatchService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BasketWhatsAppDispatchController extends Controller
{
    public function overview(StoreContext $context, BasketWhatsAppDispatchService $service)
    {
        return response()->json(['data' => $service->overview($context)]);
    }

    public function saveSchedule(Request $request, StoreContext $context, BasketWhatsAppDispatchService $service)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'weekday' => ['required', 'integer', 'between:0,7'],
            'time' => ['required', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'scope' => ['required', 'string', Rule::in(BasketWhatsAppDispatchService::SCOPES)],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $schedule = $service->saveSchedule($context, $data, $request->user());

        return response()->json(['data' => ['schedule' => $schedule]]);
    }

    public function send(Request $request, StoreContext $context, BasketWhatsAppDispatchService $service)
    {
        $data = $request->validate([
            'customer_ids' => ['nullable', 'array', 'max:200'],
            'customer_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $batch = $service->dispatchNow($context, $data['customer_ids'] ?? null, $request->user(), 'manual');

        return response()->json(['data' => ['batch' => $batch]], 201);
    }

    public function logManual(Request $request, StoreContext $context, BasketWhatsAppDispatchService $service)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:180'],
        ]);

        $batch = $service->logManualWaMe($context, (int) $data['customer_id'], $data['customer_name'], $request->user());

        return response()->json(['data' => ['batch' => $batch]], 201);
    }
}
