<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_whatsapp_configs')) {
            Schema::table('store_whatsapp_configs', function (Blueprint $table) {
                if (! Schema::hasColumn('store_whatsapp_configs', 'owner_phone')) {
                    $table->string('owner_phone', 40)->nullable()->after('last_auto_dispatch_date');
                }
                if (! Schema::hasColumn('store_whatsapp_configs', 'daily_summary_time')) {
                    $table->string('daily_summary_time', 5)->default('21:00')->after('owner_phone');
                }
                if (! Schema::hasColumn('store_whatsapp_configs', 'last_daily_summary_date')) {
                    $table->date('last_daily_summary_date')->nullable()->after('daily_summary_time');
                }
                if (! Schema::hasColumn('store_whatsapp_configs', 'automation_rules')) {
                    $table->json('automation_rules')->nullable()->after('last_daily_summary_date');
                }
            });
        }

        if (! Schema::hasTable('whatsapp_notification_logs')) {
            Schema::create('whatsapp_notification_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->string('event_type', 50);
                $table->string('entity_type', 50)->nullable();
                $table->unsignedBigInteger('entity_id')->nullable();
                $table->string('destination', 40);
                $table->string('template_name', 120);
                $table->string('status', 20)->default('sent'); // sent | failed | skipped
                $table->string('provider_message_id', 160)->nullable();
                $table->string('error_code', 120)->nullable();
                $table->json('payload_preview')->nullable();
                $table->timestamps(6);
                $table->index(['store_id', 'event_type', 'created_at'], 'idx_wa_logs_store_event_date');
                $table->index(['store_id', 'event_type', 'entity_type', 'entity_id'], 'idx_wa_logs_store_entity');
                $table->foreign(['store_id'], 'fk_wa_logs_store')->references(['id'])->on('stores');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_logs');

        if (Schema::hasTable('store_whatsapp_configs')) {
            Schema::table('store_whatsapp_configs', function (Blueprint $table) {
                foreach (['automation_rules', 'last_daily_summary_date', 'daily_summary_time', 'owner_phone'] as $col) {
                    if (Schema::hasColumn('store_whatsapp_configs', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
