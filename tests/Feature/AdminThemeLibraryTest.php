<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Services\Admin\SiteThemePackageService;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Site\SiteAppearanceService;
use App\Services\Topics\TopicTemplateCatalog;
use App\Services\Topics\TopicThemeCompatibility;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class AdminThemeLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_library_contains_six_featured_themes_and_keeps_the_full_runtime_catalog(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.site-settings.index'))->assertOk();
        $library = $response->viewData('themeLibrary');
        $this->assertSame(['', 'geoflow-template-21-enterprise-signature', 'geoflow-template-08-section-blue', 'geoflow-template-01-ink-editorial', 'geoflow-template-14-knowledge-paper', 'geoflow-template-20-research-journal'], collect($library['items']->items())->pluck('id')->all());
        $response->assertSee(__('theme_library.selections.enterprise.name'))->assertDontSee('Apple Support Inspired')->assertDontSee('GEOFlow 02 Market Briefing');
        $this->assertContains('apple_support_clone', app(SiteThemeCatalog::class)->ids());
        $this->assertFileExists(resource_path('views/theme/apple_support_clone/home.blade.php'));
    }

    public function test_current_theme_remains_visible_when_search_has_no_results(): void
    {
        $this->setTheme('geoflow-template-20-research-journal');
        $response = $this->actingAs($this->admin(), 'admin')->get(route('admin.site-settings.index', ['theme_tab' => 'archived', 'theme_search' => 'NO-MATCH-987']))->assertOk();
        $this->assertSame('geoflow-template-20-research-journal', $response->viewData('themeLibrary')['current']['id']);
        $response->assertSee(__('theme_library.empty'))->assertSee(__('theme_library.clear'))->assertSee(__('theme_library.selections.research.name'));
    }

    public function test_template_versions_are_grouped_with_active_version_first_and_search_can_find_an_older_id(): void
    {
        $this->withVersionGroupFixtures();
        $this->setTheme('fixture-family-v4');
        $this->actingAs($this->admin(), 'admin');
        $library = $this->get(route('admin.site-settings.index', ['theme_tab' => 'personal']))->assertOk()->viewData('themeLibrary');
        $group = collect($library['items']->items())->firstWhere('id', 'fixture-family-v4');
        $this->assertCount(8, $group['versions']);
        $this->assertTrue($group['active']);
        $result = $this->get(route('admin.site-settings.index', ['theme_tab' => 'personal', 'theme_search' => 'fixture-family-v10']))->assertOk()->viewData('themeLibrary');
        $this->assertSame('fixture-family-v10', $result['items']->items()[0]['id']);
    }

    public function test_latest_template_version_is_shown_when_no_version_is_active(): void
    {
        $this->withVersionGroupFixtures();
        $library = app(SiteThemeCatalog::class)->library(['theme_tab' => 'personal'], '');
        $this->assertNotNull(collect($library['items']->items())->firstWhere('id', 'fixture-family-v11'));
    }

    public function test_archive_and_restore_are_persistent_without_changing_active_binding_or_runtime_files(): void
    {
        $this->setTheme('geoflow-template-20-research-journal');
        $binding = app(SiteAppearanceService::class)->binding();
        $binding->forceFill(['lock_version' => 9])->save();
        $snapshot = $binding->fresh()->toArray();
        $id = 'geoflow-template-08-section-blue';
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.themes.library'), ['library_action' => 'archive', 'theme_ids' => [$id]])->assertRedirect();
        $archived = app(SiteThemeCatalog::class)->library(['theme_tab' => 'archived', 'theme_search' => $id], 'geoflow-template-20-research-journal');
        $this->assertSame($id, $archived['items']->items()[0]['id']);
        $this->assertSame($snapshot, $binding->fresh()->toArray());
        $this->assertContains($id, app(SiteThemeCatalog::class)->ids());
        $this->assertFileExists(public_path('themes/'.$id.'/theme.css'));
        $this->post(route('admin.site-settings.themes.library'), ['library_action' => 'restore', 'theme_ids' => [$id]])->assertRedirect();
        $featured = app(SiteThemeCatalog::class)->library([], 'geoflow-template-20-research-journal');
        $this->assertCount(6, $featured['items']);
        $this->assertSame($snapshot, $binding->fresh()->toArray());
    }

    public function test_mixed_archive_retains_active_theme_and_reports_it(): void
    {
        $active = 'geoflow-template-08-section-blue';
        $other = 'geoflow-template-14-knowledge-paper';
        $this->setTheme($active);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $response = $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.themes.library'), ['library_action' => 'archive', 'theme_ids' => [$active, $other]])->assertRedirect()->assertSessionHas('theme_library_retained', [__('theme_library.selections.news.name')]);
        $state = json_decode(SiteSetting::query()->where('setting_key', SiteThemeCatalog::LIBRARY_STATE_KEY)->value('setting_value'), true);
        $this->assertArrayNotHasKey($active, $state);
        $this->assertSame('archived', $state[$other]);
        $this->assertSame($active, SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'));
    }

    public function test_restoring_a_sample_places_it_in_my_themes(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.themes.library'), ['library_action' => 'restore', 'theme_ids' => ['apple_support_clone']])->assertRedirect();
        $library = app(SiteThemeCatalog::class)->library(['theme_tab' => 'personal', 'theme_search' => 'apple_support_clone'], '');
        $this->assertSame('apple_support_clone', $library['items']->items()[0]['id']);
    }

    public function test_batch_input_is_validated_and_regular_admin_cannot_change_library_state(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->admin(), 'admin');
        $this->post(route('admin.site-settings.themes.library'), ['library_action' => 'remove', 'theme_ids' => ['unknown']])->assertSessionHasErrors(['library_action', 'theme_ids.0']);
        $this->post(route('admin.site-settings.themes.library'), ['library_action' => 'archive', 'theme_ids' => []])->assertSessionHasErrors('theme_ids');
        $this->assertDatabaseMissing('site_settings', ['setting_key' => SiteThemeCatalog::LIBRARY_STATE_KEY]);
        $this->actingAs($this->admin('regular', 'admin'), 'admin')->post(route('admin.site-settings.themes.library'), ['library_action' => 'archive', 'theme_ids' => ['apple_support_clone']])->assertForbidden();
    }

    public function test_search_is_escaped_and_pagination_preserves_the_query(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $response = $this->get(route('admin.site-settings.index', ['theme_tab' => 'archived', 'theme_search' => '<script>alert(1)</script>']))->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(0, $response->viewData('themeLibrary')['items']->total());
        $library = $this->get(route('admin.site-settings.index', ['theme_tab' => 'archived', 'theme_source' => 'builtin', 'theme_page' => 999]))->assertOk()->viewData('themeLibrary');
        $this->assertSame(2, $library['items']->currentPage());
        $this->assertStringContainsString('theme_source=builtin', $library['items']->previousPageUrl());
        $this->assertStringEndsWith('#site-settings-theme', $library['items']->previousPageUrl());
    }

    public function test_explicit_system_default_is_reflected_in_the_current_card_after_activation(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.site-settings.theme'), ['active_theme' => ''])->assertRedirect(route('admin.site-settings.index').'#site-settings-theme');
        $library = $this->get(route('admin.site-settings.index'))->assertOk()->viewData('themeLibrary');
        $this->assertSame('', $library['current']['id']);
        $this->assertTrue($library['items']->items()[0]['active']);
        $this->assertSame('', SiteSettingsBag::get('active_theme'));
        $this->get(route('site.home'))->assertOk()->assertViewIs('site.home');
    }

    public function test_builtin_and_default_preview_render_real_frames_without_switching_theme(): void
    {
        $active = 'geoflow-template-20-research-journal';
        $this->setTheme($active);
        $this->actingAs($this->admin(), 'admin');
        foreach (['geoflow-template-08-section-blue', SiteThemeCatalog::DEFAULT_PREVIEW_ID] as $id) {
            $preview = $this->get(route('admin.site-settings.themes.preview', ['themeId' => $id]))->assertOk();
            $this->get($preview->viewData('frameUrl'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
        $this->assertSame($active, SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'));
        $this->get(route('admin.site-settings.themes.preview', ['themeId' => $active, 'page' => 'topic-show']))->assertOk()->assertViewHas('frameUrl', null);
        $this->get(route('admin.site-settings.themes.preview', ['themeId' => 'not-installed']))->assertNotFound();
        $this->get(route('admin.site-settings.themes.preview.frame', ['themeId' => $active, 'sitePath' => 'admin/dashboard']))->assertNotFound();
    }

    public function test_preview_and_library_mutation_preserve_permission_and_csrf_boundaries(): void
    {
        $id = 'geoflow-template-08-section-blue';
        $this->actingAs($this->admin('regular', 'admin'), 'admin')->get(route('admin.site-settings.themes.preview', ['themeId' => $id]))->assertForbidden();
        $this->get(route('admin.site-settings.themes.preview.frame', ['themeId' => $id]))->assertForbidden();
        $this->actingAs($this->admin(), 'admin');
        $this->app->instance('env', 'local');
        try {
            $this->withMiddleware(ValidateCsrfToken::class)->post(route('admin.site-settings.themes.library'), ['library_action' => 'archive', 'theme_ids' => [$id]])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_imported_theme_is_visible_in_my_library_and_generic_preview(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $packages = app(SiteThemePackageService::class);
        $inspection = $packages->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        $packages->install($admin->id, $inspection['token'], true);
        $this->actingAs($admin, 'admin');
        $library = $this->get(route('admin.site-settings.index', ['theme_tab' => 'personal', 'theme_source' => 'installed']))->assertOk()->viewData('themeLibrary');
        $this->assertSame('fixture-theme', $library['items']->items()[0]['id']);
        $preview = $this->get(route('admin.site-settings.themes.preview', ['themeId' => 'fixture-theme']))->assertOk();
        $this->get($preview->viewData('frameUrl'))->assertOk()->assertSee('Fixture home');
    }

    public function test_preview_isolates_other_templates_from_the_active_immutable_revision(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $this->setTheme('geoflow-template-01-ink-editorial');
        $revision = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), 'geoflow-template-01-ink-editorial', [
            'resources/views/site/home.blade.php' => "<link rel=\"stylesheet\" href=\"{{ asset('themes/geoflow-template-01-ink-editorial/theme.css') }}\"><h1>Frozen library marker</h1>",
            'public/themes/geoflow-template-01-ink-editorial/theme.css' => '/* frozen-library-css */',
        ], ['active_theme' => 'geoflow-template-01-ink-editorial', 'site_name' => 'Frozen snapshot']);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $revision->theme_id, 'revision_id' => $revision->id, 'settings' => $revision->settings]);
        ThemeRelease::query()->create(['id' => (string) Str::uuid(), 'site_key' => 'primary', 'workspace_id' => (string) Str::uuid(), 'revision_id' => $revision->id, 'admin_id' => $admin->id, 'binding_version' => 1, 'changes' => [], 'plan_sha256' => str_repeat('a', 64)]);
        $this->actingAs($admin, 'admin');
        $this->get(route('admin.site-settings.themes.preview.frame', ['themeId' => SiteThemeCatalog::DEFAULT_PREVIEW_ID]))
            ->assertOk()->assertDontSee('Frozen library marker')->assertDontSee('Frozen snapshot');
        $packages = app(SiteThemePackageService::class);
        $inspection = $packages->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        $packages->install($admin->id, $inspection['token'], true);
        $this->get(route('admin.site-settings.themes.preview.frame', ['themeId' => 'fixture-theme']))
            ->assertOk()->assertSee('Fixture home')->assertDontSee('Frozen library marker');
        $this->get(route('admin.site-settings.themes.preview.frame', ['themeId' => $revision->theme_id]))
            ->assertOk()->assertSee('Frozen library marker')
            ->assertSee('/theme-assets/'.$revision->id.'/theme.css', false)
            ->assertHeader('X-GEOFlow-Theme-Revision', $revision->id);
        $asset = $this->get('/theme-assets/'.$revision->id.'/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->assertStringContainsString('frozen-library-css', file_get_contents($asset->baseResponse->getFile()->getPathname()));
        $this->get('/')->assertOk()->assertSee('Frozen library marker');
        $this->assertSame($revision->id, SiteThemeBinding::query()->find('primary')->revision_id);
    }

    public function test_explicit_default_uses_core_topic_layouts(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $files = ThemePackageFixture::files();
        $path = 'resources/views/theme/fixture-theme/manifest.json';
        $manifest = json_decode($files[$path], true);
        $manifest['topic'] = ['contract' => 1, 'layouts' => [['id' => 'default', 'name' => 'Package default', 'view' => 'site.topics.templates.default'], ['id' => 'special', 'name' => 'Special package layout', 'view' => 'topics/templates/special.blade.php']], 'homepage_module' => ['view' => 'site.partials.topic-home', 'enabled' => false]];
        $files[$path] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $files['resources/views/theme/fixture-theme/topics/templates/special.blade.php'] = '<p>Special layout</p>';
        $packages = app(SiteThemePackageService::class);
        $inspection = $packages->inspect(ThemePackageFixture::archive($files), $admin->id);
        $packages->install($admin->id, $inspection['token'], true);
        config(['geoflow.default_theme' => 'fixture-theme']);
        $this->setTheme('');
        $this->assertSame(TopicTemplateCatalog::CORE, app(TopicTemplateCatalog::class)->options('primary'));
        $this->assertNull(app(TopicThemeCompatibility::class)->ensure('primary', $admin));
    }

    private function withVersionGroupFixtures(): void
    {
        config(['theme-library.version_groups' => ['fixture-family' => array_map(fn ($version) => 'fixture-family-v'.$version, range(4, 11))]]);
        $themes = app(SiteThemeCatalog::class)->all();
        $ids = array_column($themes, 'id');
        foreach (range(4, 11) as $version) {
            $id = 'fixture-family-v'.$version;
            if (! in_array($id, $ids, true)) {
                $themes[] = ['id' => $id, 'name' => 'Fixture family v'.$version, 'description' => '', 'version' => '1.'.$version.'.0', 'source' => 'builtin'];
            }
        }
        $this->partialMock(SiteThemeCatalog::class)->shouldReceive('all')->andReturn($themes);
    }

    private function setTheme(string $id): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $id]);
        SiteSettingsBag::forget();
    }

    private function admin(string $username = 'library_admin', string $role = 'super_admin'): Admin
    {
        return Admin::query()->create(['username' => $username, 'password' => 'password', 'email' => $username.'@example.com', 'display_name' => $username, 'role' => $role, 'status' => 'active']);
    }
}
