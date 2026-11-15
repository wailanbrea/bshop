<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Domain\Business\StoreContext;
use App\Jobs\Middleware\WithStoreContext;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SingleBusinessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $store;

    public function actingAs($user, $guard = null)
    {
        Auth::forgetGuards();
        $this->app['session']->flush();

        return parent::actingAs($user, $guard);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        app(InstallBusiness::class)->create($this->owner, ['name' => 'KeylaShop', 'default_currency' => 'DOP', 'timezone' => 'America/Santo_Domingo', 'store_name' => 'Principal']);
        $this->store = Store::firstOrFail()->id;
    }

    public function test_installation_has_no_company_scopes_memberships_or_saas_tables(): void
    {
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $table) {
            $this->assertNotContains('tenant_id', Schema::getColumnListing($table->name));
        }
        foreach (['tenants', 'tenant_memberships', 'saas_plans', 'tenant_subscriptions'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertDatabaseCount('business_profile', 1);
        $this->actingAs($this->owner)->getJson('/api/v1/session')->assertOk()->assertJsonPath('data.business.name', 'KeylaShop')->assertJsonMissingPath('data.memberships');
        $this->postJson('/api/v1/tenants', [])->assertNotFound();
        $this->postJson('/api/v1/business', ['name' => 'Second'])->assertStatus(405);
    }

    public function test_database_rejects_a_second_business_profile(): void
    {
        $this->expectException(QueryException::class);
        DB::table('business_profile')->insert(['id' => 2, 'name' => 'Second', 'default_currency' => 'USD', 'timezone' => 'UTC']);
    }

    public function test_owner_cannot_access_an_unassigned_store(): void
    {
        $other = Store::create(['name' => 'Restringida', 'code' => 'PRIVATE']);
        $this->actingAs($this->owner)->getJson('/api/v1/business/workspace')->assertOk()->assertJsonCount(1, 'data.stores');
        $this->getJson("/api/v1/business/stores/$other->id/warehouses")->assertNotFound();
        $this->patchJson("/api/v1/business/stores/$other->id", ['name' => 'Attack', 'code' => 'PRIVATE'])->assertNotFound();
    }

    public function test_owner_creates_store_and_warehouse_with_audit(): void
    {
        $id = $this->actingAs($this->owner)->postJson('/api/v1/business/stores', ['name' => 'Norte', 'code' => 'NORTE'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('store_user', ['user_id' => $this->owner->id, 'store_id' => $id]);
        $this->postJson("/api/v1/business/stores/$id/warehouses", ['name' => 'Principal', 'is_default' => true])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['event' => 'warehouse.created', 'user_id' => $this->owner->id]);
        $this->getJson("/api/v1/business/stores/$id/warehouses")->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/business/stores', ['name' => 'Otro', 'code' => 'NORTE'])->assertUnprocessable();
    }

    private function seller(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('code', 'seller')->value('id'));
        DB::table('store_user')->insert(['user_id' => $user->id, 'store_id' => $this->store]);

        return $user;
    }

    public function test_seller_reads_only_assigned_stores_and_cannot_manage_business_or_team(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)->getJson('/api/v1/business/workspace')->assertOk()->assertJsonCount(1, 'data.stores');
        $this->getJson('/api/v1/business/team')->assertForbidden();
        $this->postJson('/api/v1/business/stores', ['name' => 'Attack', 'code' => 'ATTACK'])->assertForbidden();
        $this->patchJson('/api/v1/business', ['name' => 'Attack', 'timezone' => 'UTC'])->assertForbidden();
    }

    public function test_last_owner_cannot_be_suspended_or_demoted(): void
    {
        $this->actingAs($this->owner)->patchJson("/api/v1/business/users/{$this->owner->id}", ['role_id' => Role::where('code', 'seller')->value('id'), 'store_ids' => [$this->store], 'status' => 'active'])->assertUnprocessable();
        $this->assertTrue($this->owner->fresh()->permits('members.manage'));
    }

    public function test_suspending_a_user_revokes_access_but_allows_logout(): void
    {
        $seller = $this->seller();
        $this->actingAs($this->owner)->patchJson("/api/v1/business/users/$seller->id", ['role_id' => Role::where('code', 'seller')->value('id'), 'store_ids' => [$this->store], 'status' => 'suspended'])->assertOk();
        $this->actingAs($seller->fresh())->getJson('/api/v1/business/workspace')->assertForbidden();
        $this->postJson('/api/v1/logout')->assertNoContent();
    }

    public function test_invitation_creates_one_local_user_and_get_does_not_consume(): void
    {
        $created = $this->actingAs($this->owner)->postJson('/api/v1/business/invitations', ['email' => 'invited@business.test', 'role_id' => Role::where('code', 'seller')->value('id'), 'store_ids' => [$this->store]])->assertCreated()->json('data');
        $token = basename($created['invitation_url']);
        $this->assertDatabaseHas('team_invitations', ['token_hash' => hash('sha256', $token), 'status' => 'pending']);
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $this->getJson("/api/v1/invitations/$token")->assertOk();
        $this->getJson("/api/v1/invitations/$token")->assertOk();
        $this->postJson("/api/v1/invitations/$token/accept", ['name' => 'Invitado', 'email' => 'invited@business.test', 'password' => 'StrongPassword123', 'password_confirmation' => 'StrongPassword123'])->assertOk()->assertJsonPath('data.business.name', 'KeylaShop');
        $this->postJson("/api/v1/invitations/$token/accept", ['email' => 'invited@business.test'])->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('business_profile', 1);
    }

    public function test_jobs_require_current_role_and_store_access_and_clear_context(): void
    {
        $job = new WithStoreContext($this->owner->id, $this->store, 'stores.view');
        $job->handle(new \stdClass, fn () => $this->assertSame($this->store, app(StoreContext::class)->store()->id));
        $this->expectException(\LogicException::class);
        app(StoreContext::class)->store();
    }
}
