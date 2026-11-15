<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('order_id');
            $table->foreignId('return_adjustment_id')->nullable()->unique()->constrained('return_adjustments');
            $table->foreignId('credit_application_id')->nullable()->unique()->constrained('credit_applications');
            $table->unsignedInteger('version')->default(1);
            $table->json('snapshot');
            $table->char('data_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('object_key', 255)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->timestamps(6);
            $table->foreign(['order_id', 'store_id'], 'fk_financial_doc_order')->references(['id', 'store_id'])->on('orders');
        });
        SchemaChecks::add('financial_documents', [
            'chk_financial_doc_source' => '(return_adjustment_id IS NOT NULL AND credit_application_id IS NULL) OR (return_adjustment_id IS NULL AND credit_application_id IS NOT NULL)',
            'chk_financial_doc_state' => "version = 1 AND status IN ('pending','ready','failed') AND (status <> 'ready' OR (object_key IS NOT NULL AND sha256 IS NOT NULL AND byte_size IS NOT NULL AND byte_size > 0))",
        ]);
    }

    public function down(): void
    {
        throw new RuntimeException('Financial document snapshots and issued PDFs retain history. Use a reviewed forward migration.');
    }
};
