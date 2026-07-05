<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_site_settings_page_renders_before_lead_forms_table_is_migrated(): void
    {
        Schema::dropIfExists('lead_submissions');
        Schema::dropIfExists('lead_forms');

        $admin = Admin::query()->create([
            'username' => 'site_settings_no_leads_admin',
            'password' => 'secret-123',
            'email' => 'site-settings-no-leads-admin@example.com',
            'display_name' => 'Site Settings No Leads Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.page_title'))
            ->assertSee(__('admin.site_settings.homepage.lead_form_none'));
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

    public function test_homepage_modules_can_be_saved_and_rendered_on_homepage(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_modules_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-modules-admin@example.com',
            'display_name' => 'Site Homepage Modules Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $category = Category::query()->create([
            'name' => 'Resources',
            'slug' => 'resources',
            'description' => 'Resource category.',
            'sort_order' => 10,
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        Article::query()->create([
            'title' => 'Latest GEO Resource',
            'slug' => 'latest-geo-resource',
            'excerpt' => 'A latest resource for homepage modules.',
            'content' => 'Homepage module article body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => 'GEO,modules',
            'meta_description' => 'Homepage module article.',
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 3,
            'is_ai_generated' => 1,
            'is_hot' => false,
            'is_featured' => false,
            'published_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => '#0f766e',
                    'background_color' => '#ffffff',
                    'surface_color' => '#ffffff',
                    'text_color' => '#111827',
                    'muted_color' => '#64748b',
                    'container_width' => 'wide',
                    'section_spacing' => 'normal',
                    'radius' => 'soft',
                ],
                'homepage_modules' => [
                    [
                        'id' => 'hero-module',
                        'type' => 'hero',
                        'layout' => 'split',
                        'enabled' => '1',
                        'sort_order' => 10,
                        'title' => 'Enterprise GEO Hub',
                        'subtitle' => 'Knowledge workflow',
                        'body' => 'Organize homepage content with custom modules.',
                        'link_text' => 'View resources',
                        'link_url' => 'category/resources',
                    ],
                    [
                        'id' => 'latest-module',
                        'type' => 'article_collection',
                        'layout' => 'grid',
                        'data_source' => 'latest',
                        'enabled' => '1',
                        'sort_order' => 20,
                        'title' => 'Latest resources',
                        'subtitle' => 'Fresh content',
                        'limit' => 4,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $style = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_style')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertCount(2, $modules);
        $this->assertSame('/category/resources', $modules[0]['link_url']);
        $this->assertSame('article_collection', $modules[1]['type']);
        $this->assertSame('#0f766e', $style['accent_color']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Enterprise GEO Hub')
            ->assertSee('Latest resources')
            ->assertSee('Latest GEO Resource');
    }

    public function test_homepage_modules_reject_invalid_url_and_color(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_modules_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-modules-invalid-admin@example.com',
            'display_name' => 'Site Homepage Modules Invalid Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => 'blue',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'rich_text',
                        'enabled' => '1',
                        'title' => 'Invalid color',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_style');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => '#2563eb',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'hero',
                        'enabled' => '1',
                        'title' => 'Invalid URL',
                        'link_text' => 'Bad link',
                        'link_url' => 'javascript:alert(1)',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_modules');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => '#2563eb',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'rich_text',
                        'enabled' => '1',
                        'title' => 'Invalid module color',
                        'accent_color' => 'orange',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_modules');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => '#2563eb',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'rich_text',
                        'enabled' => '1',
                        'title' => 'Invalid alignment',
                        'alignment' => 'right',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_modules');
    }

    public function test_homepage_custom_html_modules_strip_unsafe_attributes(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_html_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-html-admin@example.com',
            'display_name' => 'Site Homepage HTML Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules'), [
                'homepage_style' => [
                    'accent_color' => '#2563eb',
                ],
                'homepage_modules' => [
                    [
                        'type' => 'custom_html',
                        'enabled' => '1',
                        'sort_order' => 10,
                        'title' => 'Custom proof',
                        'custom_html' => '<section style="background:red" onclick="alert(1)"><a href="javascript:alert(1)" onclick="alert(2)" target="_blank" title="Bad">Bad link</a><a href="/safe" style="color:red" target="_blank">Safe link</a><img src=x onerror=alert(3)></section>',
                    ],
                    [
                        'type' => 'feature_grid',
                        'enabled' => '1',
                        'sort_order' => 20,
                        'title' => 'Feature proof',
                        'body' => "Unsafe row | Should not link | javascript:alert(4)\nSafe row | Should link | /safe-row",
                    ],
                ],
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertStringNotContainsString('onclick', $modules[0]['custom_html']);
        $this->assertStringNotContainsString('style=', $modules[0]['custom_html']);
        $this->assertStringNotContainsString('javascript:', $modules[0]['custom_html']);
        $this->assertStringNotContainsString('<img', $modules[0]['custom_html']);
        $this->assertStringContainsString('<a target="_blank" rel="noopener nofollow" title="Bad">Bad link</a>', $modules[0]['custom_html']);
        $this->assertStringContainsString('<a href="/safe" target="_blank" rel="noopener nofollow">Safe link</a>', $modules[0]['custom_html']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Custom proof')
            ->assertSee('Feature proof')
            ->assertSee('<a target="_blank" rel="noopener nofollow" title="Bad">Bad link</a>', false)
            ->assertSee('<a href="/safe" target="_blank" rel="noopener nofollow">Safe link</a>', false)
            ->assertSee('<a href="/safe-row">Safe row</a>', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<img', false);
    }

    public function test_homepage_module_preset_can_replace_modules_and_render(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_preset_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-preset-admin@example.com',
            'display_name' => 'Site Homepage Preset Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules.preset'), [
                'homepage_preset' => 'enterprise_brand',
                'preset_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $style = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_style')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertCount(5, $modules);
        $this->assertSame('hero', $modules[0]['type']);
        $this->assertSame('把首页升级成业务增长入口', $modules[0]['title']);
        $this->assertSame('#2563eb', $style['accent_color']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('把首页升级成业务增长入口')
            ->assertSee('核心能力')
            ->assertSee('运营概览');
    }

    public function test_homepage_module_preset_can_append_existing_modules(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_preset_append_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-preset-append-admin@example.com',
            'display_name' => 'Site Homepage Preset Append Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_style'],
            ['setting_value' => (string) json_encode([
                'accent_color' => '#0f172a',
                'background_color' => '#ffffff',
                'surface_color' => '#ffffff',
                'text_color' => '#111827',
                'muted_color' => '#6b7280',
                'container_width' => 'narrow',
                'section_spacing' => 'compact',
                'radius' => 'none',
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => (string) json_encode([[
                'id' => 'existing-module',
                'type' => 'rich_text',
                'layout' => 'single',
                'data_source' => 'latest',
                'enabled' => true,
                'sort_order' => 5,
                'title' => '现有模块',
                'subtitle' => '',
                'body' => 'Existing module body.',
                'image_url' => '',
                'link_text' => '',
                'link_url' => '',
                'limit' => 4,
                'custom_html' => '',
            ]], JSON_UNESCAPED_UNICODE)]
        );

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules.preset'), [
                'homepage_preset' => 'content_portal',
                'preset_mode' => 'append',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $style = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_style')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertGreaterThan(1, count($modules));
        $this->assertContains('现有模块', array_column($modules, 'title'));
        $this->assertContains('让内容成为持续增长资产', array_column($modules, 'title'));
        $this->assertSame('#0f172a', $style['accent_color']);
    }

    public function test_homepage_design_json_import_can_replace_modules_and_render(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_import_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-import-admin@example.com',
            'display_name' => 'Site Homepage Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $design = [
            'style' => [
                'accent' => '#0f766e',
                'container' => 'wide',
                'spacing' => 'relaxed',
            ],
            'modules' => [
                [
                    'kind' => 'hero',
                    'headline' => 'Agent 生成的首页主视觉',
                    'copy' => '支持多模块首页展示。',
                    'cta_label' => '查看文章',
                    'cta_url' => '/articles',
                    'order' => 10,
                ],
                [
                    'kind' => 'metrics',
                    'heading' => '业务指标',
                    'content' => [
                        ['title' => '知识库', 'description' => '8'],
                        ['title' => '渠道', 'description' => '12'],
                    ],
                    'order' => 20,
                ],
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules.import'), [
                'homepage_design_json' => (string) json_encode($design, JSON_UNESCAPED_UNICODE),
                'import_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $style = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_style')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertCount(2, $modules);
        $this->assertSame('hero', $modules[0]['type']);
        $this->assertSame('Agent 生成的首页主视觉', $modules[0]['title']);
        $this->assertSame('metric_band', $modules[1]['type']);
        $this->assertStringContainsString('知识库|8', $modules[1]['body']);
        $this->assertSame('#0f766e', $style['accent_color']);
        $this->assertSame('wide', $style['container_width']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Agent 生成的首页主视觉')
            ->assertSee('业务指标');
    }

    public function test_homepage_design_json_import_can_append_alias_sections(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_import_append_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-import-append-admin@example.com',
            'display_name' => 'Site Homepage Import Append Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_style'],
            ['setting_value' => (string) json_encode([
                'accent_color' => '#0f172a',
                'background_color' => '#ffffff',
                'surface_color' => '#ffffff',
                'text_color' => '#111827',
                'muted_color' => '#6b7280',
                'container_width' => 'narrow',
                'section_spacing' => 'compact',
                'radius' => 'none',
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'homepage_modules'],
            ['setting_value' => (string) json_encode([[
                'id' => 'existing-import-module',
                'type' => 'rich_text',
                'layout' => 'single',
                'data_source' => 'latest',
                'enabled' => true,
                'sort_order' => 5,
                'title' => '导入前模块',
                'subtitle' => '',
                'body' => 'Existing import module body.',
                'image_url' => '',
                'link_text' => '',
                'link_url' => '',
                'limit' => 4,
                'custom_html' => '',
            ]], JSON_UNESCAPED_UNICODE)]
        );

        $design = [
            'style_tokens' => [
                'accent' => '#db2777',
                'container' => 'wide',
            ],
            'sections' => [
                [
                    'module_type' => 'feature',
                    'heading' => '新增功能区',
                    'description' => [
                        ['title' => '模块一', 'description' => '说明', 'url' => '/articles'],
                    ],
                    'position' => 50,
                ],
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules.import'), [
                'homepage_design_json' => (string) json_encode($design, JSON_UNESCAPED_UNICODE),
                'import_mode' => 'append',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);
        $style = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_style')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertContains('导入前模块', array_column($modules, 'title'));
        $this->assertContains('新增功能区', array_column($modules, 'title'));
        $this->assertSame('#0f172a', $style['accent_color']);
        $this->assertSame('narrow', $style['container_width']);
    }

    public function test_homepage_design_json_import_preserves_chart_rows_and_module_style(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_import_chart_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-import-chart-admin@example.com',
            'display_name' => 'Site Homepage Import Chart Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $design = [
            'style_tokens' => [
                'accent' => '#2563eb',
                'container' => 'wide',
            ],
            'sections' => [
                [
                    'kind' => 'bar_chart',
                    'heading' => 'Visibility signals',
                    'tagline' => 'Data proof',
                    'content' => [
                        ['label' => 'AI citations', 'value' => '72', 'note' => 'Target share'],
                        ['label' => 'Owned answers', 'value' => '44', 'note' => 'Verified pages'],
                    ],
                    'accent' => '#dc2626',
                    'surface' => '#ffffff',
                    'font_color' => '#111827',
                    'subtle_text' => '#4b5563',
                    'align' => 'center',
                    'order' => 10,
                ],
            ],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.homepage-modules.import'), [
                'homepage_design_json' => (string) json_encode($design, JSON_UNESCAPED_UNICODE),
                'import_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'));

        $modules = json_decode((string) SiteSetting::query()->where('setting_key', 'homepage_modules')->value('setting_value'), true);

        $this->assertIsArray($modules);
        $this->assertCount(1, $modules);
        $this->assertSame('chart_band', $modules[0]['type']);
        $this->assertSame('center', $modules[0]['alignment']);
        $this->assertSame('#dc2626', $modules[0]['accent_color']);
        $this->assertStringContainsString('AI citations|72|Target share', $modules[0]['body']);
        $this->assertStringContainsString('Owned answers|44|Verified pages', $modules[0]['body']);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Visibility signals')
            ->assertSee('geo-home-module--chart_band', false)
            ->assertSee('geo-home-module--align-center', false)
            ->assertSee('AI citations');
    }

    public function test_homepage_design_json_import_rejects_invalid_or_empty_payload(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_import_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-import-invalid-admin@example.com',
            'display_name' => 'Site Homepage Import Invalid Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules.import'), [
                'homepage_design_json' => '{"modules":',
                'import_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_design_json');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules.import'), [
                'homepage_design_json' => '{"style":{"accent":"#2563eb"}}',
                'import_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_design_json');
    }

    public function test_homepage_module_preset_rejects_unknown_preset(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = Admin::query()->create([
            'username' => 'site_homepage_preset_invalid_admin',
            'password' => 'secret-123',
            'email' => 'site-homepage-preset-invalid-admin@example.com',
            'display_name' => 'Site Homepage Preset Invalid Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.homepage-modules.preset'), [
                'homepage_preset' => 'unknown',
                'preset_mode' => 'replace',
            ])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHasErrors('homepage_preset');
    }
}
