<?php

namespace Tests\Feature;

use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\InstalledSiteThemeRepository;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class SiteThemeCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[DataProvider('compatibleVersions')]
    public function test_default_package_installs_across_the_same_major_version(string $exporter, string $target): void
    {
        config(['geoflow.app_version' => $exporter]);
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $upload = ThemePackageFixture::archive($files, $package);
        config(['geoflow.app_version' => $target]);
        $service = app(SiteThemePackageService::class);

        $report = $service->inspect($upload, 7);
        $installed = $service->install(7, $report['token'], true);

        $this->assertSame('3.*', $report['package']['requires']['geoflow']);
        $this->assertSame($exporter, $report['package']['exported_with']['geoflow']);
        $this->assertSame('fixture-theme', $installed['id']);
    }

    public static function compatibleVersions(): array
    {
        return [
            'patch update' => ['3.1.0', '3.1.9'],
            'minor update' => ['3.1.0', '3.3.0'],
            'older minor' => ['3.3.2', '3.1.0'],
            'preview update' => ['3.2.0-beta.1', '3.2.0-beta.2'],
            'preview to stable' => ['3.2.0-beta.2', '3.3.0'],
            'version prefix' => ['v3.1.0', 'v3.3.7'],
        ];
    }

    #[DataProvider('compatibleVersions')]
    public function test_legacy_default_lock_is_relaxed_without_rewriting_the_package(string $exporter, string $target): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['exported_with']['geoflow'] = $exporter;
        $package['requires']['geoflow'] = $exporter;
        $package['exported_with']['contracts'] = $package['requires']['contracts'] = SiteThemePackageGuard::LEGACY_CONTRACTS;
        $package['pages'] = app(SiteThemePackageGuard::class)->pages($package['files'], 'fixture-theme', includeTopics: false);
        config(['geoflow.app_version' => $target]);
        $service = app(SiteThemePackageService::class);

        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 7);
        $installed = $service->install(7, $report['token'], true);
        $export = $service->export('fixture-theme', 7);

        $this->assertSame($package, $installed['package']);
        $this->assertSame('3.*', $report['requirements']['geoflow']);
        $this->assertSame('3.*', $export['package']['requires']['geoflow']);
        $this->assertSame($package['content_sha256'], $export['package']['content_sha256']);
    }

    #[DataProvider('explicitRequirements')]
    public function test_explicit_version_restrictions_remain_enforced(string $source, string $constraint): void
    {
        $files = ThemePackageFixture::files();
        if ($source !== 'package') {
            $path = 'resources/views/theme/fixture-theme/manifest.json';
            $manifest = json_decode($files[$path], true);
            $manifest[$source]['geoflow'] = $constraint;
            $files[$path] = json_encode($manifest, JSON_THROW_ON_ERROR);
        }
        $package = ThemePackageFixture::package($files);
        $package['exported_with']['geoflow'] = '3.3.0';
        $package['requires']['geoflow'] = $source === 'package' ? $constraint : '3.3.0';
        config(['geoflow.app_version' => '3.1.0']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('admin.theme_packages.error.incompatible_geoflow', ['required' => $constraint, 'current' => '3.1.0']));

        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 7);
    }

    public static function explicitRequirements(): array
    {
        return [
            'manifest minimum' => ['requires', '>=3.2.0 <4.0.0'],
            'manifest exact' => ['requires', '3.3.0'],
            'manifest legacy dependency' => ['dependencies', '3.3.0'],
            'package minimum' => ['package', '>=3.2.0 <4.0.0'],
            'package intentional exact' => ['package', '=3.3.0'],
            'package exact differs from exporter' => ['package', '3.2.0'],
        ];
    }

    public function test_install_rechecks_major_version_after_inspection(): void
    {
        config(['geoflow.app_version' => '3.1.0']);
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['requires']['geoflow'] = '3.1.0';
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 7);
        config(['geoflow.app_version' => '4.0.0']);

        try {
            $service->install(7, $report['token'], true);
            $this->fail('A legacy 3.x package was installed on 4.x.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.incompatible_geoflow', ['required' => '3.*', 'current' => '4.0.0']), $exception->getMessage());
        }

        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        $this->assertSame([], Storage::disk('local')->allFiles('geoflow-site-themes/staging'));
    }

    public function test_legacy_version_relaxation_keeps_missing_dependency_checks(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n@include('site.missing-compatibility-view')";
        $package = ThemePackageFixture::package($files);
        $package['exported_with']['geoflow'] = $package['requires']['geoflow'] = '3.1.0';
        config(['geoflow.app_version' => '3.3.0']);

        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: site.missing-compatibility-view']));

        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 7);
    }

    public function test_old_automatic_topic_requirements_are_removed_from_an_ordinary_reexport(): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['exported_with']['geoflow'] = $package['requires']['geoflow'] = '3.2.0-beta.1';
        $package['requires']['contracts'] = SiteThemePackageGuard::CONTRACTS;
        $package['requires']['views'] = ['site.topics.index', 'site.topics.show'];
        config(['geoflow.app_version' => '3.3.0']);
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 7);
        $service->install(7, $report['token'], true);

        $export = $service->export('fixture-theme', 7);

        $this->assertSame(SiteThemePackageGuard::LEGACY_CONTRACTS, $export['package']['exported_with']['contracts']);
        $this->assertSame(SiteThemePackageGuard::LEGACY_CONTRACTS, $export['package']['requires']['contracts']);
        $this->assertNotContains('topics-index', $export['package']['pages']['fallback']);
        $this->assertNotContains('site.topics.index', $export['package']['requires']['views']);
        $this->assertNotContains('site.topics.show', $export['package']['requires']['views']);
        $download = $service->download(7, $export['token']);
        $reimport = $service->inspect(new UploadedFile($download['path'], 'theme.zip', null, null, true), 7);
        $this->assertSame($export['package'], $reimport['package']);
    }

    public function test_actual_topic_reference_keeps_the_topic_contract_on_export(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n<a href=\"{{ route('site.topics.index') }}\">Topics</a>";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files), 7);
        $service->install(7, $report['token'], true);

        $export = $service->export('fixture-theme', 7);

        $this->assertSame(SiteThemePackageGuard::CONTRACTS, $export['package']['exported_with']['contracts']);
        $this->assertSame(SiteThemePackageGuard::CONTRACTS, $export['package']['requires']['contracts']);
        $this->assertContains('topics-index', $export['package']['pages']['fallback']);
        $this->assertContains('site.topics.index', $export['package']['requires']['routes']);
    }

    public function test_manifest_base_contract_does_not_erase_an_inferred_topic_requirement(): void
    {
        $files = ThemePackageFixture::files();
        $path = 'resources/views/theme/fixture-theme/manifest.json';
        $manifest = json_decode($files[$path], true);
        $manifest['requires']['contracts'] = SiteThemePackageGuard::LEGACY_CONTRACTS;
        $files[$path] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n{{ route('site.topics.index') }}";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files), 7);
        $service->install(7, $report['token'], true);

        $export = $service->export('fixture-theme', 7);

        $this->assertSame(SiteThemePackageGuard::CONTRACTS, $export['package']['requires']['contracts']);
    }

    public function test_explicit_minimum_version_survives_legacy_install_and_reexport(): void
    {
        $files = ThemePackageFixture::files();
        $path = 'resources/views/theme/fixture-theme/manifest.json';
        $manifest = json_decode($files[$path], true);
        $manifest['requires']['geoflow'] = '>=3.1.5 <4.0.0';
        $files[$path] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $package = ThemePackageFixture::package($files);
        $package['exported_with']['geoflow'] = $package['requires']['geoflow'] = '3.1.9';
        config(['geoflow.app_version' => '3.3.0']);
        $service = app(SiteThemePackageService::class);

        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 7);
        $service->install(7, $report['token'], true);
        $export = $service->export('fixture-theme', 7);

        $this->assertSame('>=3.1.5 <4.0.0', $report['requirements']['geoflow']);
        $this->assertSame('>=3.1.5 <4.0.0', $export['package']['requires']['geoflow']);
    }

    public function test_an_image_folder_named_topics_keeps_the_basic_package_format(): void
    {
        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/images/topics/cover.png'] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6bS8AAAAASUVORK5CYII=');
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n<img src=\"{{ asset('themes/fixture-theme/images/topics/cover.png') }}\">";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files), 7);
        $service->install(7, $report['token'], true);

        $export = $service->export('fixture-theme', 7);

        $this->assertSame(SiteThemePackageGuard::LEGACY_CONTRACTS, $export['package']['exported_with']['contracts']);
        $this->assertContains('themes/fixture-theme/images/topics/cover.png', $export['package']['requires']['assets']);
    }
}
