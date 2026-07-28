<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminLoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifth_failed_login_temporarily_blocks_only_the_username_and_ip_pair(): void
    {
        config([
            'geoflow.max_login_attempts' => 5,
            'geoflow.login_lockout_seconds' => 900,
        ]);

        $admin = Admin::query()->create([
            'username' => 'timed-admin',
            'password' => 'correct-password',
            'email' => 'timed-admin@example.com',
            'display_name' => 'Timed Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->from(route('admin.login'))
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post(route('admin.login.attempt'), [
                    'username' => 'timed-admin',
                    'password' => 'wrong-password',
                ])
                ->assertRedirect(route('admin.login'))
                ->assertSessionHasErrors('username');
        }

        $this->from(route('admin.login'))
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('admin.login.attempt'), [
                'username' => 'timed-admin',
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('username', __('admin.login.error.too_many_attempts', ['seconds' => 900]));

        $this->assertSame('active', $admin->fresh()?->status);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->post(route('admin.login.attempt'), [
                'username' => 'timed-admin',
                'password' => 'correct-password',
            ])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_login_route_limits_total_requests_from_one_ip(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->post(route('admin.login.attempt'), [
                    'username' => 'unknown-'.$attempt,
                    'password' => 'wrong-password',
                ])
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('admin.login.attempt'), [
                'username' => 'another-unknown',
                'password' => 'wrong-password',
            ])
            ->assertTooManyRequests();
    }

    public function test_remember_cookie_expires_within_thirty_days(): void
    {
        Admin::query()->create([
            'username' => 'remember-admin',
            'password' => 'correct-password',
            'email' => 'remember-admin@example.com',
            'display_name' => 'Remember Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $response = $this->post(route('admin.login.attempt'), [
            'username' => 'remember-admin',
            'password' => 'correct-password',
            'remember' => '1',
        ])->assertRedirect(route('admin.dashboard'));

        $rememberCookie = collect($response->headers->getCookies())
            ->first(static fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_admin_'));

        $this->assertNotNull($rememberCookie);
        $remainingSeconds = $rememberCookie->getExpiresTime() - now()->timestamp;
        $this->assertGreaterThan(29 * 24 * 60 * 60, $remainingSeconds);
        $this->assertLessThanOrEqual(30 * 24 * 60 * 60, $remainingSeconds);
    }

    public function test_remember_cookie_can_restore_a_new_versioned_session(): void
    {
        $admin = Admin::query()->create([
            'username' => 'remember-restore-admin',
            'password' => 'correct-password',
            'email' => 'remember-restore-admin@example.com',
            'display_name' => 'Remember Restore Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $loginResponse = $this->post(route('admin.login.attempt'), [
            'username' => 'remember-restore-admin',
            'password' => 'correct-password',
            'remember' => '1',
        ])->assertRedirect(route('admin.dashboard'));
        $rememberCookie = collect($loginResponse->headers->getCookies())
            ->first(static fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_admin_'));
        $this->assertNotNull($rememberCookie);

        $this->flushSession();
        Auth::guard('admin')->forgetUser();

        $this->withUnencryptedCookie($rememberCookie->getName(), $rememberCookie->getValue())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSessionHas(Admin::AUTH_VERSION_SESSION_KEY, (int) $admin->auth_version);
    }

    public function test_expired_database_limiter_rows_are_pruned_without_deleting_active_rows(): void
    {
        config(['cache.limiter' => 'database']);

        DB::table('cache')->insert([
            [
                'key' => 'expired-login-limit',
                'value' => 'i:1;',
                'expiration' => now()->subMinute()->getTimestamp(),
            ],
            [
                'key' => 'active-login-limit',
                'value' => 'i:1;',
                'expiration' => now()->addMinute()->getTimestamp(),
            ],
        ]);

        $this->artisan('geoflow:prune-expired-cache')
            ->expectsOutputToContain('Pruned expired cache rows: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('cache', ['key' => 'expired-login-limit']);
        $this->assertDatabaseHas('cache', ['key' => 'active-login-limit']);
    }
}
