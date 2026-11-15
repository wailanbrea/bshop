<?php

use App\Infrastructure\Database\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Single-business installation: no tenant/company scope or SaaS subscription tables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name', 130);
            $table->string('slug', 150);
            $table->boolean('is_active')->default(1);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->index(['parent_id'], 'fk_category_parent');
            $table->unique(['slug'], 'uq_category_slug');
            $table->foreign(['parent_id'], 'fk_category_parent')->references(['id'])->on('categories');
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id('id');
            $table->string('name', 130);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['name'], 'uq_brand_name');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->string('unit_code', 20)->default('unit');
            $table->boolean('track_stock')->default(1);
            $table->string('status', 20)->default('active');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['brand_id'], 'fk_prod_brand');
            $table->index(['category_id'], 'idx_product_category');
            $table->index(['name'], 'idx_product_name');
            $table->foreign(['brand_id'], 'fk_prod_brand')->references(['id'])->on('brands');
            $table->foreign(['category_id'], 'fk_prod_category')->references(['id'])->on('categories');
        });
        SchemaChecks::add('products', ['chk_prod_type' => 'product_type IN (\'physical\',\'service\')']);
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id('id');
            $table->string('name', 100);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['name'], 'uq_attr_name');
        });
        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('attribute_id');
            $table->string('value', 120);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unique(['attribute_id', 'id'], 'uq_av_attr_id');
            $table->unique(['attribute_id', 'value'], 'uq_av_value');
            $table->foreign(['attribute_id'], 'fk_av_attr')->references(['id'])->on('product_attributes');
        });
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 90);
            $table->string('barcode', 100)->nullable();
            $table->string('combination_key', 255)->default('default');
            $table->decimal('sale_price', 19, 4);
            $table->decimal('cost_price', 19, 4)->nullable();
            $table->boolean('is_active')->default(1);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['product_id'], 'idx_var_product');
            $table->unique(['barcode'], 'uq_var_barcode');
            $table->unique(['product_id', 'combination_key'], 'uq_var_combination');
            $table->unique(['product_id', 'id'], 'uq_var_product_id');
            $table->unique(['sku'], 'uq_var_sku');
            $table->foreign(['product_id'], 'fk_var_product')->references(['id'])->on('products');
        });
        SchemaChecks::add('product_variants', ['chk_var_price' => 'sale_price >= 0 AND (cost_price IS NULL OR cost_price >= 0)']);
        Schema::create('variant_attribute_values', function (Blueprint $table) {
            $table->unsignedBigInteger('variant_id');
            $table->unsignedBigInteger('attribute_id');
            $table->unsignedBigInteger('value_id');
            $table->index(['attribute_id', 'value_id'], 'fk_vav_value');
            $table->primary(['variant_id', 'attribute_id']);
            $table->foreign(['attribute_id', 'value_id'], 'fk_vav_value')->references(['attribute_id', 'id'])->on('attribute_values');
            $table->foreign(['variant_id'], 'fk_vav_var')->references(['id'])->on('product_variants');
        });
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id('id');
            $table->string('provider', 30)->default('r2');
            $table->string('bucket_name', 150);
            $table->string('staging_provider', 30)->default('local');
            $table->string('staging_bucket_name', 150);
            $table->string('staging_key', 600);
            $table->char('staging_key_hash', 64);
            $table->string('object_key', 600)->nullable();
            $table->char('object_key_hash', 64)->nullable();
            $table->string('original_filename', 255)->nullable();
            $table->string('requested_mime', 100)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('visibility', 12)->default('public');
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->dateTime('ready_at', 6)->nullable();
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->dateTime('deleted_at', 6)->nullable();
            $table->index(['uploaded_by_user_id'], 'fk_media_uploader');
            $table->index(['status', 'created_at'], 'idx_media_status');
            $table->unique(['provider', 'bucket_name', 'object_key_hash'], 'uq_media_final');
            $table->unique(['staging_provider', 'staging_bucket_name', 'staging_key_hash'], 'uq_media_stage');
            $table->foreign(['uploaded_by_user_id'], 'fk_media_uploader')->references(['id'])->on('users');
        });
        SchemaChecks::add('media_assets', ['chk_media_provider' => 'provider IN (\'local\',\'r2\')', 'chk_media_staging_provider' => 'staging_provider IN (\'local\',\'r2\')', 'chk_media_visibility' => 'visibility IN (\'public\',\'private\')', 'chk_media_status' => 'status IN (\'pending\',\'uploaded\',\'processing\',\'ready\',\'rejected\',\'failed\',\'deleted\')', 'chk_media_dimensions' => '(width_px IS NULL OR width_px > 0) AND (height_px IS NULL OR height_px > 0) AND (byte_size IS NULL OR byte_size > 0)']);
        Schema::create('media_upload_sessions', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('media_asset_id');
            $table->string('request_key', 160);
            $table->string('declared_mime', 100);
            $table->unsignedBigInteger('declared_size');
            $table->dateTime('expires_at', 6);
            $table->string('status', 20)->default('issued');
            $table->dateTime('completed_at', 6)->nullable();
            $table->unsignedBigInteger('created_by_user_id');
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->index(['created_by_user_id'], 'fk_mus_creator');
            $table->unique(['media_asset_id'], 'uq_mus_media');
            $table->unique(['request_key'], 'uq_mus_request');
            $table->index(['product_id', 'status'], 'idx_upload_product_state');
            $table->foreign(['created_by_user_id'], 'fk_mus_creator')->references(['id'])->on('users');
            $table->foreign(['media_asset_id'], 'fk_mus_media')->references(['id'])->on('media_assets');
            $table->foreign(['product_id'], 'fk_upload_product')->references(['id'])->on('products');
            $table->foreign(['product_id', 'variant_id'], 'fk_upload_product_variant')->references(['product_id', 'id'])->on('product_variants');
        });
        SchemaChecks::add('media_upload_sessions', ['chk_mus_size' => 'declared_size > 0 AND declared_size <= 10485760', 'chk_mus_status' => 'status IN (\'issued\',\'uploaded\',\'verified\',\'expired\',\'failed\')']);
        Schema::create('product_images', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('media_asset_id');
            $table->string('alt_text', 255)->nullable();
            $table->boolean('is_primary')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('created_at', 6)->nullable();
            $table->dateTime('updated_at', 6)->nullable();
            $table->unsignedBigInteger('primary_product_id')->nullable()->virtualAs('CASE WHEN is_primary = 1 THEN product_id ELSE NULL END');
            $table->index(['media_asset_id'], 'fk_img_asset');
            $table->index(['product_id', 'variant_id'], 'fk_img_var_same_product');
            $table->index(['product_id', 'sort_order'], 'idx_img_product_sort');
            $table->unique(['product_id', 'media_asset_id'], 'uq_img_product_media');
            $table->unique(['primary_product_id'], 'uq_product_primary_image');
            $table->foreign(['media_asset_id'], 'fk_img_asset')->references(['id'])->on('media_assets');
            $table->foreign(['product_id'], 'fk_img_prod')->references(['id'])->on('products');
            $table->foreign(['product_id', 'variant_id'], 'fk_img_var_same_product')->references(['product_id', 'id'])->on('product_variants');
        });
        Schema::create('media_renditions', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('media_asset_id');
            $table->string('rendition', 24);
            $table->string('provider', 30)->default('r2');
            $table->string('namespace_name', 150);
            $table->string('object_key', 600);
            $table->char('object_key_hash', 64);
            $table->string('mime_type', 100);
            $table->unsignedInteger('width_px');
            $table->unsignedInteger('height_px');
            $table->unsignedBigInteger('byte_size');
            $table->char('sha256', 64);
            $table->dateTime('created_at', 6)->nullable();
            $table->index(['media_asset_id'], 'idx_rend_asset');
            $table->unique(['media_asset_id', 'rendition'], 'uq_rend_asset_kind');
            $table->unique(['provider', 'namespace_name', 'object_key_hash'], 'uq_rend_storage');
            $table->foreign(['media_asset_id'], 'fk_rend_media')->references(['id'])->on('media_assets');
        });
        SchemaChecks::add('media_renditions', ['chk_rend_kind' => 'rendition IN (\'thumb\',\'card\',\'detail\')', 'chk_rend_provider' => 'provider IN (\'local\',\'r2\')', 'chk_rend_dimensions' => 'width_px > 0 AND height_px > 0 AND byte_size > 0']);
        Schema::create('storage_quota', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->default(1);
            $table->unsignedBigInteger('max_bytes')->default(2147483648);
            $table->unsignedBigInteger('bytes_ready')->default(0);
            $table->unsignedBigInteger('bytes_pending')->default(0);
            $table->unsignedBigInteger('max_single_upload_bytes')->default(10485760);
            $table->unsignedTinyInteger('max_images_per_product')->default(5);
            $table->dateTime('updated_at', 6)->nullable();
            $table->primary(['id']);
        });
        SchemaChecks::add('storage_quota', ['chk_quota_size' => 'max_bytes > 0 AND max_single_upload_bytes > 0 AND max_images_per_product BETWEEN 1 AND 5', 'chk_quota_current' => 'bytes_ready + bytes_pending <= max_bytes', 'chk_storage_quota_singleton' => 'id = 1']);
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_quota');
        Schema::dropIfExists('media_renditions');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('media_upload_sessions');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
