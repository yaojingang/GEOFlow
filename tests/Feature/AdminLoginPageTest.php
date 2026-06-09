<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_initial_admin_hint_when_default_credentials_are_still_valid(): void
    {
        config([
            'geoflow.initial_admin_hint_enabled' => true,
            'geoflow.initial_admin_username' => 'admin',
            'geoflow.initial_admin_password' => 'password',
        ]);

        Admin::query()->create([
            'username' => 'admin',
            'password' => 'password',
            'email' => 'admin@example.com',
            'display_name' => 'Administrator',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee(__('admin.login.first_login_hint_title'))
            ->assertSee(__('admin.login.first_login_security'))
            ->assertSee('admin')
            ->assertSee('password')
            ->assertSee('id="initial-admin-hint"', false)
            ->assertSee('localStorage.setItem', false);
    }

    public function test_login_page_hides_initial_admin_hint_after_first_successful_login(): void
    {
        config([
            'geoflow.initial_admin_hint_enabled' => true,
            'geoflow.initial_admin_username' => 'admin',
            'geoflow.initial_admin_password' => 'password',
        ]);

        Admin::query()->create([
            'username' => 'admin',
            'password' => 'password',
            'email' => 'admin@example.com',
            'display_name' => 'Administrator',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => now(),
        ]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee(__('admin.login.first_login_hint_title'))
            ->assertDontSee('id="initial-admin-hint"', false);
    }

    public function test_login_page_hides_initial_admin_hint_when_configured_password_no_longer_matches(): void
    {
        config([
            'geoflow.initial_admin_hint_enabled' => true,
            'geoflow.initial_admin_username' => 'admin',
            'geoflow.initial_admin_password' => 'password',
        ]);

        Admin::query()->create([
            'username' => 'admin',
            'password' => 'changed-secret-123',
            'email' => 'admin@example.com',
            'display_name' => 'Administrator',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertDontSee(__('admin.login.first_login_hint_title'))
            ->assertDontSee('id="initial-admin-hint"', false);
    }

    public function test_login_page_points_to_init_log_when_production_password_was_generated(): void
    {
        config([
            'app.env' => 'production',
            'geoflow.initial_admin_hint_enabled' => true,
            'geoflow.initial_admin_username' => 'admin',
            'geoflow.initial_admin_password' => '',
        ]);

        Admin::query()->create([
            'username' => 'admin',
            'password' => 'random-generated-secret',
            'email' => 'admin@example.com',
            'display_name' => 'Administrator',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee(__('admin.login.first_login_hint_title'))
            ->assertSee(__('admin.login.first_login_password_from_log'))
            ->assertSee('geoflow-init')
            ->assertDontSee('random-generated-secret');
    }
}
