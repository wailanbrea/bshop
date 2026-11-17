<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        SchemaChecks::drop('storage_quota', ['chk_quota_size']);
        SchemaChecks::add('storage_quota', [
            'chk_quota_size' => 'max_bytes > 0 AND max_single_upload_bytes > 0 AND max_images_per_product BETWEEN 1 AND 100',
        ]);
        DB::table('storage_quota')->where('max_images_per_product', 5)->update(['max_images_per_product' => 100]);
    }

    public function down(): void
    {
        throw new RuntimeException('La reducción del límite requiere revisar primero las fotos existentes.');
    }
};
