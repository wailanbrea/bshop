<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_quota', fn (Blueprint $table) => $table->unsignedBigInteger('bytes_retained')->default(0));
        SchemaChecks::drop('storage_quota', ['chk_quota_current']);
        SchemaChecks::add('storage_quota', ['chk_quota_current' => 'bytes_ready + bytes_pending + bytes_retained <= max_bytes']);
        Schema::table('media_assets', function (Blueprint $table) {
            $table->uuid('storage_token')->nullable();
            $table->boolean('cleanup_pending')->default(false);
            $table->dateTime('cleanup_after', 6)->nullable();
            $table->dateTime('purged_at', 6)->nullable();
            $table->dateTime('private_cleaned_at', 6)->nullable();
        });
        Schema::table('media_upload_sessions', function (Blueprint $table) {
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
        });
        Schema::table('product_images', fn (Blueprint $table) => $table->dateTime('deleted_at', 6)->nullable());
    }

    public function down(): void
    {
        if (DB::table('storage_quota')->where('bytes_retained', '>', 0)->exists() || DB::table('media_assets')->where('cleanup_pending', true)->exists()) {
            throw new RuntimeException('Retained/pending objects must be reconciled before narrowing media accounting.');
        }
        Schema::table('product_images', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
        Schema::table('media_upload_sessions', fn (Blueprint $table) => $table->dropColumn(['source_sha256', 'attempts']));
        Schema::table('media_assets', fn (Blueprint $table) => $table->dropColumn(['storage_token', 'cleanup_pending', 'cleanup_after', 'purged_at', 'private_cleaned_at']));
        SchemaChecks::drop('storage_quota', ['chk_quota_current']);
        SchemaChecks::add('storage_quota', ['chk_quota_current' => 'bytes_ready + bytes_pending <= max_bytes']);
        Schema::table('storage_quota', fn (Blueprint $table) => $table->dropColumn('bytes_retained'));
    }
};
