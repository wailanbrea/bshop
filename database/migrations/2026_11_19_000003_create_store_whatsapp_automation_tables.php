<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_whatsapp_configs')) {
            Schema::create('store_whatsapp_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id')->unique('uq_store_whatsapp_store');
                $table->boolean('enabled')->default(false);
                $table->string('phone_number_id', 60)->nullable();
                $table->string('template', 120)->nullable();
                $table->string('language', 20)->default('es');
                $table->string('graph_version', 20)->default('v23.0');
                $table->json('body_fields')->nullable();
                $table->text('access_token_encrypted')->nullable();
                $table->text('app_secret_encrypted')->nullable();
                $table->text('verify_token_encrypted')->nullable();
                $table->boolean('auto_dispatch_enabled')->default(false);
                $table->unsignedTinyInteger('auto_dispatch_weekday')->default(4); // 0=daily, 1=Mon..7=Sun
                $table->string('auto_dispatch_time', 5)->default('18:00');
                $table->string('auto_dispatch_scope', 40)->default('open_and_invoiced_pending');
                $table->text('auto_dispatch_message')->nullable();
                $table->date('last_auto_dispatch_date')->nullable();
                $table->timestamps(6);
                $table->foreign(['store_id'], 'fk_store_whatsapp_store')->references(['id'])->on('stores');
            });
        }

        if (! Schema::hasTable('whatsapp_dispatch_batches')) {
            Schema::create('whatsapp_dispatch_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('triggered_by_user_id')->nullable();
                $table->string('trigger_type', 20)->default('manual'); // manual | scheduled
                $table->string('channel', 20)->default('meta_api'); // meta_api | wa_me
                $table->unsignedInteger('total_targets')->default(0);
                $table->unsignedInteger('sent_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->json('details')->nullable();
                $table->timestamps(6);
                $table->index(['store_id', 'created_at'], 'idx_wa_batches_store_created');
                $table->foreign(['store_id'], 'fk_wa_batches_store')->references(['id'])->on('stores');
                $table->foreign(['triggered_by_user_id'], 'fk_wa_batches_user')->references(['id'])->on('users');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_dispatch_batches');
        Schema::dropIfExists('store_whatsapp_configs');
    }
};
