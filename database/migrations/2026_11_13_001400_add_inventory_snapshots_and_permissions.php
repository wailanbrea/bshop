<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('on_hand_after', 19, 4)->nullable();
            $table->decimal('reserved_after', 19, 4)->nullable();
            $table->unsignedBigInteger('balance_version')->nullable();
            $table->index(['store_id', 'warehouse_id', 'id'], 'idx_inventory_store_kardex');
        });
        Schema::table('idempotency_requests', fn (Blueprint $table) => $table->json('response_data')->nullable());
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager', 'seller'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'inventory.view';
            if ($role->code !== 'seller') {
                $permissions[] = 'inventory.manage';
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        if (DB::table('inventory_movements')->whereNotNull('balance_version')->exists()) {
            throw new RuntimeException('Preserve inventory snapshots: rollback refused while movements use this schema.');
        }
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex('idx_inventory_store_kardex');
            $table->dropColumn(['on_hand_after', 'reserved_after', 'balance_version']);
        });
        Schema::table('idempotency_requests', fn (Blueprint $table) => $table->dropColumn('response_data'));
    }
};
