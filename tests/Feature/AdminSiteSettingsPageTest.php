<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SensitiveWord;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
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
}
