<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_session_is_revoked_when_credential_version_changes(): void
    {
        $admin = Admin::query()->create([
            'username' => 'session_owner',
            'password' => 'secret-123',
            'email' => 'session-owner@example.com',
            'display_name' => 'Session Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk();

        $this->assertSame(1, session('admin_auth_version'));

        $admin->increment('auth_version');

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_admin_session_without_a_credential_version_is_revoked(): void
    {
        $admin = Admin::query()->create([
            'username' => 'legacy_session_owner',
            'password' => 'secret-123',
            'email' => 'legacy-session@example.com',
            'display_name' => 'Legacy Session Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession([Admin::AUTH_VERSION_SESSION_KEY => null])
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_admin_session_is_revoked_when_the_account_is_inactive(): void
    {
        $admin = Admin::query()->create([
            'username' => 'inactive_session_owner',
            'password' => 'secret-123',
            'email' => 'inactive-session@example.com',
            'display_name' => 'Inactive Session Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin');
        $admin->forceFill(['status' => 'inactive'])->save();

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }

    public function test_updating_own_password_revokes_all_existing_credentials(): void
    {
        $admin = Admin::query()->create([
            'username' => 'password_owner',
            'password' => 'old-secret-123',
            'email' => 'password-owner@example.com',
            'display_name' => 'Password Owner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill(['remember_token' => 'old-remember-token'])->save();
        $tokenId = $admin->createToken('existing-token', ['catalog:read'])->accessToken->id;

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security-settings.password.update'), [
                'current_password' => 'old-secret-123',
                'new_password' => 'new-secret-123',
                'confirm_password' => 'new-secret-123',
            ])
            ->assertRedirect(route('admin.login'));

        $admin->refresh();

        $this->assertGuest('admin');
        $this->assertSame(2, $admin->auth_version);
        $this->assertNotSame('old-remember-token', $admin->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_unlocking_an_admin_revokes_all_existing_credentials(): void
    {
        $admin = Admin::query()->create([
            'username' => 'locked_owner',
            'password' => 'secret-123',
            'email' => 'locked-owner@example.com',
            'display_name' => 'Locked Owner',
            'role' => 'admin',
            'status' => 'locked',
        ]);
        $admin->forceFill(['remember_token' => 'old-remember-token'])->save();
        $tokenId = $admin->createToken('existing-token', ['catalog:read'])->accessToken->id;

        $this->artisan('geoflow:admin-unlock', ['username' => 'locked_owner'])
            ->assertSuccessful();

        $admin->refresh();

        $this->assertSame('active', $admin->status);
        $this->assertSame(2, $admin->auth_version);
        $this->assertNotSame('old-remember-token', $admin->remember_token);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
    }

    public function test_stale_admin_instances_cannot_overwrite_a_newer_credential_version(): void
    {
        $admin = Admin::query()->create([
            'username' => 'concurrent_credential_owner',
            'password' => 'secret-123',
            'email' => 'concurrent-credential-owner@example.com',
            'display_name' => 'Concurrent Credential Owner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $firstRequestView = Admin::query()->findOrFail($admin->id);
        $secondRequestView = Admin::query()->findOrFail($admin->id);

        $firstRequestView->revokeAuthenticationCredentials();
        $secondRequestView->revokeAuthenticationCredentials();

        $this->assertSame(3, (int) $admin->fresh()->auth_version);
    }
}
