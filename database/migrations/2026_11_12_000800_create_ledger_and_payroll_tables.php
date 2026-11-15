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
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('return_item_id')->nullable();
            $table->string('movement_type', 32);
            $table->decimal('on_hand_delta', 19, 4)->default('0.0000');
            $table->decimal('reserved_delta', 19, 4)->default('0.0000');
            $table->string('event_key', 160);
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('performed_by_user_id')->nullable();
            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->unsignedBigInteger('store_id');
            $table->index(['performed_by_user_id'], 'fk_im_actor');
            $table->index(['warehouse_id', 'variant_id'], 'fk_im_balance');
            $table->index(['reservation_id', 'warehouse_id', 'variant_id'], 'fk_im_res');
            $table->index(['return_item_id', 'variant_id', 'store_id'], 'fk_im_return');
            $table->index(['variant_id', 'occurred_at'], 'idx_im_variant_date');
            $table->unique(['event_key'], 'uq_im_event');
            $table->foreign(['performed_by_user_id'], 'fk_im_actor')->references(['id'])->on('users');
            $table->foreign(['warehouse_id', 'variant_id'], 'fk_im_balance')->references(['warehouse_id', 'variant_id'])->on('stock_balances');
            $table->foreign(['reservation_id', 'warehouse_id', 'variant_id'], 'fk_im_res')->references(['id', 'warehouse_id', 'variant_id'])->on('stock_reservations');
            $table->foreign(['return_item_id', 'variant_id', 'store_id'], 'fk_im_return')->references(['id', 'variant_id', 'store_id'])->on('sales_return_items');
            $table->foreign(['store_id', 'warehouse_id'], 'fk_movement_store_warehouse')->references(['store_id', 'id'])->on('warehouses');
        });
        SchemaChecks::add('inventory_movements', ['chk_im_not_zero' => 'on_hand_delta <> 0 OR reserved_delta <> 0']);
        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('compensation_plan_id');
            $table->unsignedBigInteger('invoice_item_id');
            $table->unsignedBigInteger('payment_allocation_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();
            $table->string('entry_type', 20);
            $table->decimal('commission_base', 19, 4);
            $table->decimal('commission_rate', 9, 4);
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->string('event_key', 160);
            $table->dateTime('earned_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->index(['invoice_item_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_item');
            $table->index(['payment_allocation_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_pa');
            $table->index(['compensation_plan_id', 'employee_id', 'currency'], 'fk_ce_plan');
            $table->index(['refund_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_refund');
            $table->index(['employee_id', 'earned_at'], 'idx_ce_employee_date');
            $table->unique(['event_key'], 'uq_ce_event');
            $table->unique(['id', 'employee_id', 'currency'], 'uq_ce_payroll_context');
            $table->foreign(['employee_id'], 'fk_ce_employee')->references(['id'])->on('employees');
            $table->foreign(['invoice_item_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_item')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('invoice_items');
            $table->foreign(['payment_allocation_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_pa')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('payment_allocations');
            $table->foreign(['compensation_plan_id', 'employee_id', 'currency'], 'fk_ce_plan')->references(['id', 'employee_id', 'currency'])->on('employee_compensation_plans');
            $table->foreign(['refund_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_ce_refund')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('refunds');
            $table->foreign(['invoice_item_id', 'employee_id', 'compensation_plan_id', 'commission_rate'], 'fk_ce_item_snapshot')->references(['id', 'seller_employee_id', 'commission_plan_id', 'commission_rate_snapshot'])->on('invoice_items');
        });
        SchemaChecks::add('commission_entries', ['chk_ce_type' => 'entry_type IN (\'earn\',\'reversal\',\'adjustment\')', 'chk_ce_rate' => 'commission_rate BETWEEN 0 AND 100', 'chk_ce_sign' => '(entry_type=\'earn\' AND amount >= 0) OR (entry_type=\'reversal\' AND amount <= 0) OR (entry_type=\'adjustment\')', 'chk_commission_source' => '(entry_type=\'earn\' AND payment_allocation_id IS NOT NULL AND refund_id IS NULL) OR (entry_type=\'reversal\' AND (payment_allocation_id IS NOT NULL OR refund_id IS NOT NULL)) OR entry_type=\'adjustment\'']);
        Schema::create('payroll_periods', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('scope_key', 80)->default('company');
            $table->char('currency', 3);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft');
            $table->dateTime('approved_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['store_id'], 'fk_pp_store');
            $table->unique(['scope_key', 'starts_on', 'ends_on'], 'uq_pp_scope');
            $table->unique(['id', 'currency'], 'uq_payroll_period_currency');
            $table->foreign(['store_id'], 'fk_pp_store')->references(['id'])->on('stores');
        });
        SchemaChecks::add('payroll_periods', ['chk_pp_dates' => 'ends_on >= starts_on', 'chk_pp_status' => 'status IN (\'draft\',\'approved\',\'paid\')']);
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('payroll_period_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('compensation_type_snapshot', 20);
            $table->char('currency', 3);
            $table->decimal('salary_amount', 19, 4)->default('0.0000');
            $table->decimal('commission_amount', 19, 4)->default('0.0000');
            $table->decimal('adjustments', 19, 4)->default('0.0000');
            $table->decimal('deductions', 19, 4)->default('0.0000');
            $table->decimal('total_payable', 19, 4)->default('0.0000');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['employee_id'], 'fk_pl_employee');
            $table->unique(['payroll_period_id', 'employee_id'], 'uq_pl_employee_period');
            $table->unique(['id', 'employee_id', 'currency'], 'uq_pl_payroll_context');
            $table->foreign(['employee_id'], 'fk_pl_employee')->references(['id'])->on('employees');
            $table->foreign(['payroll_period_id', 'currency'], 'fk_pl_period')->references(['id', 'currency'])->on('payroll_periods');
        });
        SchemaChecks::add('payroll_lines', ['chk_pl_deductions' => 'salary_amount >= 0 AND deductions >= 0 AND total_payable >= 0']);
        Schema::create('payroll_commission_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payroll_line_id');
            $table->unsignedBigInteger('commission_entry_id');
            $table->unsignedBigInteger('employee_id');
            $table->char('currency', 3);
            $table->dateTime('created_at', 6)->nullable();
            $table->index(['commission_entry_id', 'employee_id', 'currency'], 'fk_pce_entry');
            $table->index(['payroll_line_id', 'employee_id', 'currency'], 'fk_pce_line');
            $table->index(['payroll_line_id'], 'idx_pce_line');
            $table->primary(['commission_entry_id']);
            $table->foreign(['commission_entry_id', 'employee_id', 'currency'], 'fk_pce_entry')->references(['id', 'employee_id', 'currency'])->on('commission_entries');
            $table->foreign(['payroll_line_id', 'employee_id', 'currency'], 'fk_pce_line')->references(['id', 'employee_id', 'currency'])->on('payroll_lines');
        });
        Schema::create('payroll_disbursements', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('payroll_line_id');
            $table->unsignedBigInteger('employee_id');
            $table->decimal('amount', 19, 4);
            $table->char('currency', 3);
            $table->string('payment_method', 30);
            $table->string('reference', 160)->nullable();
            $table->string('event_key', 160);
            $table->dateTime('paid_at', 6);
            $table->dateTime('created_at', 6)->nullable();
            $table->index(['payroll_line_id', 'employee_id', 'currency'], 'fk_pd_line');
            $table->index(['payroll_line_id'], 'idx_pd_line');
            $table->unique(['event_key'], 'uq_pd_event');
            $table->foreign(['payroll_line_id', 'employee_id', 'currency'], 'fk_pd_line')->references(['id', 'employee_id', 'currency'])->on('payroll_lines');
        });
        SchemaChecks::add('payroll_disbursements', ['chk_pd_amount' => 'amount > 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_disbursements');
        Schema::dropIfExists('payroll_commission_entries');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_periods');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('inventory_movements');
    }
};
