<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_whatsapp_configs') && ! Schema::hasColumn('store_whatsapp_configs', 'display_phone')) {
            Schema::table('store_whatsapp_configs', function (Blueprint $table) {
                $table->string('display_phone', 32)->nullable()->after('phone_number_id');
            });
        }

        if (Schema::hasTable('store_whatsapp_configs') && Schema::hasColumn('store_whatsapp_configs', 'display_phone')) {
            DB::table('store_whatsapp_configs')
                ->where('store_id', 1)
                ->whereNull('display_phone')
                ->update([
                    'display_phone' => '+13475256436',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Safe non-destructive migration
    }
};
