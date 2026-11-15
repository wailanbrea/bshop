<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Business\StoreContext;
use App\Domain\Sales\InvoiceDocument;
use App\Domain\Sales\OrderSettlement;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InvoiceDocumentController extends Controller
{
    public function index(Request $request, StoreContext $context)
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->index($request, $context));
        }
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1']]);
        $query = DB::table('invoices')->where('invoices.store_id', $context->store()->id)->leftJoin('orders', 'orders.id', '=', 'invoices.order_id')->leftJoin('weekly_accounts as w', 'w.id', '=', 'invoices.weekly_account_id')->select('invoices.id', 'invoices.invoice_number', 'invoices.currency', 'invoices.grand_total', 'invoices.status', 'invoices.issued_at', 'invoices.order_id')->selectRaw('COALESCE(orders.customer_snapshot,w.customer_snapshot) as customer_snapshot');
        if (! empty($data['q'])) {
            $query->whereRaw("invoice_number LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%']);
        }
        $query->selectRaw("EXISTS(SELECT 1 FROM sales_returns r WHERE r.invoice_id=invoices.id AND r.status='received') as return_pending");
        $page = $query->orderByDesc('invoices.id')->paginate(20);
        $rows = $page->getCollection()->map(fn ($invoice) => ['id' => $invoice->id, 'invoice_number' => $invoice->invoice_number, 'currency' => $invoice->currency, 'grand_total' => bcadd((string) $invoice->grand_total, '0', 4), ...OrderSettlement::invoice($invoice), 'status' => $invoice->status, 'issued_at' => $invoice->issued_at, 'order_id' => $invoice->order_id, 'return_pending' => (bool) $invoice->return_pending, 'customer' => $invoice->customer_snapshot ? json_decode($invoice->customer_snapshot, true, flags: JSON_THROW_ON_ERROR) : null]);

        return response()->json(['data' => $rows, 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function prepare(Request $request, StoreContext $context, InvoiceDocument $documents)
    {
        $invoice = DB::table('invoices')->where('store_id', $context->store()->id)->where('id', $request->route('invoice'))->firstOrFail();
        try {
            $documents->ensure($invoice->id);
        } catch (\Throwable) {
            return response()->json(['message' => 'La factura existe; no se pudo preparar su PDF. Reintenta este mismo documento.'], 503);
        }

        return response()->json(['data' => ['status' => 'ready', 'url' => '/api/v1/business/stores/'.$invoice->store_id.'/invoices/'.$invoice->id.'/document']]);
    }

    public function download(Request $request, StoreContext $context)
    {
        $invoice = DB::table('invoices')->where('store_id', $context->store()->id)->where('id', $request->route('invoice'))->firstOrFail();
        $document = DB::table('invoice_documents')->where('invoice_id', $invoice->id)->where('version', 1)->where('status', 'ready')->firstOrFail();
        abort_unless(preg_match('#^invoices/[1-9][0-9]*/v1\.pdf$#D', $document->object_key), 404);
        $disk = Storage::disk('private_invoices');
        abort_unless($disk->exists($document->object_key), 404);
        abort_unless(hash_equals($document->sha256, hash_file('sha256', $disk->path($document->object_key))), 503, 'El archivo histórico requiere restauración.');

        return $disk->download($document->object_key, $invoice->invoice_number.'.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->setPrivate();
    }
}
