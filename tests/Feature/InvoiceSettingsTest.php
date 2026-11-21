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
}
