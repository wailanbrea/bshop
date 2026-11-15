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
        Schema::create('return_receipt_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->unique()->constrained('sales_returns');
            $table->string('reason', 255);
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('actor_name', 255);
            $table->json('snapshot');
            $table->char('data_hash', 64);
            $table->dateTime('created_at', 6);
        });
        Schema::create('return_receipt_reversal_items', function (Blueprint $table) {
            $table->foreignId('return_item_id')->primary()->constrained('sales_return_items');
            $table->foreignId('reversal_id')->constrained('return_receipt_reversals');
            $table->foreignId('original_movement_id')->nullable()->unique()->constrained('inventory_movements');
            $table->foreignId('inverse_movement_id')->nullable()->unique()->constrained('inventory_movements');
            $table->decimal('quantity', 19, 4);
        });
        SchemaChecks::add('return_receipt_reversal_items', ['chk_receipt_reversal_qty' => 'quantity > 0', 'chk_receipt_reversal_pair' => '(original_movement_id IS NULL AND inverse_movement_id IS NULL) OR (original_movement_id IS NOT NULL AND inverse_movement_id IS NOT NULL)']);
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'returns.reverse';
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Return receipt compensations retain inventory history. Use a reviewed forward migration.');
    }
};
