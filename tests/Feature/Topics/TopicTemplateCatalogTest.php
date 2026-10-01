<?php

namespace Tests\Feature\Topics;

use App\Models\SiteSetting;
use App\Services\Admin\SiteThemePackageService;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class TopicTemplateCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_layout_survives_install_export_inspection_and_is_available_to_the_selected_site(): void
    {
        Storage::fake('local');
        $files = ThemePackageFixture::files('topic-custom');
        $path = 'resources/views/theme/topic-custom/manifest.json';
        $manifest = json_decode($files[$path], true);
        $manifest['topic'] = TopicTemplateCatalog::declaration();
        $manifest['topic']['layouts'][] = ['id' => 'custom', 'name' => '专属布局', 'view' => 'topics/templates/custom.blade.php'];
        $files[$path] = json_encode($manifest);
        $files['resources/views/theme/topic-custom/topics/templates/custom.blade.php'] = "<section class=\"topic-custom-probe\">{{ \$topic['title'] }}</section>";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, 'topic-custom')), 7);
        $theme = $service->install(7, $report['token'], true);
        app('view')->addLocation($theme['view_root']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'topic-custom']);
        $catalog = app(TopicTemplateCatalog::class);
        $this->assertSame('专属布局', $catalog->options('primary')['custom']);
        $this->assertSame('theme.topic-custom.topics.templates.custom', $catalog->viewForSite('primary', 'custom'));
        $this->assertTrue($catalog->homeEnabled('primary'));
        $topic = app(TopicService::class)->create('primary', ['title' => '自定义布局草稿', 'template_key' => 'custom']);
        $this->assertSame('custom', $topic->draft_payload['template_key']);
        $export = $service->export('topic-custom', 7);
        $download = $service->download(7, $export['token']);
        $reimport = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 7);
        $this->assertSame($export['package']['content_sha256'], $reimport['package']['content_sha256']);
        $again = $service->install(7, $reimport['token'], true);
        $this->assertSame($manifest['topic'], $again['manifest']['topic']);
    }

    public function test_missing_custom_view_is_rejected_during_package_inspection(): void
    {
        Storage::fake('local');
        $files = ThemePackageFixture::files();
        $path = 'resources/views/theme/fixture-theme/manifest.json';
        $manifest = json_decode($files[$path], true);
        $manifest['topic'] = TopicTemplateCatalog::declaration();
        $manifest['topic']['layouts'][] = ['id' => 'missing', 'name' => '缺失布局', 'view' => 'topics/templates/missing.blade.php'];
        $files[$path] = json_encode($manifest);
        $this->expectException(\RuntimeException::class);
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 7);
    }

    public function test_old_package_uses_core_layouts_and_unknown_layout_is_a_field_error(): void
    {
        Storage::fake('local');
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 7);
        $service->install(7, $report['token'], true);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => 'fixture-theme']);
        $catalog = app(TopicTemplateCatalog::class);
        $this->assertSame(TopicTemplateCatalog::CORE, $catalog->options('primary'));
        $this->assertSame('site.topics.templates.default', $catalog->viewForSite('primary', 'deleted-layout'));
        try {
            app(TopicService::class)->create('primary', ['title' => 'Unavailable', 'template_key' => 'custom']);
            $this->fail('Unknown layout accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('template_key', $error->errors());
        }
    }

    public function test_metadata_rejects_paths_unknown_contracts_and_duplicate_ids(): void
    {
        foreach (['path', 'contract', 'duplicate'] as $kind) {
            $metadata = TopicTemplateCatalog::declaration();
            if ($kind === 'path') {
                $metadata['layouts'][0]['view'] = '../../private.blade.php';
            } elseif ($kind === 'contract') {
                $metadata['contract'] = 2;
            } else {
                $metadata['layouts'][] = $metadata['layouts'][0];
            }try {
                TopicTemplateCatalog::validateDeclaration($metadata);
                $this->fail('Invalid declaration accepted');
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }
}
