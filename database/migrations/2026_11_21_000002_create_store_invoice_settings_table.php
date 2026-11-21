<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('store_invoice_settings')) {
            return;
        }

        Schema::create('store_invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id')->unique('uq_store_invoice_settings_store');
            $table->string('logo_path', 255)->nullable();
            $table->unsignedSmallInteger('logo_width_px')->default(52);
            $table->string('accent_color', 20)->default('#4f46e5');
            $table->string('document_title', 40)->default('FACTURA');
            $table->string('brand_subtitle', 120)->nullable();
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_product_images')->default(true);
            $table->boolean('show_sku')->default(true);
            $table->boolean('show_store_contact')->default(true);
            $table->boolean('show_customer_contact')->default(true);
            $table->boolean('show_payment_summary')->default(true);
            $table->boolean('show_payment_methods')->default(true);
            $table->boolean('show_notes')->default(true);
            $table->string('footer_text', 240)->default('Gracias por su preferencia · Documento histórico de la operación.');
            $table->text('terms_text')->nullable();
            $table->timestamps();

            $table->foreign(['store_id'], 'fk_store_invoice_settings_store')->references(['id'])->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_invoice_settings');
    }
};
