<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
            ->assertDontSee(AdminWeb::routePath('admin.admin-activity-logs'), false)
            ->assertDontSee('data-system-update-link', false);
    }

    public function test_super_admin_update_icon_links_to_the_update_center_with_or_without_a_new_version(): void
    {
        config([
            'geoflow.admin_ui_v3_enabled' => true,
            'geoflow.update_center_enabled' => true,
            'geoflow.update_check_enabled' => false,
        ]);
        $admin = $this->admin('update_link_owner', 'super_admin');

        $currentHtml = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertUpdateCenterLink($currentHtml, false);

        Cache::flush();
        config([
            'geoflow.app_version' => '2.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);
        Http::fake([
            'https://example.test/version.json' => Http::response(['version' => '2.1']),
        ]);

        $updateHtml = $this->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertUpdateCenterLink($updateHtml, true);
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

    public function test_model_not_found_keeps_its_explanation_below_the_compact_topbar_identity(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('missing_model_owner', 'super_admin');

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => 999_999_999]))
            ->assertNotFound()
            ->assertSee('data-page-icon="circle-alert"', false)
            ->assertSee(__('admin_pages.not_found'))
            ->assertSee('data-gf-page-heading="content"', false)
            ->assertSee(__('admin.common.not_found_title'))
            ->assertSee(__('admin.common.not_found_desc'));
    }

    public function test_site_settings_context_navigation_respects_permissions(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $superAdmin = $this->admin('settings_owner', 'super_admin');

        $superResponse = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
            ->actingAs($superAdmin, 'admin')
            ->get(route('admin.site-settings.index'));

        $superResponse
            ->assertOk()
            ->assertSee('class="gf-context-nav"', false)
            ->assertSee('data-settings-navigation', false)
            ->assertSee(AdminWeb::routePath('admin.admin-users.index'), false)
            ->assertSee(AdminWeb::routePath('admin.system-updates.index'), false);

        $document = new \DOMDocument;
        $document->loadHTML((string) $superResponse->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $navigation = $xpath->query('//*[@data-settings-navigation]')?->item(0);

        self::assertNotNull($navigation);
        self::assertSame(6, $xpath->query('.//*[@data-settings-navigation-item]', $navigation)?->length);
        self::assertSame(6, $xpath->query('.//*[@data-settings-navigation-dot]', $navigation)?->length);

        $activeItem = $xpath->query('.//*[@aria-current="page"]', $navigation)?->item(0);
        self::assertNotNull($activeItem);
        self::assertContains('border-blue-600', explode(' ', (string) $activeItem->attributes?->getNamedItem('class')?->nodeValue));
        self::assertNotContains('bg-blue-50', explode(' ', (string) $activeItem->attributes?->getNamedItem('class')?->nodeValue));

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

    public function test_ai_configurator_navigation_is_shared_by_the_overview_and_management_pages(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('ai_configurator_owner', 'super_admin');
        $routes = [
            'admin.ai.configurator' => null,
            'admin.ai-models.index' => 'models',
            'admin.ai-prompts' => 'prompts',
            'admin.ai-special-prompts' => 'special',
            'admin.ai-source-providers.index' => 'sources',
        ];

        foreach ($routes as $routeName => $activeKey) {
            $response = $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])
                ->actingAs($admin, 'admin')
                ->get(route($routeName));

            $response
                ->assertOk()
                ->assertSee('data-ai-configurator-navigation', false)
                ->assertSee(AdminWeb::routePath('admin.ai-models.index'), false)
                ->assertSee(AdminWeb::routePath('admin.ai-prompts'), false)
                ->assertSee(AdminWeb::routePath('admin.ai-special-prompts'), false)
                ->assertSee(AdminWeb::routePath('admin.ai-source-providers.index'), false);

            $document = new \DOMDocument;
            $document->loadHTML((string) $response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
            $xpath = new \DOMXPath($document);
            $navigation = $xpath->query('//*[@data-ai-configurator-navigation]')?->item(0);

            self::assertNotNull($navigation, $routeName);
            self::assertSame(4, $xpath->query('.//*[@data-ai-configurator-navigation-item]', $navigation)?->length, $routeName);
            self::assertSame(4, $xpath->query('.//*[@data-ai-configurator-navigation-dot]', $navigation)?->length, $routeName);

            $activeItems = $xpath->query('.//*[@aria-current="page"]', $navigation);
            self::assertSame($activeKey === null ? 0 : 1, $activeItems?->length, $routeName);
            if ($activeKey !== null) {
                self::assertSame($activeKey, $activeItems?->item(0)?->attributes?->getNamedItem('data-ai-configurator-navigation-item')?->nodeValue, $routeName);
            }
        }
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

    private function assertUpdateCenterLink(string $html, bool $hasUpdate): void
    {
        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new \DOMXPath($document);
        $links = $xpath->query('//*[@data-system-update-link]');

        self::assertSame(1, $links?->length);
        self::assertSame('a', $links?->item(0)?->nodeName);
        self::assertSame(
            AdminWeb::routePath('admin.system-updates.index'),
            $links?->item(0)?->attributes?->getNamedItem('href')?->nodeValue,
        );
        self::assertSame(
            $hasUpdate ? 1 : 0,
            $xpath->query('.//*[@data-update-indicator]', $links?->item(0))?->length,
        );
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
