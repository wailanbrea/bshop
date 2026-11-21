<?php

namespace App\Domain\Sales;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InvoiceDocument
{
    public function ensure(int $invoiceId): array
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \LogicException('Render PDFs only after the business transaction commits.');
        }
        $disk = Storage::disk('private_invoices');
        $directory = $disk->path('_locks');
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Invoice lock directory unavailable.');
        }
        $handle = fopen($directory.'/'.$invoiceId.'.lock', 'c');
        if (! $handle || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            } abort(409, 'El PDF se está preparando. Reintenta la misma factura.');
        }
        try {
            $document = DB::table('invoice_documents')->where('invoice_id', $invoiceId)->where('version', 1)->firstOrFail();
            if ($document->status === 'ready') {
                if (! $disk->exists($document->object_key) || ! hash_equals($document->sha256, hash_file('sha256', $disk->path($document->object_key)))) {
                    throw new \RuntimeException('Historical invoice PDF is missing or changed. Restore the original backup.');
                }

                return ['status' => 'ready'];
            }
            $invoice = DB::table('invoices')->where('id', $invoiceId)->firstOrFail();
            $weekly = $invoice->weekly_account_id ? DB::table('weekly_accounts')->where('id', $invoice->weekly_account_id)->firstOrFail() : null;
            $items = DB::table('invoice_items')->where('invoice_id', $invoiceId)->orderBy('id')->get();
            $business = json_decode($invoice->business_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $store = DB::table('stores')->where('id', $invoice->store_id)->first();
            if ($store) {
                $business['name'] = $store->name ?: ($business['name'] ?? 'BShop');
                $business['phone'] = $business['phone'] ?? $store->phone;
                $business['address'] = $business['address'] ?? $store->address;
                $business['store_code'] = $business['store_code'] ?? $store->code;
            }
            $customer = OrderInvoice::customer($invoice);
            $settlement = OrderSettlement::invoice($invoice);
            $paymentMethods = DB::table('payment_allocations as pa')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->where('pa.invoice_id', $invoiceId)
                ->where('p.status', 'posted')
                ->distinct()
                ->pluck('p.payment_method')
                ->all();
            $paymentMethodLabels = \App\Domain\Payments\PaymentMethodCatalog::labelsForStore((int) $invoice->store_id);
            $bytes = Pdf::loadView('documents.invoice', compact('invoice', 'items', 'business', 'customer', 'weekly', 'settlement', 'paymentMethods', 'paymentMethodLabels'))->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
            if (! str_starts_with($bytes, '%PDF-')) {
                throw new \RuntimeException('Invalid invoice PDF output.');
            }
            $key = 'invoices/'.$invoiceId.'/v1.pdf';
            $disk->put($key, $bytes);
            $hash = hash('sha256', $bytes);
            if (! hash_equals($hash, hash_file('sha256', $disk->path($key)))) {
                throw new \RuntimeException('Invoice write verification failed.');
            }
            DB::table('invoice_documents')->where('id', $document->id)->update(['status' => 'ready', 'object_key' => $key, 'object_key_hash' => hash('sha256', $key), 'sha256' => $hash, 'byte_size' => strlen($bytes)]);

            return ['status' => 'ready'];
        } catch (\Throwable $error) {
            DB::table('invoice_documents')->where('invoice_id', $invoiceId)->where('status', '!=', 'ready')->update(['status' => 'failed']);
            throw $error;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
