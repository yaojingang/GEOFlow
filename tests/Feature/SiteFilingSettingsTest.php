<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFilingSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_filing_settings_and_the_default_footer_links_the_filing_number(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.field_filing_info'))
            ->assertSee(__('admin.site_settings.field_filing_url'))
            ->assertSee('value="https://beian.miit.gov.cn/"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), $this->siteSettingsPayload([
                'filing_info' => '京ICP备12345678号-1',
                'filing_url' => 'https://beian.miit.gov.cn/',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'filing_info',
            'setting_value' => '京ICP备12345678号-1',
        ]);
        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'filing_url',
            'setting_value' => 'https://beian.miit.gov.cn/',
        ]);

        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('京ICP备12345678号-1')
            ->assertSee('href="https://beian.miit.gov.cn/"', false)
            ->assertSee('rel="nofollow noopener noreferrer"', false);
    }

    public function test_admin_rejects_an_invalid_filing_url(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.update'), $this->siteSettingsPayload([
                'filing_info' => '京ICP备12345678号-1',
                'filing_url' => 'ftp://example.com/filing',
            ]))
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('filing_url');

        $this->assertDatabaseMissing('site_settings', [
            'setting_key' => 'filing_info',
        ]);
    }

    public function test_frontend_hides_the_filing_link_when_the_filing_information_is_blank(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'filing_info'],
            ['setting_value' => '']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'filing_url'],
            ['setting_value' => 'https://beian.miit.gov.cn/']
        );
        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertDontSee('https://beian.miit.gov.cn/', false);
    }

    public function test_every_selectable_theme_renders_the_shared_filing_footer(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'filing_info'],
            ['setting_value' => '京ICP备12345678号-1']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'filing_url'],
            ['setting_value' => 'https://beian.miit.gov.cn/']
        );

        $themes = app(SiteThemeCatalog::class)->all();
        $this->assertNotEmpty($themes);

        foreach ($themes as $theme) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => 'active_theme'],
                ['setting_value' => (string) $theme['id']]
            );
            SiteSettingsBag::forget();

            $this->get(route('site.home'))
                ->assertOk()
                ->assertSee('京ICP备12345678号-1')
                ->assertSee('href="https://beian.miit.gov.cn/"', false);
        }
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'site_filing_admin',
            'password' => 'secret-123',
            'email' => 'site-filing-admin@example.com',
            'display_name' => 'Site Filing Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function siteSettingsPayload(array $overrides = []): array
    {
        return array_replace([
            'site_name' => 'Frontend Site',
            'site_subtitle' => '',
            'site_description' => '',
            'site_keywords' => '',
            'copyright_info' => '© 2026 Frontend Site',
            'filing_info' => '',
            'filing_url' => '',
            'site_logo' => '',
            'site_favicon' => '',
            'analytics_code' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => 6,
            'per_page' => 12,
            'admin_base_path' => AdminWeb::basePath(),
        ], $overrides);
    }
}
