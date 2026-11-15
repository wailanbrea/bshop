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
        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->char('currency', 3);
            $table->decimal('amount', 19, 4);
            $table->decimal('allocated_amount', 19, 4);
            $table->decimal('credit_voided', 19, 4);
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->dateTime('reversed_at', 6);
            $table->foreign(['payment_id', 'store_id', 'customer_id', 'currency'], 'fk_reversal_payment')->references(['id', 'store_id', 'customer_id', 'currency'])->on('payments');
            $table->foreign(['order_id', 'store_id', 'customer_id', 'currency'], 'fk_reversal_order')->references(['id', 'store_id', 'customer_id', 'currency'])->on('orders');
        });
        SchemaChecks::add('payment_reversals', ['chk_payment_reversal_values' => 'amount > 0 AND allocated_amount >= 0 AND credit_voided >= 0']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'payments.reverse';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Payment reversal history is append-only; use a reviewed forward migration.');
    }
};
