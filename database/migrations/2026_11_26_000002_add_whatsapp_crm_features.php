<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_conversations')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                if (! Schema::hasColumn('whatsapp_conversations', 'assigned_user_id')) {
                    $table->unsignedBigInteger('assigned_user_id')->nullable()->after('customer_id');
                    $table->index(['store_id', 'assigned_user_id'], 'idx_wa_conv_store_assigned');
                    $table->foreign(['assigned_user_id'], 'fk_wa_conv_assigned_user')->references(['id'])->on('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('whatsapp_conversations', 'crm_stage')) {
                    $table->string('crm_stage', 32)->default('new')->after('assigned_user_id');
                    $table->index(['store_id', 'crm_stage'], 'idx_wa_conv_store_stage');
                }
            });
        }

        if (Schema::hasTable('whatsapp_messages') && ! Schema::hasColumn('whatsapp_messages', 'is_internal')) {
            Schema::table('whatsapp_messages', function (Blueprint $table) {
                $table->boolean('is_internal')->default(false)->after('direction');
            });
        }

        if (! Schema::hasTable('whatsapp_tags')) {
            Schema::create('whatsapp_tags', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->string('name', 40);
                $table->string('color', 16)->default('#6d28d9');
                $table->timestamps(6);

                $table->unique(['store_id', 'name'], 'uq_wa_tag_store_name');
                $table->foreign(['store_id'], 'fk_wa_tag_store')->references(['id'])->on('stores');
            });
        }

        if (! Schema::hasTable('whatsapp_conversation_tags')) {
            Schema::create('whatsapp_conversation_tags', function (Blueprint $table) {
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('tag_id');
                $table->unsignedBigInteger('store_id');
                $table->dateTime('created_at', 6)->nullable();

                $table->primary(['conversation_id', 'tag_id'], 'pk_wa_conv_tag');
                $table->index(['store_id', 'tag_id'], 'idx_wa_conv_tag_store');
                $table->foreign(['conversation_id'], 'fk_wa_ct_conv')->references(['id'])->on('whatsapp_conversations')->cascadeOnDelete();
                $table->foreign(['tag_id'], 'fk_wa_ct_tag')->references(['id'])->on('whatsapp_tags')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('whatsapp_quick_replies')) {
            Schema::create('whatsapp_quick_replies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->string('shortcut', 40);
                $table->string('title', 80);
                $table->text('body');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps(6);

                $table->unique(['store_id', 'shortcut'], 'uq_wa_qr_store_shortcut');
                $table->foreign(['store_id'], 'fk_wa_qr_store')->references(['id'])->on('stores');
                $table->foreign(['created_by_user_id'], 'fk_wa_qr_user')->references(['id'])->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_quick_replies');
        Schema::dropIfExists('whatsapp_conversation_tags');
        Schema::dropIfExists('whatsapp_tags');
    }
};
