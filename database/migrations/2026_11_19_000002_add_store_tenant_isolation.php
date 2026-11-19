<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('id');
                $table->index(['store_id'], 'idx_products_store');
                $table->foreign(['store_id'], 'fk_products_store')->references(['id'])->on('stores');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('id');
                $table->index(['store_id'], 'idx_customers_store');
                $table->foreign(['store_id'], 'fk_customers_store')->references(['id'])->on('stores');
            }
        });

        // Backfill all existing products and customers to the primary (earliest) store so existing data stays owned by its original store.
        $defaultStoreId = DB::table('stores')->orderBy('id')->value('id');
        if ($defaultStoreId !== null) {
            DB::table('products')->whereNull('store_id')->update(['store_id' => $defaultStoreId]);
            DB::table('customers')->whereNull('store_id')->update(['store_id' => $defaultStoreId]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'store_id')) {
                $table->dropForeign('fk_customers_store');
                $table->dropIndex('idx_customers_store');
                $table->dropColumn('store_id');
            }
        });

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'store_id')) {
                $table->dropForeign('fk_products_store');
                $table->dropIndex('idx_products_store');
                $table->dropColumn('store_id');
            }
        });
    }
};
