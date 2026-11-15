<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->json('customer_snapshot')->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->decimal('tax_rate_snapshot', 9, 4)->nullable();
            $table->foreign(['store_id', 'warehouse_id'], 'fk_order_warehouse_context')->references(['store_id', 'id'])->on('warehouses');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->dateTime('removed_at', 6)->nullable();
            $table->string('image_url_snapshot', 2048)->nullable();
        });
        Schema::table('invoices', fn (Blueprint $table) => $table->json('business_snapshot')->nullable());
        Schema::create('order_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->primary();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3);
            $table->dateTime('created_at', 6);
            $table->foreign(['order_id', 'store_id', 'customer_id', 'currency'], 'fk_op_order')->references(['id', 'store_id', 'customer_id', 'currency'])->on('orders');
            $table->foreign(['payment_id', 'store_id', 'customer_id', 'currency'], 'fk_op_payment')->references(['id', 'store_id', 'customer_id', 'currency'])->on('payments');
        });
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager', 'seller'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions = [...$permissions, 'customers.view', 'customers.manage', 'sales.view', 'sales.create', 'invoices.view', 'payments.record'];
            if ($role->code !== 'seller') {
                $permissions[] = 'sales.cancel';
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNotNull('customer_snapshot')->exists() || DB::table('order_payments')->exists()) {
            throw new RuntimeException('Preserve sales/payment history; rollback refused.');
        }
        Schema::drop('order_payments');
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['removed_at', 'image_url_snapshot']));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('business_snapshot'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('fk_order_warehouse_context');
            $table->dropColumn(['lock_version', 'customer_snapshot', 'estimated_delivery', 'warehouse_id', 'tax_rate_snapshot']);
        });
    }
};
