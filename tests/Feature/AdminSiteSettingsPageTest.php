<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SensitiveWord;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSiteSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_view_admin_base_path_setting(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_settings_admin',
            'password' => 'secret-123',
            'email' => 'site-settings-admin@example.com',
            'display_name' => 'Site Settings Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.field_admin_base_path'))
            ->assertSee(__('admin.site_settings.section_home_carousel'))
            ->assertSee(__('admin.site_settings.module_sensitive_words'))
            ->assertSee('value="'.AdminWeb::basePath().'"', false);
    }

    public function test_apple_support_theme_is_listed_without_becoming_active_theme(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_theme_admin',
            'password' => 'secret-123',
            'email' => 'site-theme-admin@example.com',
            'display_name' => 'Site Theme Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('Apple Support Inspired')
            ->assertSee('value="apple_support_clone"', false)
            ->assertDontSee('value="apple_support_clone" class="mt-1 text-blue-600 focus:ring-blue-500" checked', false);
    }

    public function test_generated_netease_theme_variants_are_listed_with_public_assets(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_theme_variants_admin',
            'password' => 'secret-123',
            'email' => 'site-theme-variants-admin@example.com',
            'display_name' => 'Site Theme Variants Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $expectedThemes = [
            'geoflow-template-01-ink-editorial' => 'GEOFlow 01 Ink Editorial',
            'geoflow-template-02-market-briefing' => 'GEOFlow 02 Market Briefing',
            'geoflow-template-03-salmon-insight' => 'GEOFlow 03 Salmon Insight',
            'geoflow-template-04-red-opinion' => 'GEOFlow 04 Red Opinion',
            'geoflow-template-05-wire-clean' => 'GEOFlow 05 Wire Clean',
            'geoflow-template-06-public-broadcast' => 'GEOFlow 06 Public Broadcast',
            'geoflow-template-07-breaking-red' => 'GEOFlow 07 Breaking Red',
            'geoflow-template-08-section-blue' => 'GEOFlow 08 Section Blue',
            'geoflow-template-09-tech-spectrum' => 'GEOFlow 09 Tech Spectrum',
            'geoflow-template-10-wired-feature' => 'GEOFlow 10 Wired Feature',
            'geoflow-template-11-product-newsroom' => 'GEOFlow 11 Product Newsroom',
            'geoflow-template-12-saas-gradient' => 'GEOFlow 12 SaaS Gradient',
            'geoflow-template-13-linear-system' => 'GEOFlow 13 Linear System',
            'geoflow-template-14-knowledge-paper' => 'GEOFlow 14 Knowledge Paper',
            'geoflow-template-15-reading-medium' => 'GEOFlow 15 Reading Medium',
            'geoflow-template-16-newsletter-letter' => 'GEOFlow 16 Newsletter Letter',
            'geoflow-template-17-executive-review' => 'GEOFlow 17 Executive Review',
            'geoflow-template-18-consulting-insight' => 'GEOFlow 18 Consulting Insight',
            'geoflow-template-19-tech-review' => 'GEOFlow 19 Tech Review',
            'geoflow-template-20-research-journal' => 'GEOFlow 20 Research Journal',
        ];

        $catalogIds = collect(app(SiteThemeCatalog::class)->all())
            ->pluck('id')
            ->all();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk();

        foreach ($expectedThemes as $themeId => $themeName) {
            $this->assertContains($themeId, $catalogIds);
            $this->assertFileExists(resource_path("views/theme/{$themeId}/layout.blade.php"));
            $this->assertFileExists(public_path("themes/{$themeId}/theme.css"));

            $response
                ->assertSee($themeName)
                ->assertSee('value="'.$themeId.'"', false);
        }
    }

    public function test_frontend_theme_headers_keep_home_as_first_navigation_item(): void
    {
        $headerFiles = array_merge(
            [resource_path('views/site/partials/header.blade.php')],
            glob(resource_path('views/theme/*/partials/header.blade.php')) ?: []
        );

        $this->assertNotEmpty($headerFiles);

        foreach ($headerFiles as $headerFile) {
            $contents = (string) file_get_contents($headerFile);
            $relativePath = str_replace(base_path().'/', '', $headerFile);
            $homePosition = strpos($contents, 'data-nav-item="home"');
            $categoryPosition = strpos($contents, '$navCategories');

            $this->assertNotFalse($homePosition, $relativePath.' should expose the home navigation item.');
            $this->assertStringContainsString("__('front.nav.home')", $contents, $relativePath.' should use the localized home label.');

            if ($categoryPosition !== false) {
                $this->assertLessThan(
                    $categoryPosition,
                    $homePosition,
                    $relativePath.' should render the home menu item before category links.'
                );
            }
        }
    }

    public function test_standard_admin_cannot_update_analytics_code(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        SiteSetting::query()->create([
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);

        $admin = Admin::query()->create([
            'username' => 'site_analytics_admin',
            'password' => 'secret-123',
            'email' => 'site-analytics-admin@example.com',
            'display_name' => 'Site Analytics Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '<script>changed()</script>',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $this->assertSame(
            '<script>existing()</script>',
            (string) SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value')
        );
    }

    public function test_sensitive_words_are_managed_under_site_settings(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_sensitive_admin',
            'password' => 'secret-123',
            'email' => 'site-sensitive-admin@example.com',
            'display_name' => 'Site Sensitive Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.sensitive-words'))
            ->assertOk()
            ->assertSee(__('admin.security.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.security-settings.index'))
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.sensitive-words.store'), [
                'words' => "测试敏感词\n测试敏感词\n另一个敏感词",
            ])
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->assertDatabaseHas('sensitive_words', ['word' => '测试敏感词']);
        $this->assertDatabaseHas('sensitive_words', ['word' => '另一个敏感词']);
        $this->assertSame(2, SensitiveWord::query()->count());

        $word = SensitiveWord::query()->where('word', '测试敏感词')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.sensitive-words.delete', ['wordId' => $word->id]))
            ->assertRedirect(route('admin.site-settings.sensitive-words'));

        $this->assertDatabaseMissing('sensitive_words', ['word' => '测试敏感词']);
    }

    public function test_admin_base_path_rejects_unsafe_value(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_settings_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-settings-invalid-admin@example.com',
            'display_name' => 'Site Settings Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => '../admin',
            ])
            ->assertSessionHasErrors('admin_base_path');
    }

    public function test_site_settings_save_home_carousel_slides(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_carousel_admin',
            'password' => 'secret-123',
            'email' => 'site-carousel-admin@example.com',
            'display_name' => 'Site Carousel Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.update'), [
                'site_name' => 'Frontend Site',
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'analytics_code' => '',
                'seo_title_template' => '{title} - {site_name}',
                'seo_description_template' => '{description}',
                'featured_limit' => 6,
                'per_page' => 12,
                'admin_base_path' => AdminWeb::basePath(),
                'home_carousel_slides' => [
                    [
                        'image_url' => '/storage/banners/home.jpg',
                        'title' => 'Home Banner',
                        'link_url' => 'article/demo',
                        'enabled' => '1',
                    ],
                    [
                        'image_url' => 'javascript:alert(1)',
                        'title' => 'Invalid Banner',
                        'link_url' => '',
                        'enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $raw = (string) SiteSetting::query()
            ->where('setting_key', 'home_carousel_slides')
            ->value('setting_value');
        $slides = json_decode($raw, true);

        $this->assertIsArray($slides);
        $this->assertCount(1, $slides);
        $this->assertSame('/storage/banners/home.jpg', $slides[0]['image_url']);
        $this->assertSame('Home Banner', $slides[0]['title']);
        $this->assertSame('/article/demo', $slides[0]['link_url']);
        $this->assertTrue($slides[0]['enabled']);
    }

    public function test_article_detail_text_ads_can_be_saved_updated_and_deleted_without_touching_sticky_ads(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_ads'],
            ['setting_value' => '[{"title":"Sticky CTA","enabled":true}]']
        );

        $admin = Admin::query()->create([
            'username' => 'site_text_ads_admin',
            'password' => 'secret-123',
            'email' => 'site-text-ads-admin@example.com',
            'display_name' => 'Site Text Ads Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.text-ads'), [
                'text_ad_modules' => [
                    [
                        'id' => 'bottom-ad',
                        'name' => 'Bottom Ad',
                        'placement' => 'content_bottom',
                        'enabled' => '1',
                        'sort_order' => 20,
                        'links' => [
                            [
                                'id' => 'bottom-link',
                                'text' => 'Bottom CTA',
                                'url' => 'offers/bottom',
                                'text_color' => '#0f0',
                                'open_new_tab' => '1',
                                'tracking_enabled' => '1',
                                'tracking_param' => 'utm_source=geoflow',
                                'enabled' => '1',
                                'sort_order' => 10,
                            ],
                        ],
                    ],
                    [
                        'id' => 'top-ad',
                        'name' => 'Top Ad',
                        'placement' => 'content_top',
                        'enabled' => '1',
                        'sort_order' => 10,
                        'links' => [
                            [
                                'id' => 'top-link',
                                'text' => 'Top CTA',
                                'url' => 'https://example.com/top',
                                'text_color' => '#2563EB',
                                'enabled' => '1',
                                'sort_order' => 10,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $saved = json_decode((string) SiteSetting::query()->where('setting_key', 'article_detail_text_ads')->value('setting_value'), true);
        $this->assertIsArray($saved);
        $this->assertCount(2, $saved);
        $this->assertSame('top-ad', $saved[0]['id']);
        $this->assertSame('top-link', $saved[0]['links'][0]['id']);
        $this->assertSame('#2563eb', $saved[0]['links'][0]['text_color']);
        $this->assertSame('/offers/bottom', $saved[1]['links'][0]['url']);
        $this->assertSame('#00ff00', $saved[1]['links'][0]['text_color']);
        $this->assertTrue($saved[1]['links'][0]['open_new_tab']);
        $this->assertTrue($saved[1]['links'][0]['tracking_enabled']);
        $this->assertSame('[{"title":"Sticky CTA","enabled":true}]', (string) SiteSetting::query()->where('setting_key', 'article_detail_ads')->value('setting_value'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.text-ads'), [
                'text_ad_modules' => [
                    [
                        'id' => 'top-ad',
                        'name' => 'Top Ad Updated',
                        'placement' => 'content_top',
                        'enabled' => '1',
                        'sort_order' => 5,
                        'links' => [
                            [
                                'id' => 'top-link',
                                'text' => 'Updated Top CTA',
                                'url' => '/offers/top',
                                'text_color' => '#123456',
                                'enabled' => '1',
                                'sort_order' => 10,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $updated = json_decode((string) SiteSetting::query()->where('setting_key', 'article_detail_text_ads')->value('setting_value'), true);
        $this->assertIsArray($updated);
        $this->assertCount(1, $updated);
        $this->assertSame('top-ad', $updated[0]['id']);
        $this->assertSame('Updated Top CTA', $updated[0]['links'][0]['text']);
    }

    public function test_article_detail_text_ads_reject_invalid_url_and_color(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_text_ads_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-text-ads-invalid-admin@example.com',
            'display_name' => 'Site Text Ads Invalid Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.text-ads'), [
                'text_ads' => [
                    [
                        'name' => 'Bad URL',
                        'placement' => 'content_top',
                        'text' => 'Bad URL',
                        'url' => 'javascript:alert(1)',
                        'text_color' => '#2563eb',
                        'enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.text-ads'), [
                'text_ads' => [
                    [
                        'name' => 'Bad Color',
                        'placement' => 'content_bottom',
                        'text' => 'Bad Color',
                        'url' => '/offers',
                        'text_color' => 'red',
                        'enabled' => '1',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors();
    }

    public function test_article_detail_text_ads_reject_more_than_ten_links_per_module(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_text_ads_max_admin',
            'password' => 'secret-123',
            'email' => 'site-text-ads-max-admin@example.com',
            'display_name' => 'Site Text Ads Max Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $links = [];
        for ($i = 1; $i <= 11; $i++) {
            $links[] = [
                'text' => 'CTA '.$i,
                'url' => '/offers/'.$i,
                'text_color' => '#2563eb',
                'enabled' => '1',
                'sort_order' => $i * 10,
            ];
        }

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.text-ads'), [
                'text_ad_modules' => [
                    [
                        'name' => 'Too Many Links',
                        'placement' => 'content_top',
                        'enabled' => '1',
                        'links' => $links,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors();
    }
}
