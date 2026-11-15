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
        Schema::create('credit_application_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_application_id')->unique()->constrained('credit_applications');
            $table->decimal('amount', 19, 4);
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->json('snapshot');
            $table->char('data_hash', 64);
            $table->dateTime('created_at', 6);
        });
        SchemaChecks::add('credit_application_reversals', ['chk_credit_reversal_amount' => 'amount > 0']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'credits.reverse';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Credit reversals retain financial history. Use a reviewed forward migration.');
    }
};
