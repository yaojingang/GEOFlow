<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_supported_locales_are_chinese_and_english_only(): void
    {
        $this->assertSame([
            'zh_CN',
            'en',
        ], array_keys(AdminWeb::supportedLocales()));
    }

    public function test_admin_locale_switch_accepts_chinese_and_english(): void
    {
        foreach (['zh_CN', 'en'] as $locale) {
            $this->from(route('admin.login'))
                ->get(route('admin.locale.switch', ['locale' => $locale]))
                ->assertRedirect(route('admin.login'))
                ->assertSessionHas('locale', $locale);
        }
    }

    public function test_admin_locale_switch_falls_back_for_removed_languages(): void
    {
        foreach (['ja', 'es', 'ru', 'pt_BR'] as $locale) {
            $this->from(route('admin.login'))
                ->get(route('admin.locale.switch', ['locale' => $locale]))
                ->assertRedirect(route('admin.login'))
                ->assertSessionHas('locale', 'zh_CN');
        }
    }

    public function test_admin_dashboard_renders_chinese_and_english_core_copy(): void
    {
        $admin = Admin::query()->create([
            'username' => 'locale_admin',
            'password' => 'secret-123',
            'email' => 'locale-admin@example.com',
            'display_name' => 'Locale Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $expectations = [
            'zh_CN' => '首页导航',
            'en' => 'Home Navigation',
        ];

        foreach ($expectations as $locale => $heading) {
            $this->actingAs($admin, 'admin')
                ->withSession(['locale' => $locale])
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee($heading)
                ->assertDontSee('admin.dashboard')
                ->assertDontSee('dashboard.heading');
        }
    }
}
