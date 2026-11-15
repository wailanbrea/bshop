<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_view_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('store_id');
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at', 6);
            $table->dateTime('revoked_at', 6)->nullable();
            $table->timestamps(6);
            $table->foreign(['order_id', 'store_id'])->references(['id', 'store_id'])->on('orders');
            $table->index(['order_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Order view links retain revocation history. Use a reviewed forward migration.');
    }
};
