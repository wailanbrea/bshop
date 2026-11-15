<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_link_catalogs', function (Blueprint $table) {
            $table->unsignedBigInteger('share_link_id')->primary();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->json('variant_ids');
            $table->decimal('shipping_total', 19, 4)->default('0.0000');
            $table->foreign(['share_link_id', 'store_id'])->references(['id', 'store_id'])->on('share_links');
            $table->foreign(['store_id', 'warehouse_id'])->references(['store_id', 'id'])->on('warehouses');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Public order authorizations retain history; use a reviewed forward migration.');
    }
};
