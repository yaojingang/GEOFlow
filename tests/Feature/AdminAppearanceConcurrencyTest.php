<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Services\Site\SiteAppearanceService;
use App\Support\AdminWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class AdminAppearanceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_retains_the_revision_of_its_displayed_values_when_another_writer_saves_during_render(): void
    {
        $admin = Admin::query()->create(['username' => 'appearance-editor', 'password' => 'test-password', 'role' => 'admin', 'status' => 'active']);
        SiteSetting::query()->create(['setting_key' => 'site_name', 'setting_value' => 'Original name']);
        $appearance = app(SiteAppearanceService::class);
        $originalRevision = $appearance->hash($appearance->settings());
        View::composer('admin.site-settings.index', static function () use ($appearance): void {
            $appearance->saveValidated(['site_name' => 'Concurrent name']);
        });
        $response = $this->actingAs($admin, 'admin')->get(route('admin.site-settings.index'))->assertOk();
        $this->assertSame('Original name', $response->viewData('settings')['site_name']);
        $this->assertSame($originalRevision, $response->viewData('appearanceRevision'));
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $revisions = (new \DOMXPath($dom))->query('//input[@name="appearance_revision"]');
        $this->assertGreaterThan(0, $revisions->length);
        foreach ($revisions as $revision) {
            $this->assertSame($originalRevision, $revision->getAttribute('value'));
        }
        $this->post(route('admin.site-settings.update'), [
            'site_name' => 'Original name', 'admin_base_path' => AdminWeb::basePath(), 'appearance_revision' => $originalRevision,
        ])->assertConflict()->assertSee('外观配置已更新');

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'site_name', 'setting_value' => 'Concurrent name']);
    }

    public function test_homepage_editor_uses_the_same_snapshot_contract(): void
    {
        $admin = Admin::query()->create(['username' => 'homepage-editor', 'password' => 'test-password', 'role' => 'admin', 'status' => 'active']);
        SiteSetting::query()->create(['setting_key' => 'homepage_style', 'setting_value' => '{}']);
        $appearance = app(SiteAppearanceService::class);
        $originalRevision = $appearance->hash($appearance->settings());
        View::composer('admin.site-settings.index', static function () use ($appearance): void {
            $appearance->saveValidated(['site_name' => 'Concurrent homepage name']);
        });
        $response = $this->actingAs($admin, 'admin')->get(route('admin.site-settings.homepage-modules.edit'))->assertOk();
        $this->assertSame($originalRevision, $response->viewData('appearanceRevision'));
        $this->post(route('admin.site-settings.homepage-modules'), [
            'homepage_style' => [], 'homepage_modules' => [], 'appearance_revision' => $originalRevision,
        ])->assertConflict();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'site_name', 'setting_value' => 'Concurrent homepage name']);
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'homepage_style', 'setting_value' => '{}']);
    }
}
