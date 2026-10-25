<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAnalyticsCodeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('analyticsSnippets')]
    public function test_super_admin_can_save_encoded_analytics_and_render_it_in_the_public_head(string $code): void
    {
        $this->login();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode($code),
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
        $this->get(route('admin.site-settings.index'))->assertSee($code)->assertSee('js/admin-analytics-code.js', false);
        $html = $this->get(route('site.home'))->assertOk()->getContent();
        $head = substr($html, 0, strpos($html, '</head>'));
        $this->assertStringContainsString($code, $head);
    }

    public static function analyticsSnippets(): array
    {
        return [
            'external script' => ['<script defer src="https://tongji.liehe.com/script.js" data-website-id="00000000-0000-4000-8000-000000000001"></script>'],
            'multiple scripts and unicode' => ["<!-- 中文统计 📊 -->\n<script>window.analyticsName = '访问统计';</script>\n<script async src=\"https://example.com/analytics.js\"></script>"],
        ];
    }

    public function test_empty_encoded_analytics_clears_the_existing_snippet(): void
    {
        $this->login();
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => '',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '']);
        $this->get(route('site.home'))->assertDontSee('<script>existing()</script>', false);
    }

    public function test_encoded_analytics_takes_precedence_over_the_legacy_field(): void
    {
        $this->login();
        $code = '<script>encoded()</script>';

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode($code),
            'analytics_code' => '<script>legacy()</script>',
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
    }

    public function test_legacy_analytics_submissions_remain_supported(): void
    {
        $this->login();
        $code = '<script>legacy()</script>';

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code' => $code,
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
    }

    public function test_standard_admin_cannot_change_analytics_using_the_encoded_field(): void
    {
        $this->login('admin');
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode('<script>changed()</script>'),
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '<script>existing()</script>']);
    }

    public function test_guest_cannot_save_encoded_analytics(): void
    {
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode('<script>changed()</script>'),
        ]))->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '<script>existing()</script>']);
    }

    #[DataProvider('invalidEncodedSnippets')]
    public function test_invalid_encoded_analytics_is_rejected_without_changing_settings(mixed $value, string $errorKey): void
    {
        $this->login();
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'site_name' => 'Must not save',
            'analytics_code_base64' => $value,
        ]))->assertSessionHasErrors($errorKey);

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '<script>existing()</script>']);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'site_name', 'setting_value' => 'Must not save']);
    }

    public static function invalidEncodedSnippets(): array
    {
        return [
            'invalid base64' => ['%%%invalid%%%', 'analytics_code'],
            'invalid unicode' => [base64_encode("\xff\xfe"), 'analytics_code'],
            'array instead of string' => [['invalid'], 'analytics_code_base64'],
        ];
    }

    public function test_validation_error_retains_the_decoded_draft_and_escapes_it_in_the_admin(): void
    {
        $this->login();
        $this->existingCode();
        $draft = '<script>window.draftAnalytics = "中文";</script></textarea><script>window.adminInjection = true;</script>';

        $this->from(route('admin.site-settings.index'))->post(route('admin.site-settings.update'), $this->payload([
            'site_name' => '',
            'analytics_code_base64' => base64_encode($draft),
        ]))->assertSessionHasErrors('site_name')->assertSessionHasInput('analytics_code', $draft);

        $this->get(route('admin.site-settings.index'))->assertSee($draft)->assertDontSee($draft, false);
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '<script>existing()</script>']);
    }

    private function login(string $role = 'super_admin'): void
    {
        $this->actingAs(Admin::query()->create([
            'username' => 'analytics-editor',
            'password' => 'test-password',
            'role' => $role,
            'status' => 'active',
        ]), 'admin');
    }

    private function existingCode(): void
    {
        SiteSetting::query()->create(['setting_key' => 'analytics_code', 'setting_value' => '<script>existing()</script>']);
    }

    private function payload(array $overrides): array
    {
        return array_replace([
            'site_name' => 'Analytics Test Site',
            'admin_base_path' => AdminWeb::basePath(),
        ], $overrides);
    }
}
