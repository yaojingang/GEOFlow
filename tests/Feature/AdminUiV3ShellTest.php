<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiV3ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_flag_switches_shared_admin_shell(): void
    {
        $admin = $this->admin('shell_owner', 'super_admin');

        config()->set('geoflow.admin_ui_v3_enabled', true);
        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('class="gf-admin-v3', false)
            ->assertSee('data-gf-shell', false)
            ->assertSee(AdminWeb::routePath('admin.ai-workspace'), false)
            ->assertSee(AdminWeb::routePath('admin.distribution.index'), false)
            ->assertDontSee('tailwindcss.play-cdn.js', false);

        config()->set('geoflow.admin_ui_v3_enabled', false);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-gf-shell', false)
            ->assertSee('tailwindcss.play-cdn.js', false);
    }

    public function test_feature_flag_disables_v3_only_pages(): void
    {
        $admin = $this->admin('disabled_v3_owner', 'super_admin');
        config()->set('geoflow.admin_ui_v3_enabled', false);

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.ai-workspace'))
            ->assertNotFound();

        $this->get(route('admin.account.show'))->assertNotFound();
    }

    public function test_regular_admin_navigation_hides_protected_workflows(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('shell_editor', 'admin');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(AdminWeb::routePath('admin.ai-workspace'), false)
            ->assertDontSee(AdminWeb::routePath('admin.distribution.index'), false)
            ->assertDontSee(AdminWeb::routePath('admin.admin-users.index'), false)
            ->assertDontSee(AdminWeb::routePath('admin.admin-activity-logs'), false);
    }

    public function test_ai_workspace_is_a_demo_conversation_page(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('agent_owner', 'super_admin');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee('data-ai-workspace', false)
            ->assertSee('data-ai-conversation', false)
            ->assertSee('data-ai-stage', false)
            ->assertSee('data-ai-result', false)
            ->assertSee(__('admin.ai_workspace.disclaimer'));
    }

    public function test_site_settings_context_navigation_respects_permissions(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $superAdmin = $this->admin('settings_owner', 'super_admin');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($superAdmin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('class="gf-context-nav"', false)
            ->assertSee(AdminWeb::routePath('admin.admin-users.index'), false)
            ->assertSee(AdminWeb::routePath('admin.system-updates.index'), false);

        $regularAdmin = $this->admin('settings_editor', 'admin');
        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($regularAdmin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('class="gf-context-nav"', false)
            ->assertSee(AdminWeb::routePath('admin.security-settings.index'), false)
            ->assertDontSee(AdminWeb::routePath('admin.admin-users.index'), false)
            ->assertDontSee(AdminWeb::routePath('admin.system-updates.index'), false);
    }

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
