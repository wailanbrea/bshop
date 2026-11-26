<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_invoice_settings')) {
            DB::table('store_invoice_settings')->update([
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Safe non-destructive cache refresh
    }
};
