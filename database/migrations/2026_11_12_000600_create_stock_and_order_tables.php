<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single-business installation: no tenant/company scope or SaaS subscription tables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('variant_id');
            $table->decimal('on_hand', 19, 4)->default('0.0000');
            $table->decimal('reserved', 19, 4)->default('0.0000');
            $table->unsignedBigInteger('version')->default(0);
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['variant_id'], 'idx_stock_variant');
            $table->primary(['warehouse_id', 'variant_id']);
            $table->foreign(['variant_id'], 'fk_stock_var')->references(['id'])->on('product_variants');
            $table->foreign(['warehouse_id'], 'fk_stock_wh')->references(['id'])->on('warehouses');
        });
        SchemaChecks::add('stock_balances', ['chk_stock_nonneg' => 'on_hand >= 0 AND reserved >= 0 AND reserved <= on_hand']);
        Schema::create('share_links', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->char('token_hash', 64);
            $table->string('status', 20)->default('active');
            $table->dateTime('expires_at', 6);
            $table->dateTime('used_at', 6)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['created_by_user_id'], 'fk_link_creator');
            $table->index(['customer_id'], 'fk_link_customer');
            $table->index(['store_id'], 'fk_link_store');
            $table->index(['status', 'expires_at'], 'idx_link_expiry');
            $table->unique(['id', 'store_id'], 'uq_link_store_id');
            $table->unique(['token_hash'], 'uq_link_token');
            $table->foreign(['created_by_user_id'], 'fk_link_creator')->references(['id'])->on('users');
            $table->foreign(['customer_id'], 'fk_link_customer')->references(['id'])->on('customers');
            $table->foreign(['store_id'], 'fk_link_store')->references(['id'])->on('stores');
        });
        SchemaChecks::add('share_links', ['chk_link_status' => 'status IN (\'active\',\'used\',\'revoked\',\'expired\')']);
        Schema::create('weekly_accounts', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('open');
            $table->dateTime('closed_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['customer_id'], 'fk_wa_customer');
            $table->unique(['id', 'store_id', 'customer_id'], 'uq_wa_context');
            $table->unique(['store_id', 'customer_id', 'period_start', 'period_end'], 'uq_wa_cycle');
            $table->foreign(['customer_id'], 'fk_wa_customer')->references(['id'])->on('customers');
            $table->foreign(['store_id'], 'fk_wa_store')->references(['id'])->on('stores');
        });
        SchemaChecks::add('weekly_accounts', ['chk_wa_period' => 'period_end >= period_start', 'chk_wa_status' => 'status IN (\'open\',\'closed\',\'cancelled\')']);
        Schema::create('orders', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('weekly_account_id')->nullable();
            $table->unsignedBigInteger('share_link_id')->nullable();
            $table->unsignedBigInteger('seller_employee_id')->nullable();
            $table->string('channel', 20)->default('manual');
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3);
            $table->decimal('subtotal', 19, 4)->default('0.0000');
            $table->decimal('discount_total', 19, 4)->default('0.0000');
            $table->decimal('tax_total', 19, 4)->default('0.0000');
            $table->decimal('shipping_total', 19, 4)->default('0.0000');
            $table->decimal('grand_total', 19, 4)->default('0.0000');
            $table->text('notes')->nullable();
            $table->dateTime('confirmed_at', 6)->nullable();
            $table->dateTime('fulfilled_at', 6)->nullable();
            $table->dateTime('cancelled_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['share_link_id', 'store_id'], 'fk_order_link');
            $table->index(['store_id'], 'fk_order_store');
            $table->index(['weekly_account_id', 'store_id', 'customer_id'], 'fk_order_weekly');
            $table->index(['customer_id', 'status'], 'idx_order_customer_status');
            $table->index(['seller_employee_id'], 'idx_order_seller');
            $table->index(['weekly_account_id'], 'idx_order_weekly');
            $table->unique(['id', 'store_id', 'customer_id'], 'uq_order_context');
            $table->unique(['id', 'store_id', 'customer_id', 'currency'], 'uq_order_context_currency');
            $table->unique(['share_link_id'], 'uq_order_link');
            $table->unique(['id', 'store_id'], 'uq_order_store');
            $table->unique(['id', 'weekly_account_id'], 'uq_order_weekly_source');
            $table->foreign(['customer_id'], 'fk_order_customer')->references(['id'])->on('customers');
            $table->foreign(['share_link_id', 'store_id'], 'fk_order_link')->references(['id', 'store_id'])->on('share_links');
            $table->foreign(['seller_employee_id'], 'fk_order_seller')->references(['id'])->on('employees');
            $table->foreign(['store_id'], 'fk_order_store')->references(['id'])->on('stores');
            $table->foreign(['weekly_account_id', 'store_id', 'customer_id'], 'fk_order_weekly')->references(['id', 'store_id', 'customer_id'])->on('weekly_accounts');
        });
        SchemaChecks::add('orders', ['chk_order_totals' => 'subtotal >= 0 AND discount_total >= 0 AND tax_total >= 0 AND shipping_total >= 0 AND grand_total >= 0', 'chk_order_status' => 'status IN (\'draft\',\'reserved\',\'confirmed\',\'fulfilled\',\'cancelled\')']);
        Schema::create('order_items', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('seller_employee_id')->nullable();
            $table->decimal('quantity', 19, 4);
            $table->decimal('unit_price', 19, 4);
            $table->decimal('line_discount', 19, 4)->default('0.0000');
            $table->decimal('line_tax', 19, 4)->default('0.0000');
            $table->decimal('line_total', 19, 4);
            $table->string('product_name_snapshot', 200);
            $table->string('sku_snapshot', 90);
            $table->json('variant_snapshot')->nullable();
            $table->unsignedBigInteger('commission_plan_id')->nullable();
            $table->decimal('commission_rate_snapshot', 9, 4)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['order_id', 'store_id'], 'fk_oi_order');
            $table->index(['commission_plan_id', 'seller_employee_id'], 'fk_oi_plan');
            $table->index(['seller_employee_id'], 'fk_oi_seller');
            $table->index(['order_id'], 'idx_oi_order');
            $table->index(['variant_id'], 'idx_oi_variant');
            $table->unique(['id', 'store_id', 'variant_id'], 'uq_oi_store_variant');
            $table->unique(['id', 'variant_id'], 'uq_oi_variant_id');
            $table->unique(['id', 'order_id', 'store_id', 'variant_id'], 'uq_order_item_source');
            $table->foreign(['order_id', 'store_id'], 'fk_oi_order')->references(['id', 'store_id'])->on('orders');
            $table->foreign(['commission_plan_id', 'seller_employee_id'], 'fk_oi_plan')->references(['id', 'employee_id'])->on('employee_compensation_plans');
            $table->foreign(['seller_employee_id'], 'fk_oi_seller')->references(['id'])->on('employees');
            $table->foreign(['variant_id'], 'fk_oi_var')->references(['id'])->on('product_variants');
        });
        SchemaChecks::add('order_items', ['chk_oi_amount' => 'quantity > 0 AND unit_price >= 0 AND line_discount >= 0 AND line_tax >= 0 AND line_total >= 0 AND (commission_rate_snapshot IS NULL OR commission_rate_snapshot BETWEEN 0 AND 100) AND (commission_plan_id IS NULL OR seller_employee_id IS NOT NULL)']);
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('variant_id');
            $table->decimal('quantity', 19, 4);
            $table->decimal('fulfilled_quantity', 19, 4)->default('0.0000');
            $table->decimal('released_quantity', 19, 4)->default('0.0000');
            $table->string('status', 20)->default('active');
            $table->dateTime('expires_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['warehouse_id', 'variant_id'], 'fk_res_balance');
            $table->index(['order_item_id', 'store_id', 'variant_id'], 'fk_res_order_item');
            $table->index(['store_id', 'warehouse_id'], 'fk_res_warehouse_store');
            $table->index(['status', 'expires_at'], 'idx_res_expiry');
            $table->unique(['id', 'warehouse_id', 'variant_id'], 'uq_res_context');
            $table->unique(['order_item_id', 'warehouse_id'], 'uq_res_order_item_wh');
            $table->foreign(['warehouse_id', 'variant_id'], 'fk_res_balance')->references(['warehouse_id', 'variant_id'])->on('stock_balances');
            $table->foreign(['order_item_id', 'store_id', 'variant_id'], 'fk_res_order_item')->references(['id', 'store_id', 'variant_id'])->on('order_items');
            $table->foreign(['store_id', 'warehouse_id'], 'fk_res_warehouse_store')->references(['store_id', 'id'])->on('warehouses');
        });
        SchemaChecks::add('stock_reservations', ['chk_res_qty' => 'quantity > 0 AND fulfilled_quantity >= 0 AND released_quantity >= 0 AND fulfilled_quantity + released_quantity <= quantity', 'chk_res_status' => 'status IN (\'active\',\'fulfilled\',\'released\',\'expired\')']);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('weekly_accounts');
        Schema::dropIfExists('share_links');
        Schema::dropIfExists('stock_balances');
    }
};
