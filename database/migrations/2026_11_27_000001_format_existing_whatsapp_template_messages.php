<?php

use App\Domain\Sales\WhatsAppTemplateDispatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_messages')) {
            return;
        }

        $rows = DB::table('whatsapp_messages')
            ->where('body', 'like', '🔔 Plantilla %')
            ->get(['id', 'conversation_id', 'template_name', 'body']);

        foreach ($rows as $row) {
            if (! preg_match('/^🔔 Plantilla ([a-z0-9_]+):\s*(.+)$/u', (string) $row->body, $m)) {
                continue;
            }
            $tpl = $row->template_name ?: $m[1];
            $params = array_map('trim', explode(' · ', $m[2]));
            $pretty = WhatsAppTemplateDispatcher::renderHumanBody($tpl, $params);

            DB::table('whatsapp_messages')->where('id', $row->id)->update([
                'body' => $pretty,
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('whatsapp_conversations')) {
                $latestId = DB::table('whatsapp_messages')
                    ->where('conversation_id', $row->conversation_id)
                    ->orderByDesc('occurred_at')
                    ->orderByDesc('id')
                    ->value('id');
                if ((int) $latestId === (int) $row->id) {
                    DB::table('whatsapp_conversations')->where('id', $row->conversation_id)->update([
                        'last_message_preview' => Str::limit($pretty, 180),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Safe non-destructive formatting update
    }
};
