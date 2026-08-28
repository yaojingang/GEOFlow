<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSidebarResizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_admin_sidebar_exposes_a_bounded_resize_handle(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = Admin::query()->create([
            'username' => 'sidebar_resize_owner',
            'password' => 'secret-123',
            'email' => 'sidebar-resize@example.com',
            'display_name' => 'Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-sidebar-resize', false)
            ->assertSee('role="separator"', false)
            ->assertSee('aria-valuemin="224"', false)
            ->assertSee('aria-valuemax="384"', false)
            ->assertSee('geoflow.admin.ui-v3.sidebar-width', false)
            ->assertSee('--gf-sidebar-width-value', false);
    }
}
