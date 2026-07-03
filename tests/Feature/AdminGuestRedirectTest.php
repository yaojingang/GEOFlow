<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_is_redirected_from_login_to_dashboard(): void
    {
        $admin = Admin::query()->create([
            'username' => 'redirect_admin',
            'password' => 'password',
            'email' => 'redirect_admin@example.com',
            'display_name' => 'Redirect Admin',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_authenticated_admin_entry_redirects_to_dashboard(): void
    {
        $admin = Admin::query()->create([
            'username' => 'entry_redirect_admin',
            'password' => 'password',
            'email' => 'entry_redirect_admin@example.com',
            'display_name' => 'Entry Redirect Admin',
            'role' => 'super_admin',
            'status' => 'active',
            'last_login' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.entry'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_guest_admin_login_flow_is_unchanged(): void
    {
        $this->get(route('admin.login'))
            ->assertOk();

        $this->get(route('admin.entry'))
            ->assertRedirect(route('admin.login'));
    }
}
