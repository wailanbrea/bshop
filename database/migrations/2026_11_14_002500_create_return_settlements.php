<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_adjustments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_return_id')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3);
            foreach (['amount', 'merchandise_credit', 'shipping_credit', 'net_after', 'credit_released'] as $field) {
                $table->decimal($field, 19, 4);
            }
            $table->json('snapshot');
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->dateTime('created_at', 6);
            $table->unique(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'uq_return_adjustment_context');
            $table->foreign(['sales_return_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_adjustment_return')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('sales_returns');
            $table->foreign(['invoice_id', 'order_id'], 'fk_adjustment_source')->references(['invoice_id', 'order_id'])->on('invoice_orders');
        });
        Schema::create('return_adjustment_items', function (Blueprint $table) {
            $table->unsignedBigInteger('sales_return_item_id')->primary();
            $table->foreignId('adjustment_id')->constrained('return_adjustments');
            $table->foreignId('invoice_item_id')->constrained('invoice_items');
            $table->decimal('quantity', 19, 4);
            $table->decimal('credit_amount', 19, 4);
            $table->foreign('sales_return_item_id')->references('id')->on('sales_return_items');
        });
        Schema::create('payment_allocation_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('adjustment_id');
            $table->unsignedBigInteger('payment_allocation_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->dateTime('created_at', 6);
            $table->unique(['adjustment_id', 'payment_allocation_id'], 'uq_return_allocation_release');
            $table->foreign(['adjustment_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_release_adjustment')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('return_adjustments');
            $table->foreign(['payment_allocation_id', 'payment_id', 'customer_id', 'currency'], 'fk_release_allocation')->references(['id', 'payment_id', 'customer_id', 'currency'])->on('payment_allocations');
            $table->foreign(['payment_allocation_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_release_invoice_context')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('payment_allocations');
            $table->foreign(['invoice_id', 'order_id'], 'fk_release_order')->references(['invoice_id', 'order_id'])->on('invoice_orders');
            $table->index(['order_id', 'payment_id']);
        });
        Schema::create('refund_confirmations', function (Blueprint $table) {
            $table->unsignedBigInteger('refund_id')->primary();
            $table->foreignId('adjustment_id')->constrained('return_adjustments');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->string('reason', 255);
            $table->char('reference_hash', 64)->nullable()->unique();
            $table->dateTime('created_at', 6);
            $table->foreign('refund_id')->references('id')->on('refunds');
        });
        SchemaChecks::add('return_adjustments', ['chk_return_adjustment_values' => 'amount >= 0 AND merchandise_credit >= 0 AND shipping_credit >= 0 AND net_after >= 0 AND credit_released >= 0 AND credit_released <= amount']);
        SchemaChecks::add('return_adjustment_items', ['chk_return_credit_item_values' => 'quantity > 0 AND credit_amount >= 0']);
        SchemaChecks::add('payment_allocation_releases', ['chk_allocation_release_positive' => 'amount > 0']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$permissions, 'returns.adjust', 'refunds.record'])), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Return credits and refund confirmations retain financial history. Use a reviewed forward migration.');
    }
};
