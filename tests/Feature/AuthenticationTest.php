<?php

namespace Tests\Feature;

use App\Domain\Business\InstallBusiness;
use App\Models\BusinessProfile;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_session_or_workspace(): void
    {
        $this->getJson('/api/v1/session')->assertUnauthorized();
        $this->getJson('/api/v1/business/workspace')->assertUnauthorized();
    }

    public function test_session_login_logout_and_invalid_password(): void
    {
        BusinessProfile::create(['name' => 'Negocio QA', 'default_currency' => 'DOP', 'timezone' => 'UTC']);
        $user = User::factory()->create(['email' => 'owner@example.test', 'password' => 'CorrectPassword123']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/v1/login', ['email' => 'OWNER@example.test', 'password' => 'CorrectPassword123'])->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.password');
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/v1/session')->assertOk();
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->assertGuest('web');
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/session')->assertUnauthorized();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create(['is_active' => false, 'password' => 'CorrectPassword123']);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'CorrectPassword123'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_weekly_accounts_uses_the_logged_in_session_and_rejects_logged_out_requests(): void
    {
        $user = User::factory()->create(['password' => 'CorrectPassword123']);
        app(InstallBusiness::class)->create($user, ['name' => 'Weekly session QA', 'default_currency' => 'USD', 'timezone' => 'UTC', 'store_name' => 'Principal']);
        $url = '/api/v1/business/stores/'.Store::firstOrFail()->id.'/weekly-accounts';

        $this->getJson($url)->assertUnauthorized();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'CorrectPassword123'])->assertOk();
        $this->getJson('/api/v1/session')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'none@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/login', ['email' => 'none@example.test', 'password' => 'wrong'])->assertTooManyRequests();
    }

    public function test_password_recovery_is_generic_and_reset_token_is_single_use(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $message = $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $this->postJson('/api/v1/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertJsonPath('message', $message);
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'];
        $this->postJson('/api/v1/reset-password', $data)->assertOk();
        $this->assertTrue(Hash::check('NewPassword12345', $user->refresh()->password));
        $this->postJson('/api/v1/reset-password', $data)->assertUnprocessable();
    }

    public function test_csrf_cookie_is_issued_and_mutation_without_token_is_rejected(): void
    {
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrf::class);
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->postJson('/api/v1/login', ['email' => 'none@example.test', 'password' => 'wrong'])->assertStatus(419);
    }
}

class EnforcedCsrf extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
