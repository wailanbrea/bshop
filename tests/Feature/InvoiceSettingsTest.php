<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, [
            'name' => 'Test Shop',
            'default_currency' => 'USD',
            'timezone' => 'America/Santo_Domingo',
            'store_name' => 'Principal',
        ]);
        $this->store = Store::firstOrFail();
    }

    public function test_returns_default_invoice_settings_with_product_images_enabled(): void
    {
        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$this->store->id}/invoice-settings");

        $res->assertOk()
            ->assertJsonPath('data.store_id', $this->store->id)
            ->assertJsonPath('data.show_product_images', true)
            ->assertJsonPath('data.show_logo', true)
            ->assertJsonPath('data.has_logo', false);
    }

    public function test_can_update_invoice_settings_and_isolated_per_store(): void
    {
        $otherStore = Store::create([
            'name' => 'Sucursal 2',
            'code' => 'SUC2',
            'is_active' => true,
        ]);

        $patch = $this->actingAs($this->owner)
            ->patchJson("/api/v1/business/stores/{$this->store->id}/invoice-settings", [
                'accent_color' => '#be185d',
                'document_title' => 'RECIBO OFICIAL',
                'brand_subtitle' => 'RNC 131-99999-1',
                'show_product_images' => false,
                'show_sku' => false,
                'terms_text' => 'Cambios válidos por 7 días.',
            ]);

        $patch->assertOk()
            ->assertJsonPath('data.accent_color', '#be185d')
            ->assertJsonPath('data.document_title', 'RECIBO OFICIAL')
            ->assertJsonPath('data.show_product_images', false)
            ->assertJsonPath('data.show_sku', false)
            ->assertJsonPath('data.terms_text', 'Cambios válidos por 7 días.');

        // Store 2 keeps defaults (isolated)
        \Illuminate\Support\Facades\DB::table('store_user')->insert([
            'store_id' => $otherStore->id,
            'user_id' => $this->owner->id,
        ]);
        $other = $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$otherStore->id}/invoice-settings");
        $other->assertOk()
            ->assertJsonPath('data.show_product_images', true)
            ->assertJsonPath('data.document_title', 'FACTURA');
    }

    public function test_can_upload_remove_logo_and_render_pdf_preview(): void
    {
        $img = imagecreatetruecolor(40, 40);
        $bg = imagecolorallocate($img, 100, 53, 237);
        imagefilledrectangle($img, 0, 0, 40, 40, $bg);
        ob_start();
        imagepng($img);
        $pngBytes = ob_get_clean();
        imagedestroy($img);

        $file = UploadedFile::fake()->createWithContent('logo.png', $pngBytes);

        $upload = $this->actingAs($this->owner)
            ->postJson("/api/v1/business/stores/{$this->store->id}/invoice-settings/logo", [
                'logo' => $file,
            ]);

        $upload->assertOk()
            ->assertJsonPath('data.has_logo', true)
            ->assertJsonPath('data.show_logo', true);

        $this->assertStringStartsWith('data:image/png;base64,', $upload->json('data.logo_data_uri'));

        $preview = $this->actingAs($this->owner)
            ->get("/api/v1/business/stores/{$this->store->id}/invoice-settings/preview");
        $preview->assertOk();
        $this->assertStringStartsWith('%PDF-', $preview->getContent());

        $del = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/business/stores/{$this->store->id}/invoice-settings/logo");
        $del->assertOk()
            ->assertJsonPath('data.has_logo', false);
    }

    public function test_invoice_images_prioritize_order_item_snapshot_over_product_primary_photo(): void
    {
        Storage::fake('media_catalog');

        // Create two distinct solid-color test images (1x1 red vs 1x1 blue)
        $makePng = function (int $r, int $g, int $b): string {
            $im = imagecreatetruecolor(8, 8);
            $col = imagecolorallocate($im, $r, $g, $b);
            imagefilledrectangle($im, 0, 0, 7, 7, $col);
            ob_start();
            imagepng($im);
            $bytes = (string) ob_get_clean();
            imagedestroy($im);

            return $bytes;
        };

        $pinkBytes = $makePng(240, 80, 160);
        $blackBytes = $makePng(20, 20, 20);

        $productId = \Illuminate\Support\Facades\DB::table('products')->insertGetId([
            'store_id' => $this->store->id,
            'name' => 'MJ tote pequeña',
            'product_type' => 'physical',
            'unit_code' => 'unit',
            'track_stock' => true,
            'status' => 'active',
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $variantNegraId = \Illuminate\Support\Facades\DB::table('product_variants')->insertGetId([
            'product_id' => $productId,
            'sku' => 'NEGRA-MJ-TOTE',
            'sale_price' => '55.0000',
            'cost_price' => '25.0000',
            'is_active' => true,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pinkKey = "products/{$productId}/11111111-1111-1111-1111-111111111111/v1/thumb.webp";
        $blackKey = "products/{$productId}/22222222-2222-2222-2222-222222222222/v1/thumb.webp";
        Storage::disk('media_catalog')->put($pinkKey, $pinkBytes);
        Storage::disk('media_catalog')->put($blackKey, $blackBytes);

        $pinkHash = hash('sha256', $pinkBytes);
        $blackHash = hash('sha256', $blackBytes);

        $pinkAssetId = \Illuminate\Support\Facades\DB::table('media_assets')->insertGetId([
            'provider' => 'local',
            'bucket_name' => 'media_catalog',
            'staging_provider' => 'local',
            'staging_bucket_name' => 'local',
            'staging_key' => 'staging/pink.png',
            'staging_key_hash' => hash('sha256', 'staging/pink.png'),
            'object_key' => $pinkKey,
            'object_key_hash' => hash('sha256', $pinkKey),
            'visibility' => 'public',
            'status' => 'ready',
            'mime_type' => 'image/webp',
            'byte_size' => strlen($pinkBytes),
            'width_px' => 8,
            'height_px' => 8,
            'sha256' => $pinkHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $blackAssetId = \Illuminate\Support\Facades\DB::table('media_assets')->insertGetId([
            'provider' => 'local',
            'bucket_name' => 'media_catalog',
            'staging_provider' => 'local',
            'staging_bucket_name' => 'local',
            'staging_key' => 'staging/black.png',
            'staging_key_hash' => hash('sha256', 'staging/black.png'),
            'object_key' => $blackKey,
            'object_key_hash' => hash('sha256', $blackKey),
            'visibility' => 'public',
            'status' => 'ready',
            'mime_type' => 'image/webp',
            'byte_size' => strlen($blackBytes),
            'width_px' => 8,
            'height_px' => 8,
            'sha256' => $blackHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('media_renditions')->insert([
            [
                'media_asset_id' => $pinkAssetId,
                'rendition' => 'thumb',
                'provider' => 'local',
                'namespace_name' => 'media_catalog',
                'object_key' => $pinkKey,
                'object_key_hash' => hash('sha256', $pinkKey),
                'mime_type' => 'image/webp',
                'width_px' => 8,
                'height_px' => 8,
                'byte_size' => strlen($pinkBytes),
                'sha256' => $pinkHash,
                'created_at' => now(),
            ],
            [
                'media_asset_id' => $blackAssetId,
                'rendition' => 'thumb',
                'provider' => 'local',
                'namespace_name' => 'media_catalog',
                'object_key' => $blackKey,
                'object_key_hash' => hash('sha256', $blackKey),
                'mime_type' => 'image/webp',
                'width_px' => 8,
                'height_px' => 8,
                'byte_size' => strlen($blackBytes),
                'sha256' => $blackHash,
                'created_at' => now(),
            ],
        ]);

        // Product primary photo is PINK (simulating the bug where primary photo overrode the black bag snapshot)
        \Illuminate\Support\Facades\DB::table('product_images')->insert([
            'product_id' => $productId,
            'variant_id' => null,
            'media_asset_id' => $pinkAssetId,
            'is_primary' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = \Illuminate\Support\Facades\DB::table('customers')->insertGetId([
            'store_id' => $this->store->id,
            'full_name' => 'Ingrid Test',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = \Illuminate\Support\Facades\DB::table('orders')->insertGetId([
            'store_id' => $this->store->id,
            'customer_id' => $customerId,
            'customer_snapshot' => json_encode(['full_name' => 'Ingrid Test']),
            'status' => 'reserved',
            'order_kind' => 'reservation',
            'channel' => 'manual',
            'currency' => 'USD',
            'subtotal' => '55.0000',
            'discount_total' => '0.0000',
            'tax_total' => '0.0000',
            'shipping_total' => '0.0000',
            'grand_total' => '55.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderItemId = \Illuminate\Support\Facades\DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'store_id' => $this->store->id,
            'variant_id' => $variantNegraId,
            'quantity' => '1.0000',
            'unit_price' => '55.0000',
            'line_total' => '55.0000',
            'product_name_snapshot' => 'MJ tote pequeña',
            'sku_snapshot' => 'NEGRA-MJ-TOTE',
            'image_url_snapshot' => "/media/catalog/{$blackAssetId}/thumb/{$blackHash}.webp",
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(\App\Domain\Sales\InvoiceSettingsService::class);
        $resolved = $service->productImagesForInvoice([
            (object) [
                'id' => 777,
                'order_item_id' => $orderItemId,
                'variant_id' => $variantNegraId,
            ],
        ], $this->store->id);

        $this->assertArrayHasKey(777, $resolved);
        $rawDecoded = base64_decode(substr($resolved[777], strlen('data:image/png;base64,')), true);
        $im = imagecreatefromstring((string) $rawDecoded);
        $rgb = imagecolorat($im, 2, 2);
        imagedestroy($im);
        $r = ($rgb >> 16) & 0xFF;

        // Must be the BLACK snapshot (r ~ 20), NOT the PINK primary photo (r ~ 240)
        $this->assertLessThan(50, $r, 'Invoice item image must match order_items.image_url_snapshot (black bag), not the pink primary product image.');
    }
}
