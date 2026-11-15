<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\InvoiceDocument;
use App\Domain\Sales\WeeklyAccounts;
use App\Http\Controllers\Controller;
use App\Models\WeeklyAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WeeklyAccountController extends Controller
{
    public function recovery(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        $data = $request->validate(['operation' => ['required', 'in:create,attach,detach,close,cancel']]);
        $record = DB::table('idempotency_requests')->where('operation', 'weekly.'.$data['operation'])->where('request_key', strtolower($request->route('key')))->where('status', 'completed')->firstOrFail();
        $result = json_decode($record->response_data, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(($result['_identity'][0] ?? null) === $request->user()->id && ($result['_identity'][1] ?? null) === $context->store()->id, 404);

        return response()->json(['data' => ['account' => $service->present($service->find($context, $result['account_id'])), 'replay' => true]]);
    }

    public function index(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        $data = $request->validate(['status' => ['nullable', 'in:open,closed,all'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = WeeklyAccount::where('store_id', $context->store()->id);
        if (($data['status'] ?? 'open') !== 'all') {
            $query->where('status', $data['status'] ?? 'open');
        }
        $page = $query->orderByDesc('period_start')->orderByDesc('id')->paginate(15);

        return response()->json(['data' => $page->getCollection()->map(fn ($account) => $service->present($account)), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        return response()->json(['data' => $service->present($service->find($context, (int) $request->route('account')))]);
    }

    public function candidates(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $account = $service->find($context, (int) $request->route('account'));
        $page = $service->candidates($account)->orderBy('created_at')->orderBy('id')->paginate(15);

        return response()->json(['data' => $page->getCollection()->map(fn ($o) => ['id' => $o->id, 'number' => $o->basket_number ?: $o->id, 'total' => $o->grand_total, 'currency' => $o->currency, 'created_at' => $o->created_at]), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function create(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        $result = $service->create($context, $request->user(), $request->all());

        return response()->json(['data' => $result], $result['replay'] ? 200 : 201);
    }

    public function cancel(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        return response()->json(['data' => $service->cancel($context, (int) $request->route('account'), $request->user(), $request->all())]);
    }

    public function membership(Request $request, StoreContext $context, WeeklyAccounts $service)
    {
        return response()->json(['data' => $service->membership($context, (int) $request->route('account'), $request->route('action'), $request->user(), $request->all())]);
    }

    public function close(Request $request, StoreContext $context, WeeklyAccounts $service, InvoiceDocument $documents)
    {
        $result = $service->close($context, (int) $request->route('account'), $request->user(), $request->all());
        try {
            $result['document'] = $documents->ensure($result['account']['invoice']['id']);
        } catch (\Throwable) {
            $result['document'] = ['status' => 'failed'];
        }

        return response()->json(['data' => $result]);
    }
}
