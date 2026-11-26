<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_whatsapp_configs')) {
            $row = DB::table('store_whatsapp_configs')->where('store_id', 1)->first();
            if ($row) {
                $rules = $row->automation_rules ? json_decode($row->automation_rules, true) : [];
                if (! is_array($rules)) {
                    $rules = [];
                }
                if (! isset($rules['auto_apartado'])) {
                    $rules['auto_apartado'] = [
                        'enabled' => true,
                        'template' => 'auto_apartado',
                    ];
                    DB::table('store_whatsapp_configs')->where('id', $row->id)->update([
                        'automation_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('whatsapp_messages')) {
            DB::table('whatsapp_messages')
                ->where('body', 'Mensaje recibido (unsupported)')
                ->update([
                    'body' => '🚫 El cliente eliminó un mensaje',
                    'status' => 'deleted',
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('whatsapp_conversations')) {
            DB::table('whatsapp_conversations')
                ->where('last_message_preview', 'Mensaje recibido (unsupported)')
                ->update([
                    'last_message_preview' => '🚫 El cliente eliminó un mensaje',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Safe non-destructive migration
    }
};
