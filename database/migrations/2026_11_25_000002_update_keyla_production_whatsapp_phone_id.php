<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Switch Keyla (store_id=1) from Meta sandbox test phone ID to verified production phone ID (+1 347-525-6436)
        if (Schema::hasTable('store_whatsapp_configs')) {
            DB::table('store_whatsapp_configs')
                ->where('store_id', 1)
                ->where(function ($q) {
                    $q->whereNull('phone_number_id')
                        ->orWhere('phone_number_id', '')
                        ->orWhere('phone_number_id', '1390917357446329');
                })
                ->update([
                    'phone_number_id' => '1419120884625599',
                    'graph_version' => 'v25.0',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Preserve production phone configuration on rollback.
    }
};
