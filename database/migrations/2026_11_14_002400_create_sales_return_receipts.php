<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_return_id')->primary();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->char('currency', 3);
            $table->foreignId('received_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->dateTime('received_at', 6);
            $table->foreign(['sales_return_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_receipt_return')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('sales_returns');
            $table->foreign(['order_id', 'store_id', 'customer_id', 'currency'], 'fk_receipt_order')->references(['id', 'store_id', 'customer_id', 'currency'])->on('orders');
            $table->foreign(['invoice_id', 'order_id'], 'fk_receipt_invoice_source')->references(['invoice_id', 'order_id'])->on('invoice_orders');
            $table->foreign(['store_id', 'warehouse_id'], 'fk_receipt_warehouse')->references(['store_id', 'id'])->on('warehouses');
        });
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'returns.receive';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Return receipt context retains stock and financial history. Use a reviewed forward migration.');
    }
};
