<?php

namespace App\Domain\Sales;

use Illuminate\Support\Facades\DB;

final class OrderInvoice
{
    public static function query(int $orderId)
    {
        return DB::table('invoices')->join('invoice_orders as source', 'source.invoice_id', '=', 'invoices.id')->where('source.order_id', $orderId)->select('invoices.*');
    }

    public static function number(int $storeId): string
    {
        $prefix = DB::table('business_settings')->where('id', 1)->value('invoice_prefix');
        DB::table('document_sequences')->insertOrIgnore(['store_id' => $storeId, 'document_type' => 'invoice', 'prefix' => $prefix.'-'.$storeId.'-', 'next_number' => 1]);
        $sequence = DB::table('document_sequences')->where('store_id', $storeId)->where('document_type', 'invoice')->lockForUpdate()->first();
        $number = $sequence->prefix.str_pad((string) $sequence->next_number, 6, '0', STR_PAD_LEFT);
        DB::table('document_sequences')->where('store_id', $storeId)->where('document_type', 'invoice')->update(['next_number' => $sequence->next_number + 1, 'updated_at' => now()]);

        return $number;
    }

    public static function customer(object $invoice): array
    {
        $json = $invoice->weekly_account_id
            ? DB::table('weekly_accounts')->where('id', $invoice->weekly_account_id)->value('customer_snapshot')
            : DB::table('orders')->where('id', $invoice->order_id)->value('customer_snapshot');

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
