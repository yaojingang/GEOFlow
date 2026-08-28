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
            ->assertDontSee('gf-topbar__context', false)
            ->assertSee(AdminWeb::routePath('admin.ai-workspace'), false)
            ->assertSee(AdminWeb::routePath('admin.distribution.index'), false)
            ->assertDontSee('tailwindcss.play-cdn.js', false);

        config()->set('geoflow.admin_ui_v3_enabled', false);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('data-gf-shell', false)
            ->assertSee('tailwindcss.play-cdn.js', false);
    }

    public function test_v3_shell_primes_sidebar_state_before_the_page_can_render(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('stable_shell_owner', 'super_admin');

        $html = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $bootstrapPosition = strpos($html, 'data-gf-sidebar-bootstrap');
        $lucidePosition = strpos($html, 'data-lucide-runtime');
        $bodyPosition = strpos($html, '<body');

        $this->assertNotFalse($bootstrapPosition);
        $this->assertNotFalse($lucidePosition);
        $this->assertNotFalse($bodyPosition);
        $this->assertLessThan($lucidePosition, $bootstrapPosition);
        $this->assertLessThan($bodyPosition, $bootstrapPosition);
        $this->assertStringContainsString('data-gf-sidebar-state', $html);
        $this->assertStringContainsString('data-gf-ui-booting', $html);
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

    public function test_ai_workspace_renders_the_help_assistant_surface(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', false);
        $admin = $this->admin('help_owner', 'super_admin');

        $response = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.ai-workspace'))
            ->assertOk()
            ->assertSee('data-ai-workspace', false)
            ->assertSee('data-ai-history-list', false)
            ->assertSee('data-ai-new', false)
            ->assertSee('data-sidebar-recent-toggle', false)
            ->assertSee('data-recent-url="'.AdminWeb::routePath('admin.recent.index').'"', false)
            ->assertDontSee('data-sidebar-recent-filter', false)
            ->assertDontSee('data-sidebar-recent-feature', false)
            ->assertDontSee('gf-recent-dot', false)
            ->assertDontSee('data-ai-history-toggle', false)
            ->assertDontSee('class="gf-ai-history"', false)
            ->assertSee('data-ai-form', false)
            ->assertSee('data-ai-suggestion', false)
            ->assertSee(__('admin.ai_workspace.suggestions'))
            ->assertSee(__('admin.ai_workspace.local_help_available'))
            ->assertSee('data-runtime-enabled="false"', false)
            ->assertDontSee('data-ai-runs', false)
            ->assertDontSee('data-capability-carousel', false)
            ->assertDontSee('data-ai-showcase-carousel', false)
            ->assertDontSee('data-ai-error-dialog', false);

        self::assertMatchesRegularExpression('/<textarea[^>]*data-ai-input[^>]*><\/textarea>/', $response->getContent());
        preg_match('/<textarea[^>]*data-ai-input[^>]*>/', (string) $response->getContent(), $composerMatch);
        self::assertStringNotContainsString(' disabled', $composerMatch[0] ?? '');
        self::assertGreaterThanOrEqual(4, substr_count($response->getContent(), 'data-ai-suggestion='));
        self::assertLessThanOrEqual(6, substr_count($response->getContent(), 'data-ai-suggestion='));
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

    public function test_community_dialog_shows_the_author_wechat_and_project_links(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('community_owner', 'super_admin');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-dialog-open="qr"', false)
            ->assertSee('data-gf-modal="qr"', false)
            ->assertSee(asset('assets/images/yao-jingang-wechat.jpg'), false)
            ->assertSee(__('admin.ui_v3.qr_title'))
            ->assertSee(__('admin.ui_v3.qr_invitation'))
            ->assertSee('href="https://github.com/yaojingang/GEOFlow"', false)
            ->assertSee('href="https://x.com/yaojingang"', false)
            ->assertSee('target="_blank" rel="noopener noreferrer"', false)
            ->assertDontSee('data-qr-canvas', false)
            ->assertDontSee('data-qr-value', false);

        $this->assertFileExists(public_path('assets/images/yao-jingang-wechat.jpg'));
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
