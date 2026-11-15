<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['products', 'product_variants'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(0));
        }
        foreach (['brands', 'product_attributes', 'attribute_values'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->boolean('is_active')->default(true));
        }
        foreach (DB::table('roles')->whereIn('code', ['owner', 'manager', 'seller'])->get() as $role) {
            $permissions = json_decode($role->permissions, true, flags: JSON_THROW_ON_ERROR);
            $permissions[] = 'catalog.view';
            if ($role->code !== 'seller') {
                $permissions = [...$permissions, 'catalog.manage', 'catalog.costs'];
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique($permissions)), JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void
    {
        foreach (['attribute_values', 'product_attributes', 'brands'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('is_active'));
        }
        foreach (['product_variants', 'products'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('lock_version'));
        }
        // Additive permissions can stay on roles; they grant no access without routes.
    }
};
