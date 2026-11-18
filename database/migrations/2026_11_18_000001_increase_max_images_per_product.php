<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('storage_quotas')) {
            DB::table('storage_quotas')
                ->where('max_images_per_product', '<', 20)
                ->update(['max_images_per_product' => 20]);
        }
    }

    public function down(): void
    {
        // Non-destructive: keep raised image quota
    }
};
