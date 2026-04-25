<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLocaleSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_supported_locales_include_new_languages(): void
    {
        $this->assertSame([
            'zh_CN',
            'en',
            'ja',
            'es',
            'ru',
        ], array_keys(AdminWeb::supportedLocales()));
    }

    public function test_admin_locale_switch_accepts_new_languages(): void
    {
        foreach (['ja', 'es', 'ru'] as $locale) {
            $this->from(route('admin.login'))
                ->get(route('admin.locale.switch', ['locale' => $locale]))
                ->assertRedirect(route('admin.login'))
                ->assertSessionHas('locale', $locale);
        }
    }

    public function test_admin_dashboard_renders_new_locale_core_copy(): void
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
            'ja' => 'ダッシュボード',
            'es' => 'Panel',
            'ru' => 'Панель',
        ];

        foreach ($expectations as $locale => $heading) {
            $this->actingAs($admin, 'admin')
                ->withSession(['locale' => $locale])
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee($heading)
                ->assertDontSee('dashboard.heading');
        }
    }
}
