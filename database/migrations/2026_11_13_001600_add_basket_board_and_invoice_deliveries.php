<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_kind', 20)->default('sale');
            $table->string('basket_number', 24)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
            $table->dateTime('shipped_at', 6)->nullable();
            $table->unique(['store_id', 'basket_number'], 'uq_basket_store_number');
        });
        foreach (DB::table('orders')->orderBy('id')->get() as $order) {
            $reservation = in_array($order->status, ['draft', 'reserved', 'confirmed'], true)
                || DB::table('stock_reservations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $order->id)->select('id'))->exists()
                || DB::table('audit_logs')->where('entity_type', 'orders')->where('entity_id', (string) $order->id)->where('event', 'sales.reservation.created')->exists();
            $issued = DB::table('invoices')->where('order_id', $order->id)->value('issued_at');
            DB::table('orders')->where('id', $order->id)->update(['order_kind' => $reservation ? 'reservation' : 'sale', 'basket_number' => (string) $order->id, 'closed_at' => $issued]);
        }
        Schema::table('customers', fn (Blueprint $table) => $table->unsignedBigInteger('lock_version')->default(0));
        Schema::create('invoice_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('invoice_id')->constrained();
            $table->foreignId('store_id')->constrained();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->uuid('request_key')->unique();
            $table->string('destination', 32);
            $table->string('sender_phone_id', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('provider_message_id', 192)->nullable()->unique();
            $table->string('error_code', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('accepted_at', 6)->nullable();
            $table->dateTime('sent_at', 6)->nullable();
            $table->dateTime('delivered_at', 6)->nullable();
            $table->dateTime('read_at', 6)->nullable();
            $table->dateTime('event_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['invoice_id', 'created_at'], 'idx_delivery_invoice');
        });
    }

    public function down(): void
    {
        if (DB::table('invoice_deliveries')->exists() || DB::table('orders')->whereNotNull('shipped_at')->exists() || DB::table('audit_logs')->where('event', 'like', 'basket.%')->exists()) {
            throw new RuntimeException('Preserve delivery/dispatch history; rollback refused.');
        }
        Schema::drop('invoice_deliveries');
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('lock_version'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique('uq_basket_store_number');
            $table->dropColumn(['order_kind', 'basket_number', 'closed_at', 'shipped_at']);
        });
    }
};
