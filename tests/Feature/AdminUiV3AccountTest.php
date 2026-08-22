<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminAccountProfileVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUiV3AccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function test_admin_can_only_update_self_service_profile_fields(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin();
        $profileVersion = AdminAccountProfileVersion::for($admin);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->put(route('admin.account.profile.update'), [
                'profile_version' => $profileVersion,
                'display_name' => 'Updated Admin',
                'email' => 'updated@example.com',
                'role' => 'super_admin',
                'status' => 'inactive',
                'username' => 'hijacked',
            ])
            ->assertRedirect();

        $admin->refresh();
        $this->assertSame('Updated Admin', $admin->display_name);
        $this->assertSame('updated@example.com', $admin->email);
        $this->assertSame('account_owner', $admin->username);
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
    }

    public function test_stale_profile_update_is_rejected(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin();
        $staleVersion = AdminAccountProfileVersion::for($admin);

        Admin::query()->whereKey($admin->id)->update([
            'display_name' => 'Updated elsewhere',
            'updated_at' => now()->addSecond(),
        ]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->put(route('admin.account.profile.update'), [
                'profile_version' => $staleVersion,
                'display_name' => 'Stale tab value',
                'email' => 'account@example.com',
            ])
            ->assertSessionHasErrors('profile_version');

        $this->assertSame('Updated elsewhere', $admin->fresh()->display_name);
    }

    public function test_password_update_requires_current_password_and_revokes_credentials(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin();
        $tokenId = $admin->createToken('account-token', ['catalog:read'])->accessToken->id;

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->put(route('admin.account.password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-secret-456',
                'password_confirmation' => 'new-secret-456',
            ])
            ->assertSessionHasErrors('current_password');

        $this->put(route('admin.account.password.update'), [
            'current_password' => 'secret-123',
            'password' => 'new-secret-456',
            'password_confirmation' => 'new-secret-456',
            'action' => str_repeat('x', 1000),
        ])->assertRedirect(route('admin.login'));

        $admin->refresh();
        $this->assertTrue(Hash::check('new-secret-456', $admin->password));
        $this->assertSame(2, $admin->auth_version);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $activity = $admin->activityLogs()->latest('id')->firstOrFail();
        $this->assertSame('admin.account.password.update:submit', $activity->action);
        $this->assertStringNotContainsString('secret-123', (string) $activity->details);
        $this->assertStringNotContainsString('new-secret-456', (string) $activity->details);
        $this->assertGuest('admin');
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'account_owner',
            'password' => 'secret-123',
            'email' => 'account@example.com',
            'display_name' => 'Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
