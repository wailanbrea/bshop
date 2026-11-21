<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Payments\PaymentMethodCatalog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentMethodSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, [
            'name' => 'Test Shop',
            'default_currency' => 'USD',
            'timezone' => 'America/Santo_Domingo',
            'store_name' => 'Principal',
        ]);
        $this->store = Store::firstOrFail();
    }

    public function test_store_returns_full_catalog_with_11_default_enabled_methods(): void
    {
        $res = $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$this->store->id}/payment-methods");

        $res->assertOk()
            ->assertJsonPath('store_id', $this->store->id);

        $methods = $res->json('methods');
        $this->assertGreaterThanOrEqual(40, count($methods));

        $enabled = array_values(array_filter($methods, fn ($m) => $m['is_enabled']));
        $this->assertCount(11, $enabled);
        $codes = array_column($enabled, 'code');
        $this->assertContains('cash', $codes);
        $this->assertContains('zelle', $codes);
        $this->assertContains('apple_pay', $codes);
    }

    public function test_store_can_sync_enabled_methods_and_remains_isolated(): void
    {
        $otherStore = Store::create([
            'name' => 'Segunda Sucursal',
            'code' => 'SUC2',
            'is_active' => true,
        ]);
        \Illuminate\Support\Facades\DB::table('store_user')->insert([
            'store_id' => $otherStore->id,
            'user_id' => $this->owner->id,
        ]);

        $sync = $this->actingAs($this->owner)
            ->putJson("/api/v1/business/stores/{$this->store->id}/payment-methods", [
                'enabled_codes' => ['zelle', 'google_pay', 'bitcoin'],
            ]);

        $sync->assertOk();
        $enabled = array_values(array_filter($sync->json('methods'), fn ($m) => $m['is_enabled']));
        $this->assertSame(['zelle', 'google_pay', 'bitcoin'], array_column($enabled, 'code'));

        // Other store still keeps its default 11 methods
        $otherRes = $this->actingAs($this->owner)
            ->getJson("/api/v1/business/stores/{$otherStore->id}/payment-methods");
        $otherEnabled = array_values(array_filter($otherRes->json('methods'), fn ($m) => $m['is_enabled']));
        $this->assertCount(11, $otherEnabled);
    }

    public function test_store_can_create_update_and_delete_custom_payment_method_with_svg(): void
    {
        $svg = '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" fill="currentColor"/></svg>';

        $create = $this->actingAs($this->owner)
            ->postJson("/api/v1/business/stores/{$this->store->id}/payment-methods/custom", [
                'label' => 'Banreservas',
                'bg_color' => '#0056a3',
                'fg_color' => '#ffffff',
                'icon_svg' => $svg,
            ]);

        $create->assertCreated();
        $createdCode = $create->json('created_code');
        $this->assertStringStartsWith('c_banreservas', $createdCode);

        $custom = collect($create->json('methods'))->firstWhere('code', $createdCode);
        $this->assertNotNull($custom);
        $this->assertTrue($custom['is_custom']);
        $this->assertTrue($custom['is_enabled']);
        $this->assertStringContainsString('<svg', $custom['icon_svg']);

        // Allowed in payment validation and invoice labels
        $this->assertContains($createdCode, PaymentMethodCatalog::allowedCodes($this->store->id));
        $this->assertSame('Banreservas', PaymentMethodCatalog::labelsForStore($this->store->id)[$createdCode]);

        // Update custom method
        $update = $this->actingAs($this->owner)
            ->patchJson("/api/v1/business/stores/{$this->store->id}/payment-methods/custom/{$custom['id']}", [
                'label' => 'Banco de Reservas',
                'bg_color' => '#003d7a',
            ]);
        $update->assertOk();
        $updated = collect($update->json('methods'))->firstWhere('code', $createdCode);
        $this->assertSame('Banco de Reservas', $updated['label']);

        // Delete custom method
        $delete = $this->actingAs($this->owner)
            ->deleteJson("/api/v1/business/stores/{$this->store->id}/payment-methods/custom/{$custom['id']}");
        $delete->assertOk();
        $this->assertNull(collect($delete->json('methods'))->firstWhere('code', $createdCode));
    }

    public function test_malicious_svg_is_rejected(): void
    {
        $xssSvg = '<svg viewBox="0 0 24 24" onload="alert(1)"><script>alert(1)</script><circle cx="12" cy="12" r="8"/></svg>';

        $res = $this->actingAs($this->owner)
            ->postJson("/api/v1/business/stores/{$this->store->id}/payment-methods/custom", [
                'label' => 'Malicioso',
                'icon_svg' => $xssSvg,
            ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['icon_svg']);
    }
}
