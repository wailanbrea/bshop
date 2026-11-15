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
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_allocation_id')->unique();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('origin_order_id');
            $table->unsignedBigInteger('target_order_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->dateTime('created_at', 6);
            $table->foreign(['payment_allocation_id', 'payment_id', 'customer_id', 'currency'], 'fk_credit_application_payment')->references(['id', 'payment_id', 'customer_id', 'currency'])->on('payment_allocations');
            $table->foreign(['payment_allocation_id', 'invoice_id', 'store_id', 'customer_id', 'currency'], 'fk_credit_application_invoice')->references(['id', 'invoice_id', 'store_id', 'customer_id', 'currency'])->on('payment_allocations');
            $table->foreign(['origin_order_id', 'store_id', 'customer_id', 'currency'], 'fk_credit_application_origin')->references(['id', 'store_id', 'customer_id', 'currency'])->on('orders');
            $table->foreign(['target_order_id', 'store_id', 'customer_id', 'currency'], 'fk_credit_application_target')->references(['id', 'store_id', 'customer_id', 'currency'])->on('orders');
            $table->foreign(['invoice_id', 'target_order_id'], 'fk_credit_application_source')->references(['invoice_id', 'order_id'])->on('invoice_orders');
        });
        Schema::create('credit_application_sources', function (Blueprint $table) {
            $table->foreignId('credit_application_id')->constrained('credit_applications');
            $table->foreignId('release_id')->constrained('payment_allocation_releases');
            $table->decimal('amount', 19, 4);
            $table->primary(['credit_application_id', 'release_id']);
        });
        SchemaChecks::add('credit_applications', ['chk_credit_application_amount' => 'amount > 0']);
        SchemaChecks::add('credit_application_sources', ['chk_credit_source_amount' => 'amount > 0']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'credits.apply';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Credit application history must be preserved. Use a reviewed forward migration.');
    }
};
