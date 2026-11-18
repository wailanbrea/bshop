<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\SalesControl;
use App\Domain\Sales\SalesService;
use App\Http\Controllers\Controller;
use App\Models\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesControlController extends Controller
{
    public function index(Request $request, StoreContext $context, SalesControl $control)
    {
        $canSeeCosts = (bool) $request->user()?->permits('catalog.costs');

        return response()->json($control->report($context->store()->id, $request->all(), false, $canSeeCosts))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, StoreContext $context, SalesControl $control)
    {
        $canSeeCosts = (bool) $request->user()?->permits('catalog.costs');
        $report = $control->report($context->store()->id, $request->all(), true, $canSeeCosts);

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, __('sales_control.headers'), ',', '"', '');
            foreach ($report['data'] as $row) {
                $values = [$row['number'], $row['created_at'], $row['customer_name'], $row['kind'], $row['status'], $row['invoice_number'], $row['currency'], $row['original_total'], $row['return_credit_total'], $row['net_total'], $row['paid_total'], $row['pending_total'], $row['credit_available']];
                fputcsv($out, array_map(fn ($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'".$v : $v, $values), ',', '"', '');
            }
            fclose($out);
        }, __('sales_control.filename'), ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function activity(Request $request, StoreContext $context, SalesService $sales)
    {
        return DB::transaction(function () use ($request, $context, $sales) {
            $order = SalesOrder::where('store_id', $context->store()->id)->findOrFail($request->route('order'));
            $payments = DB::table('order_payments as op')->join('payments as p', 'p.id', '=', 'op.payment_id')->leftJoin('payment_reversals as r', 'r.payment_id', '=', 'p.id')->where('op.order_id', $order->id)->orderBy('p.id')->get(['p.id', 'p.amount', 'p.currency', 'p.payment_method', 'p.status', 'p.paid_at', 'r.reason as reversal_reason', 'r.actor_name as reversal_actor', 'r.reversed_at']);
            $adjustments = DB::table('return_adjustments')->where('order_id', $order->id)->orderBy('id')->get(['id', 'sales_return_id', 'amount', 'reason', 'actor_name', 'created_at']);
            foreach ($adjustments as $entry) {
                $entry->reversal = DB::table('return_adjustment_reversals')->where('adjustment_id', $entry->id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }
            $credits = DB::table('credit_applications')->where('target_order_id', $order->id)->orWhere('origin_order_id', $order->id)->orderBy('id')->get(['id', 'origin_order_id', 'target_order_id', 'amount', 'reason', 'actor_name', 'created_at']);
            foreach ($credits as $credit) {
                $credit->reversal = DB::table('credit_application_reversals')->where('credit_application_id', $credit->id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }
            $returns = DB::table('sales_return_receipts as c')->join('sales_returns as r', 'r.id', '=', 'c.sales_return_id')->where('c.order_id', $order->id)->orderBy('c.sales_return_id')->get(['c.sales_return_id', 'c.received_at', 'r.reason', 'c.actor_name']);
            foreach ($returns as $receipt) {
                $receipt->reversal = DB::table('return_receipt_reversals')->where('sales_return_id', $receipt->sales_return_id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }
            $refunds = DB::table('refunds as r')->leftJoin('sales_return_receipts as c', 'c.sales_return_id', '=', 'r.sales_return_id')->leftJoin('order_payments as op', 'op.payment_id', '=', 'r.original_payment_id')->where('r.store_id', $context->store()->id)->where(fn ($q) => $q->where('c.order_id', $order->id)->orWhere(fn ($unused) => $unused->whereNull('r.sales_return_id')->where('op.order_id', $order->id)))->orderBy('r.id')->get(['r.id', 'r.amount', 'r.status', 'r.method', 'r.external_reference', 'r.refunded_at']);
            foreach ($refunds as $refund) {
                $refund->reversal = DB::table('refund_reversals')->where('refund_id', $refund->id)->first(['id', 'reason', 'actor_name', 'created_at']);
            }
            foreach ([$payments, $adjustments, $credits, $refunds] as $entries) {
                foreach ($entries as $entry) {
                    $entry->amount = bcadd((string) $entry->amount, '0', 4);
                }
            }

            return response()->json(['data' => ['order' => $sales->present($order), 'payments' => $payments, 'adjustments' => $adjustments, 'credits' => $credits, 'returns' => $returns, 'refunds' => $refunds]])->header('Cache-Control', 'private, no-store');
        });
    }
}
