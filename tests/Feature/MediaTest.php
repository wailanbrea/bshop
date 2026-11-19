<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Media\GalleryService;
use App\Domain\Media\MediaUploadService;
use App\Domain\Media\ObjectStorage;
use App\Models\AttributeValue;
use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\MediaUploadSession;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\StorageQuota;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.provider' => 'local']);
        Storage::fake('media_staging');
        Storage::fake('media_catalog');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'Media QA', 'default_currency' => 'DOP', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $this->actingAs($this->owner);
        $this->product = $this->postJson('/api/v1/business/products', ['name' => 'Producto foto', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [['sku' => 'PHOTO', 'sale_price' => '25', 'attribute_value_ids' => []]]])->assertCreated()->json('data.id');
    }

    private function upload(?string $key = null, ?UploadedFile $file = null)
    {
        return $this->postJson("/api/v1/business/products/{$this->product}/images", ['request_key' => $key ?? (string) Str::uuid(), 'file' => $file ?? UploadedFile::fake()->image('camera.jpg', 800, 600), 'alt_text' => 'Foto QA']);
    }

    private function gallery(): array
    {
        return $this->getJson("/api/v1/business/products/{$this->product}/images")->assertOk()->json('data');
    }

    public function test_only_verified_derivatives_are_linked_and_private_original_is_removed(): void
    {
        $this->upload()->assertCreated();
        $gallery = $this->gallery();
        $this->assertCount(1, $gallery['images']);
        $image = $gallery['images'][0];
        $this->assertTrue($image['is_primary']);
        $this->assertSame(320, $image['renditions']['thumb']['width']);
        $this->assertSame(640, $image['renditions']['card']['width']);
        $this->assertSame(800, $image['renditions']['detail']['width']);
        $asset = MediaAsset::with('renditions')->firstOrFail();
        Storage::disk('media_staging')->assertMissing($asset->staging_key);
        $this->assertSame($asset->renditions->sum('byte_size'), StorageQuota::find(1)->bytes_ready);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
        $response = $this->get($image['renditions']['thumb']['url'])->assertOk()->assertHeader('content-type', 'image/webp')->assertHeader('x-content-type-options', 'nosniff');
        $thumb = $asset->renditions->firstWhere('rendition', 'thumb');
        $this->assertSame(Storage::disk('media_catalog')->get($thumb->object_key), $response->streamedContent());
        $this->get(str_replace('thumb/', 'original/', $image['renditions']['thumb']['url']))->assertNotFound();
        $this->getJson("/api/v1/business/products/{$this->product}")->assertOk()->assertJsonPath('data.product.images.0.id', $image['id']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'media.published']);
    }

    public function test_inventory_uses_variant_thumbnail_then_primary_and_searches_attributes(): void
    {
        $color = ProductAttribute::create(['name' => 'Color', 'is_active' => true]);
        $green = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Verde', 'is_active' => true]);
        $black = AttributeValue::create(['attribute_id' => $color->id, 'value' => 'Negro', 'is_active' => true]);
        $size = ProductAttribute::create(['name' => 'Talla', 'is_active' => true]);
        $small = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'S', 'is_active' => true]);
        $medium = AttributeValue::create(['attribute_id' => $size->id, 'value' => 'M', 'is_active' => true]);
        $material = ProductAttribute::create(['name' => 'Material', 'is_active' => true]);
        $cotton = AttributeValue::create(['attribute_id' => $material->id, 'value' => 'Algodón', 'is_active' => true]);
        $silk = AttributeValue::create(['attribute_id' => $material->id, 'value' => 'Seda', 'is_active' => true]);
        $this->product = $this->postJson('/api/v1/business/products', ['name' => 'Blusa QA', 'product_type' => 'physical', 'track_stock' => true, 'unit_code' => 'unit', 'status' => 'active', 'variants' => [
            ['sku' => 'PHOTO-BLACK', 'sale_price' => '25', 'attribute_value_ids' => [$black->id, $small->id, $cotton->id]],
            ['sku' => 'PHOTO-GREEN', 'sale_price' => '25', 'attribute_value_ids' => [$green->id, $small->id, $cotton->id]],
            ['sku' => 'PHOTO-GREEN-M', 'sale_price' => '25', 'attribute_value_ids' => [$green->id, $medium->id, $cotton->id]],
            ['sku' => 'PHOTO-GREEN-SILK', 'sale_price' => '25', 'attribute_value_ids' => [$green->id, $medium->id, $silk->id]],
        ]])->assertCreated()->json('data.id');
        $variant = ProductVariant::where('sku', 'PHOTO-GREEN')->firstOrFail()->id;
        $this->upload()->assertCreated();
        $this->postJson("/api/v1/business/products/{$this->product}/images", ['request_key' => (string) Str::uuid(), 'file' => UploadedFile::fake()->image('green.jpg', 80, 80), 'variant_id' => $variant])->assertCreated();
        $images = $this->gallery()['images'];
        $primary = collect($images)->firstWhere('is_primary', true);
        $specific = collect($images)->firstWhere('variant_id', $variant);
        $root = '/api/v1/business/stores/'.Store::firstOrFail()->id.'/warehouses/'.Warehouse::firstOrFail()->id.'/stock';
        $rows = collect($this->getJson($root)->assertOk()->json('data'))->keyBy('sku');
        $this->assertSame($primary['renditions']['thumb']['url'], $rows['PHOTO-BLACK']['image_url']);
        $this->assertSame($specific['renditions']['thumb']['url'], $rows['PHOTO-GREEN']['image_url']);
        $this->assertSame($specific['renditions']['thumb']['url'], $rows['PHOTO-GREEN-M']['image_url']);
        $this->assertSame($primary['renditions']['thumb']['url'], $rows['PHOTO-GREEN-SILK']['image_url']);
        $this->getJson($root.'?q=Verde')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($root.'?q=Color')->assertOk()->assertJsonCount(4, 'data');
        $salesRoot = '/api/v1/business/stores/'.Store::firstOrFail()->id;
        $catalog = collect($this->getJson($salesRoot.'/sales/catalog?warehouse_id='.Warehouse::firstOrFail()->id)->assertOk()->json('data'))->firstWhere('id', $this->product);
        $sales = collect($catalog['variants'])->keyBy('sku');
        $this->assertSame($specific['renditions']['card']['url'], $sales['PHOTO-GREEN-M']['image_url']);
        $this->assertSame($specific['renditions']['thumb']['url'], $sales['PHOTO-GREEN-M']['thumb_url']);
        $customer = Customer::factory()->create();
        $greenMedium = ProductVariant::where('sku', 'PHOTO-GREEN-M')->firstOrFail()->id;
        $this->postJson($salesRoot.'/inventory/movements', ['request_key' => (string) Str::uuid(), 'warehouse_id' => Warehouse::firstOrFail()->id, 'variant_id' => $greenMedium, 'operation' => 'receipt', 'quantity' => '2', 'version' => 0, 'note' => 'Entrada QA'])->assertCreated();
        $draft = ['warehouse_id' => Warehouse::firstOrFail()->id, 'customer_id' => $customer->id, 'lines' => [['variant_id' => $greenMedium, 'quantity' => '1']], 'discount' => '0', 'shipping' => '0'];
        $hash = $this->postJson($salesRoot.'/sales/quote', $draft)->assertOk()->json('data.quote_hash');
        $this->postJson($salesRoot.'/sales/orders', [...$draft, 'mode' => 'reservation', 'initial_payment' => '0', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(), 'quote_hash' => $hash])->assertCreated()->assertJsonPath('data.order.items.0.image_url_snapshot', $specific['renditions']['thumb']['url']);
        $link = $this->postJson($salesRoot.'/order-request-links', ['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'variant_ids' => [ProductVariant::where('sku', 'PHOTO-GREEN-M')->firstOrFail()->id], 'hours' => 24, 'shipping' => '0'])->assertCreated()->json('data.path');
        $this->getJson('/api/v1/order-requests/'.basename($link).'/catalog?group_products=1')->assertOk()->assertJsonPath('data.0.variants.0.image_url', $specific['renditions']['card']['url']);
    }

    public function test_same_request_is_idempotent_and_different_source_conflicts(): void
    {
        $key = (string) Str::uuid();
        $this->upload($key)->assertCreated();
        $bytes = StorageQuota::find(1)->bytes_ready;
        $this->upload($key)->assertOk()->assertJsonPath('data.replay', true);
        $this->upload($key, UploadedFile::fake()->image('other.png', 60, 60))->assertConflict();
        $this->assertDatabaseCount('product_images', 1);
        $this->assertSame($bytes, StorageQuota::find(1)->bytes_ready);
    }

    public function test_product_can_have_six_photos_when_quota_allows_it(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->upload()->assertCreated();
        }
        $this->assertDatabaseCount('product_images', 6);
    }

    public function test_five_photo_limit_and_quota_rejection_leave_no_partial_rows(): void
    {
        StorageQuota::whereKey(1)->update(['max_images_per_product' => 5]);
        for ($i = 0; $i < 5; $i++) {
            $this->upload()->assertCreated();
        }
        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('media_upload_sessions', 5);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
    }

    public function test_pending_uploads_count_toward_slots_and_quota(): void
    {
        StorageQuota::whereKey(1)->update(['max_images_per_product' => 5]);
        $service = app(MediaUploadService::class);
        $input = ['declared_size' => 1024, 'declared_mime' => 'image/png', 'source_sha256' => str_repeat('a', 64)];
        for ($i = 0; $i < 5; $i++) {
            $service->reserve($this->product, [...$input, 'request_key' => (string) Str::uuid()], $this->owner);
        }
        $this->upload()->assertUnprocessable();
        $this->assertSame(5120, StorageQuota::find(1)->bytes_pending);
        $this->assertSame(5, $this->gallery()['pending_uploads']);
    }

    public function test_invalid_formats_and_pixel_bomb_are_rejected_before_reserving(): void
    {
        foreach (['svg' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'heic' => 'not-a-photo', 'gif' => 'GIF89a'] as $ext => $bytes) {
            $this->upload(file: UploadedFile::fake()->createWithContent('bad.'.$ext, $bytes))->assertUnprocessable();
        }
        $png = UploadedFile::fake()->image('oversize.png', 1, 1)->getContent();
        $png = substr_replace($png, pack('NN', 5000, 5000), 16, 8);
        $this->upload(file: UploadedFile::fake()->createWithContent('bomb.png', $png))->assertUnprocessable();
        $this->assertDatabaseCount('media_upload_sessions', 0);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
    }

    public function test_small_transparent_png_is_not_upscaled(): void
    {
        $this->upload(file: UploadedFile::fake()->image('small.png', 40, 20))->assertCreated();
        foreach ($this->gallery()['images'][0]['renditions'] as $rendition) {
            $this->assertSame(40, $rendition['width']);
            $this->assertSame(20, $rendition['height']);
        }
    }

    public function test_provider_failure_is_compensated_then_same_request_can_retry(): void
    {
        $storage = \Mockery::mock(ObjectStorage::class)->makePartial();
        $writes = 0;
        $storage->shouldReceive('put')->twice()->andReturnUsing(function ($rendition, $path) use (&$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('provider unavailable');
            }
            (new ObjectStorage)->put($rendition, $path);
        });
        $this->app->instance(ObjectStorage::class, $storage);
        $key = (string) Str::uuid();
        $this->upload($key)->assertStatus(503);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
        $this->assertSame([], Storage::disk('media_catalog')->allFiles());
        $this->assertCount(0, $this->gallery()['images']);
        $this->getJson("/api/v1/business/products/{$this->product}/uploads/$key")->assertOk()->assertJsonPath('data.retryable', true);
        $this->app->instance(ObjectStorage::class, new ObjectStorage);
        $this->upload($key)->assertCreated();
        $this->assertSame(2, MediaUploadSession::first()->attempts);
    }

    public function test_unverified_cleanup_keeps_pending_bytes_until_recovery(): void
    {
        $storage = \Mockery::mock(ObjectStorage::class)->makePartial();
        $storage->shouldReceive('put')->andThrow(new \RuntimeException('upload failed'));
        $storage->shouldReceive('remove')->andThrow(new \RuntimeException('delete failed'));
        $this->app->instance(ObjectStorage::class, $storage);
        $key = (string) Str::uuid();
        $this->upload($key)->assertStatus(503);
        $this->assertGreaterThan(0, StorageQuota::find(1)->bytes_pending);
        $this->upload($key)->assertConflict();
        $this->app->instance(ObjectStorage::class, new ObjectStorage);
        app(MediaUploadService::class)->recoverExpired();
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
        $this->upload($key)->assertCreated();
    }

    public function test_gallery_primary_order_and_optimistic_conflict(): void
    {
        $this->upload()->assertCreated();
        $this->upload()->assertCreated();
        $g = $this->gallery();
        $items = array_reverse(array_map(fn ($image) => ['id' => $image['id'], 'variant_id' => null, 'alt_text' => 'Orden'], $g['images']));
        $data = ['version' => $g['version'], 'primary_id' => $items[0]['id'], 'images' => $items];
        $this->patchJson("/api/v1/business/products/{$this->product}/images", $data)->assertOk()->assertJsonPath('data.images.0.is_primary', true)->assertJsonPath('data.images.0.id', $items[0]['id']);
        $this->patchJson("/api/v1/business/products/{$this->product}/images", $data)->assertConflict();
        $data['version']++;
        $this->patchJson("/api/v1/business/products/{$this->product}/images", $data)->assertOk()->assertJsonPath('data.images.0.is_primary', true);
    }

    public function test_removal_retains_quota_then_due_purge_releases_it_only_once(): void
    {
        $this->upload()->assertCreated();
        $this->upload()->assertCreated();
        $g = $this->gallery();
        $removed = $g['images'][0];
        $this->deleteJson("/api/v1/business/products/{$this->product}/images/{$removed['id']}", ['version' => $g['version']])->assertOk()->assertJsonCount(1, 'data.images')->assertJsonPath('data.images.0.is_primary', true);
        $this->get($removed['renditions']['thumb']['url'])->assertNotFound();
        $quota = StorageQuota::find(1);
        $this->assertGreaterThan(0, $quota->bytes_retained);
        $this->assertSame(0, app(GalleryService::class)->purgeDue());
        $this->travel(25)->hours();
        $this->assertSame(1, app(GalleryService::class)->purgeDue());
        $this->assertSame(0, app(GalleryService::class)->purgeDue());
        $this->assertSame(0, StorageQuota::find(1)->bytes_retained);
        $this->assertDatabaseCount('product_images', 2);
    }

    public function test_seller_cannot_upload_and_cannot_inspect_another_users_upload(): void
    {
        $key = (string) Str::uuid();
        $this->upload($key)->assertCreated();
        $seller = User::factory()->create();
        $seller->roles()->attach(Role::where('code', 'seller')->value('id'));
        \Illuminate\Support\Facades\DB::table('store_user')->insert(['user_id' => $seller->id, 'store_id' => \Illuminate\Support\Facades\DB::table('stores')->orderBy('id')->value('id')]);
        Auth::forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($seller);
        $this->upload()->assertForbidden();
        $this->getJson("/api/v1/business/products/{$this->product}/uploads/$key")->assertForbidden();
        $this->getJson("/api/v1/business/products/{$this->product}/images")->assertOk()->assertJsonMissingPath('data.quota');
    }

    public function test_expired_session_can_be_recovered_without_releasing_an_active_os_lock(): void
    {
        $session = app(MediaUploadService::class)->reserve($this->product, ['request_key' => (string) Str::uuid(), 'declared_size' => 1024, 'declared_mime' => 'image/png', 'source_sha256' => str_repeat('a', 64)], $this->owner)['session'];
        $this->travel(20)->minutes();
        $path = 'locks/'.hash('sha256', $session->request_key).'.lock';
        Storage::disk('media_staging')->put($path, '');
        $handle = fopen(Storage::disk('media_staging')->path($path), 'c');
        flock($handle, LOCK_EX);
        try {
            app(MediaUploadService::class)->recoverExpired();
            $this->assertSame('issued', $session->fresh()->status);
            $this->assertSame(1024, StorageQuota::find(1)->bytes_pending);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        app(MediaUploadService::class)->recoverExpired();
        $this->assertSame('failed', $session->fresh()->status);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
    }

    public function test_quota_exhaustion_does_not_create_a_session(): void
    {
        StorageQuota::whereKey(1)->update(['max_bytes' => 10]);
        $this->upload()->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('media_upload_sessions', 0);
        $this->assertSame(0, StorageQuota::find(1)->bytes_pending);
    }

    public function test_exif_orientation_is_applied_and_not_copied_to_webp(): void
    {
        $jpeg = UploadedFile::fake()->image('oriented.jpg', 800, 600)->getContent();
        $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvVv', 0x0112, 3, 1, 6)."\0\0".pack('V', 0);
        $exif = "Exif\0\0".$tiff;
        $jpeg = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $this->upload(file: UploadedFile::fake()->createWithContent('orientation.jpg', $jpeg))->assertCreated();
        $image = $this->gallery()['images'][0];
        $this->assertSame(600, $image['renditions']['detail']['width']);
        $this->assertSame(800, $image['renditions']['detail']['height']);
        $asset = MediaAsset::with('renditions')->firstOrFail();
        foreach ($asset->renditions as $rendition) {
            $bytes = Storage::disk('media_catalog')->get($rendition->object_key);
            $this->assertStringNotContainsString('EXIF', $bytes);
        }
    }

    public function test_animated_png_is_rejected_before_decoding_or_reserving(): void
    {
        $png = UploadedFile::fake()->image('animated.png', 10, 10)->getContent();
        $chunk = 'acTL'.pack('NN', 2, 0);
        $png = substr($png, 0, 33).pack('N', 8).$chunk.pack('N', crc32($chunk)).substr($png, 33);
        $this->upload(file: UploadedFile::fake()->createWithContent('animation.png', $png))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('media_upload_sessions', 0);
    }
}
