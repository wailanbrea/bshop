<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\WhatsAppTemplateDispatcher;
use App\Http\Controllers\Controller;
use App\Rules\DecimalAmount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppAutomationController extends Controller
{
    public function show(StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        return response()->json(['data' => $dispatcher->getAutomations($context->store()->id)]);
    }

    public function update(Request $request, StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        $data = $request->validate([
            'owner_phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9\s().-]{7,25}$/D'],
            'daily_summary_time' => ['required', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/D'],
            'rules' => ['required', 'array'],
            'rules.*.enabled' => ['required', 'boolean'],
            'rules.*.template' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9_]+$/D'],
            'rules.*.hours_before' => ['sometimes', 'integer', 'between:1,168'],
            'rules.*.threshold' => ['sometimes', 'integer', 'between:0,1000'],
        ]);

        return response()->json(['data' => $dispatcher->saveAutomations($context->store(), $data, $request->user())]);
    }

    public function sendReceivablesReminder(Request $request, StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'pending_total' => ['required', new DecimalAmount],
            'reference' => ['required', 'string', 'max:120'],
        ]);

        $result = $dispatcher->sendReceivablesReminder($context->store(), (int) $data['customer_id'], $data['pending_total'], $data['reference']);

        return response()->json(['data' => $result], $result['status'] === 'sent' ? 200 : 409);
    }

    public function sendCatalogLink(Request $request, StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'min:1'],
            'share_url' => ['required', 'url', 'max:500'],
            'expires_at' => ['required', 'string', 'max:60'],
        ]);

        $result = $dispatcher->sendCatalogLink($context->store(), (int) $data['customer_id'], $data['share_url'], $data['expires_at']);

        return response()->json(['data' => $result], $result['status'] === 'sent' ? 200 : 409);
    }

    public function sendWeeklyPdf(Request $request, StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        $accountId = (int) $request->route('account');
        $weekly = DB::table('weekly_accounts')->where('store_id', $context->store()->id)->where('id', $accountId)->where('status', 'closed')->firstOrFail();
        $invoice = DB::table('invoices')->where('store_id', $context->store()->id)->where('weekly_account_id', $weekly->id)->firstOrFail();

        $result = $dispatcher->sendWeeklyClosePdf($context->store(), $weekly, $invoice);

        return response()->json(['data' => $result], $result['status'] === 'sent' ? 200 : 409);
    }

    public function testOwnerSummary(StoreContext $context, WhatsAppTemplateDispatcher $dispatcher)
    {
        $result = $dispatcher->sendOwnerDailySummary($context->store());

        return response()->json(['data' => $result], $result['status'] === 'sent' ? 200 : 409);
    }
}
