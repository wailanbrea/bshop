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
        Schema::create('business_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1);
            $table->unsignedTinyInteger('weekly_close_weekday')->default(4);
            $table->decimal('sales_tax_rate', 9, 4)->default('0.0000');
            $table->unsignedInteger('reserve_expiry_hours')->nullable();
            $table->string('invoice_prefix', 20)->default('FAC');
            $table->json('configuration')->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->primary(['id']);
        });
        SchemaChecks::add('business_settings', ['chk_settings_weekday' => 'weekly_close_weekday BETWEEN 1 AND 7', 'chk_settings_tax' => 'sales_tax_rate BETWEEN 0 AND 100', 'chk_business_settings_singleton' => 'id = 1']);
        Schema::create('employees', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('home_store_id')->nullable();
            $table->string('employee_code', 40);
            $table->string('full_name', 180);
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->boolean('can_sell')->default(1);
            $table->string('status', 20)->default('active');
            $table->date('hired_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['home_store_id'], 'fk_emp_store');
            $table->unique(['employee_code'], 'uq_emp_code');
            $table->unique(['user_id'], 'uq_emp_member');
            $table->foreign(['user_id'], 'fk_emp_member')->references(['id'])->on('users');
            $table->foreign(['home_store_id'], 'fk_emp_store')->references(['id'])->on('stores');
        });
        SchemaChecks::add('employees', ['chk_emp_status' => 'status IN (\'active\',\'inactive\',\'terminated\')']);
        Schema::create('employee_store_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('store_id');
            $table->dateTime('created_at', 6)->nullable();
            $table->index(['store_id'], 'fk_esa_store');
            $table->primary(['employee_id', 'store_id']);
            $table->foreign(['employee_id'], 'fk_esa_emp')->references(['id'])->on('employees');
            $table->foreign(['store_id'], 'fk_esa_store')->references(['id'])->on('stores');
        });
        Schema::create('employee_compensation_plans', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('employee_id');
            $table->string('pay_type', 20);
            $table->char('currency', 3);
            $table->decimal('fixed_salary', 19, 4)->nullable();
            $table->string('salary_period', 20)->nullable();
            $table->decimal('commission_rate', 9, 4)->nullable();
            $table->string('commission_base', 30)->nullable()->default('net_items');
            $table->string('commission_trigger', 20)->nullable()->default('payment');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['employee_id', 'effective_from', 'effective_to'], 'idx_plan_employee_dates');
            $table->unique(['id', 'employee_id'], 'uq_plan_employee');
            $table->unique(['id', 'employee_id', 'currency'], 'uq_plan_employee_currency');
            $table->foreign(['employee_id'], 'fk_plan_emp')->references(['id'])->on('employees');
        });
        SchemaChecks::add('employee_compensation_plans', ['chk_plan_type' => 'pay_type IN (\'salary\',\'commission\')', 'chk_plan_dates' => 'effective_to IS NULL OR effective_to >= effective_from', 'chk_plan_mode' => '(pay_type=\'salary\' AND fixed_salary IS NOT NULL AND fixed_salary >= 0 AND salary_period IS NOT NULL AND salary_period IN (\'weekly\',\'biweekly\',\'monthly\') AND commission_rate IS NULL) OR (pay_type=\'commission\' AND fixed_salary IS NULL AND salary_period IS NULL AND commission_rate IS NOT NULL AND commission_rate BETWEEN 0 AND 100 AND commission_base IS NOT NULL AND commission_base=\'net_items\' AND commission_trigger IS NOT NULL AND commission_trigger=\'payment\')']);
        Schema::create('customers', function (Blueprint $table) {
            $table->id('id');
            $table->string('full_name', 180);
            $table->string('email', 190)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['full_name'], 'idx_customers_name');
            $table->index(['phone'], 'idx_customers_phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
        Schema::dropIfExists('employee_compensation_plans');
        Schema::dropIfExists('employee_store_assignments');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('business_settings');
    }
};
