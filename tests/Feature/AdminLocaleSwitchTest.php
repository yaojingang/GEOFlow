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
            'zh_TW',
            'en',
            'ja',
            'es',
            'ru',
            'pt_BR',
        ], array_keys(AdminWeb::supportedLocales()));
    }

    public function test_admin_locale_switch_accepts_new_languages(): void
    {
        foreach (['zh_TW', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
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
            'zh_TW' => 'GEO 內容營運後台',
            'ja' => 'ダッシュボード',
            'es' => 'Panel',
            'ru' => 'Панель',
            'pt_BR' => 'Painel',
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

    public function test_admin_dashboard_renders_the_accessible_language_menu(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $admin = Admin::query()->create([
            'username' => 'language_menu_admin',
            'password' => 'secret-123',
            'email' => 'language-menu-admin@example.com',
            'display_name' => 'Language Menu Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['locale' => 'ja'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-popover-button="language"', false)
            ->assertSee('data-popover="language"', false)
            ->assertSee('aria-label="表示言語: 日本語"', false)
            ->assertSee('aria-current="true"', false)
            ->assertDontSee('data-locale-select', false);

        $html = (string) $response->getContent();
        preg_match_all('/class="gf-language-option(?: is-current)?"/', $html, $languageOptions);
        $this->assertCount(7, $languageOptions[0]);
        $this->assertSame(1, substr_count($html, 'class="gf-language-option is-current"'));

        foreach (AdminWeb::supportedLocales() as $locale => $label) {
            $this->assertStringContainsString(
                AdminWeb::routePath('admin.locale.switch', ['locale' => $locale]),
                $html,
            );
            $this->assertStringContainsString('hreflang="'.str_replace('_', '-', $locale).'"', $html);
            $this->assertStringContainsString($label, $html);
        }
    }
}
