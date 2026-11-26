<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('whatsapp_conversations')) {
            Schema::create('whatsapp_conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('wa_phone', 32);
                $table->string('contact_name', 160)->nullable();
                $table->unsignedInteger('unread_count')->default(0);
                $table->dateTime('last_inbound_at', 6)->nullable();
                $table->dateTime('last_message_at', 6)->nullable();
                $table->string('last_message_preview', 255)->nullable();
                $table->string('last_message_direction', 16)->default('inbound');
                $table->timestamps(6);

                $table->unique(['store_id', 'wa_phone'], 'uq_wa_conv_store_phone');
                $table->index(['store_id', 'last_message_at'], 'idx_wa_conv_store_last');
                $table->index(['store_id', 'unread_count'], 'idx_wa_conv_store_unread');
                $table->foreign(['store_id'], 'fk_wa_conv_store')->references(['id'])->on('stores');
                $table->foreign(['customer_id'], 'fk_wa_conv_customer')->references(['id'])->on('customers')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('whatsapp_messages')) {
            Schema::create('whatsapp_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('conversation_id');
                $table->unsignedBigInteger('store_id');
                $table->string('direction', 16); // inbound | outbound
                $table->string('message_type', 24)->default('text'); // text | image | document | audio | template | other
                $table->text('body')->nullable();
                $table->string('media_id', 120)->nullable();
                $table->string('media_mime', 100)->nullable();
                $table->string('media_filename', 255)->nullable();
                $table->string('template_name', 120)->nullable();
                $table->string('provider_message_id', 160)->nullable();
                $table->string('status', 20)->default('received'); // received | sending | sent | delivered | read | failed
                $table->string('error_code', 120)->nullable();
                $table->unsignedBigInteger('sent_by_user_id')->nullable();
                $table->dateTime('occurred_at', 6);
                $table->timestamps(6);

                $table->unique(['store_id', 'provider_message_id'], 'uq_wa_msg_store_provider');
                $table->index(['conversation_id', 'occurred_at', 'id'], 'idx_wa_msg_conv_time');
                $table->index(['store_id', 'direction', 'id'], 'idx_wa_msg_store_dir_id');
                $table->foreign(['conversation_id'], 'fk_wa_msg_conv')->references(['id'])->on('whatsapp_conversations')->cascadeOnDelete();
                $table->foreign(['store_id'], 'fk_wa_msg_store')->references(['id'])->on('stores');
                $table->foreign(['sent_by_user_id'], 'fk_wa_msg_user')->references(['id'])->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
    }
};
