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
        Schema::create('refund_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->unique()->constrained('refunds');
            $table->decimal('amount', 19, 4);
            $table->boolean('confirmed_no_money');
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->json('snapshot');
            $table->char('data_hash', 64);
            $table->dateTime('created_at', 6);
        });
        SchemaChecks::add('refund_reversals', ['chk_refund_reversal_amount' => 'amount > 0 AND confirmed_no_money = 1']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'refunds.reverse';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Refund corrections retain financial history. Use a reviewed forward migration.');
    }
};
