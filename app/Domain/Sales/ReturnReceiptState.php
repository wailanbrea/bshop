<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class ReturnReceiptState
{
    public static function pendingInvoice(int $invoiceId, bool $lock = false): bool
    {
        $query = DB::table('sales_returns')->where('invoice_id', $invoiceId)->where('status', 'received');

        return $lock ? (bool) $query->orderBy('id')->lockForUpdate()->first() : $query->exists();
    }
}
