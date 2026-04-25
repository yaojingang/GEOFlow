<?php

namespace Tests\Feature;

use App\Models\Admin;
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
            ->assertSee('value="'.AdminWeb::basePath().'"', false);
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
}
