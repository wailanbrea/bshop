<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'allow_reservation_without_deposit')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->boolean('allow_reservation_without_deposit')->default(false)->after('status');
            });
        }

        if (! Schema::hasTable('whatsapp_reservation_requests')) {
            Schema::create('whatsapp_reservation_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('store_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('conversation_id')->nullable();
                $table->string('wa_phone', 32);
                $table->string('customer_name', 160);
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('variant_id');
                $table->string('product_name', 180);
                $table->string('variant_label', 120)->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 14, 4)->default(0);
                $table->decimal('line_total', 14, 4)->default(0);
                $table->decimal('required_deposit', 14, 4)->default(0);
                $table->string('currency', 8)->default('USD');
                $table->string('status', 24)->default('pending_deposit'); // pending_deposit | confirmed | cancelled | out_of_stock
                $table->string('provider_message_id', 160)->nullable();
                $table->unsignedBigInteger('confirmed_order_id')->nullable();
                $table->unsignedBigInteger('confirmed_by_user_id')->nullable();
                $table->dateTime('confirmed_at', 6)->nullable();
                $table->timestamps(6);

                $table->index(['store_id', 'status', 'id'], 'idx_wa_res_req_store_status');
                $table->index(['store_id', 'conversation_id', 'status'], 'idx_wa_res_req_conv_status');
                $table->foreign(['store_id'], 'fk_wa_res_req_store')->references(['id'])->on('stores');
                $table->foreign(['customer_id'], 'fk_wa_res_req_customer')->references(['id'])->on('customers')->nullOnDelete();
                $table->foreign(['conversation_id'], 'fk_wa_res_req_conv')->references(['id'])->on('whatsapp_conversations')->nullOnDelete();
                $table->foreign(['confirmed_by_user_id'], 'fk_wa_res_req_user')->references(['id'])->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_reservation_requests');
    }
};
