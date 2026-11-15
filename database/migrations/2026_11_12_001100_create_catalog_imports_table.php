<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->string('filename', 180);
            $table->char('source_sha256', 64);
            $table->string('status', 20)->default('preview');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('product_count');
            $table->unsignedInteger('error_count')->default(0);
            $table->boolean('requires_costs')->default(false);
            $table->longText('payload');
            $table->longText('result')->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('committed_at', 6)->nullable();
            $table->dateTime('reverted_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['created_by_user_id', 'created_at']);
        });
        SchemaChecks::add('catalog_imports', ['chk_catalog_import_status' => "status IN ('preview','committed','reverted')", 'chk_catalog_import_counts' => 'row_count > 0 AND row_count <= 500 AND product_count > 0 AND product_count <= row_count AND error_count >= 0']);
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_imports');
    }
};
