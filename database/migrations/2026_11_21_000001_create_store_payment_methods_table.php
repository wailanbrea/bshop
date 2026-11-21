<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_payment_methods')) {
            return;
        }

        Schema::create('store_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('code', 30);
            $table->string('label', 60);
            $table->string('bg_color', 20)->default('#ede9fe');
            $table->string('fg_color', 20)->default('#4c1d95');
            $table->text('icon_svg')->nullable();
            $table->boolean('is_custom')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->unique(['store_id', 'code'], 'uq_store_payment_method_code');
            $table->index(['store_id', 'is_enabled'], 'idx_spm_store_enabled');
            $table->foreign(['store_id'], 'fk_store_payment_methods_store')->references(['id'])->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_methods');
    }
};
