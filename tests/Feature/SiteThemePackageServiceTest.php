<?php

namespace Tests\Feature;

use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemePackageStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class SiteThemePackageServiceTest extends TestCase
{
    private array $sourceDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['geoflow.theme_packages.lock_timeout_milliseconds' => 10]);
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceDirectories as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_install_export_and_reimport_preserve_files_private_metadata_and_idempotency_without_executing_blade(): void
    {
        $files = ThemePackageFixture::files();
        $marker = Storage::disk('local')->path('executed.txt');
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n@php file_put_contents(".var_export($marker, true).", 'unsafe'); @endphp";
        $upload = ThemePackageFixture::archive($files);
        $service = app(SiteThemePackageService::class);
        $inspection = $service->inspect($upload, 7);
        $this->assertNull($inspection['conflict']);
        $this->assertFileDoesNotExist($marker);
        $theme = $service->install(7, $inspection['token'], true);
        $this->assertFalse($theme['already_installed']);
        $this->assertSame('installed', $theme['source']);
        $this->assertSame(['can_redistribute' => false], $theme['manifest']['private_metadata']);
        $this->assertSame('private', $theme['package']['distribution']['visibility']);
        $this->assertFileDoesNotExist($marker);
        $export = $service->export('fixture-theme', 7);
        $this->assertSame($inspection['package']['content_sha256'], $export['package']['content_sha256']);
        $download = $service->download(7, $export['token']);
        $reimport = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 7);
        $this->assertSame('already_installed', $reimport['conflict']['code']);
        $this->assertTrue($service->install(7, $reimport['token'], true)['already_installed']);
        foreach ($files as $logical => $contents) {
            $this->assertSame($contents, file_get_contents(Storage::disk('local')->path('geoflow-site-themes/installed/fixture-theme/'.$logical)));
        }
        $this->assertFileDoesNotExist($marker);
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/staging/*')));
    }

    public function test_download_keeps_its_lease_until_stream_consumption_finishes(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 7);
        $service->install(7, $report['token'], true);
        $export = $service->export('fixture-theme', 7);
        $download = $service->download(7, $export['token']);
        $service->streamDownload(7, $export['token'], function ($stream) use ($service, $download): void {
            $this->travel(61)->minutes();
            $service->pruneExpired();
            $this->assertFileExists($download['path']);
            $this->assertSame($download['bytes'], strlen(stream_get_contents($stream)));
        });
        $service->pruneExpired();
        $this->assertFileDoesNotExist($download['path']);
    }

    public function test_native_theme_exports_without_replication_task_or_activation(): void
    {
        $id = 'fixture-'.strtolower(Str::random(12));
        $files = ThemePackageFixture::files($id);
        $this->createSource($id, $files);
        $service = app(SiteThemePackageService::class);
        $export = $service->export($id, 9);
        $this->assertSame($id, $export['package']['theme']['id']);
        $this->assertSame('private', $export['package']['distribution']['visibility']);
        $download = $service->download(9, $export['token']);
        $inspection = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $this->assertSame('builtin_conflict', $inspection['conflict']['code']);
        $this->expectExceptionMessage(__('admin.theme_packages.error.builtin_conflict'));
        $service->install(9, $inspection['token'], true);
    }

    public function test_optional_and_literal_blade_text_survive_install_export_and_actual_rendering(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] = "Before\n@includeIf('site.missing-fixture-view')\n@verbatim\n@include('site.verbatim-fixture-view')\n@endverbatim\n@@include('site.escaped-fixture-view')\nAfter\n";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files), 9);
        $this->assertSame([], $report['risks']);
        $theme = $service->install(9, $report['token'], true);
        app('view')->addLocation($theme['view_root']);
        $rendered = view('theme.fixture-theme.home')->render();
        $this->assertStringContainsString("@include('site.verbatim-fixture-view')", $rendered);
        $this->assertStringContainsString("@include('site.escaped-fixture-view')", $rendered);
        $this->assertStringNotContainsString('site.missing-fixture-view', $rendered);
        $this->assertStringContainsString('After', $rendered);
        $export = $service->export('fixture-theme', 9);
        $this->assertNotContains('site.missing-fixture-view', $export['package']['requires']['views']);
    }

    public function test_export_does_not_turn_an_existing_optional_view_into_a_required_dependency(): void
    {
        $dependencyId = 'fixture-'.strtolower(Str::random(12));
        $this->createSource($dependencyId, ThemePackageFixture::files($dependencyId));
        $id = 'fixture-'.strtolower(Str::random(12));
        $files = ThemePackageFixture::files($id);
        $files['resources/views/theme/'.$id.'/home.blade.php'] = "@includeIf('theme.$dependencyId.layout')\n";
        $this->createSource($id, $files);
        $service = app(SiteThemePackageService::class);
        $export = $service->export($id, 9);
        $this->assertNotContains('theme.'.$dependencyId.'.layout', $export['package']['requires']['views']);
        unlink(resource_path('views/theme/'.$dependencyId.'/layout.blade.php'));
        $download = $service->download(9, $export['token']);
        $report = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $this->assertSame([], $report['risks']);
    }

    public function test_include_first_keeps_target_fallback_candidates_across_export_and_import(): void
    {
        $dependencyId = 'fixture-'.strtolower(Str::random(12));
        $this->createSource($dependencyId, ThemePackageFixture::files($dependencyId));
        $id = 'fixture-'.strtolower(Str::random(12));
        $files = ThemePackageFixture::files($id);
        $first = 'theme.'.$dependencyId.'.layout';
        $fallback = 'theme.'.$id.'.fallback';
        $files['resources/views/theme/'.$id.'/home.blade.php'] = "@includeFirst(['$first', '$fallback'])\n";
        $files['resources/views/theme/'.$id.'/fallback.blade.php'] = 'Target fallback';
        $this->createSource($id, $files);
        $service = app(SiteThemePackageService::class);
        $export = $service->export($id, 9);
        $this->assertNotContains($first, $export['package']['requires']['views']);
        $this->assertNotContains($fallback, $export['package']['requires']['views']);
        unlink(resource_path('views/theme/'.$dependencyId.'/layout.blade.php'));
        File::deleteDirectory(resource_path('views/theme/'.$id));
        File::deleteDirectory(public_path('themes/'.$id));
        $download = $service->download(9, $export['token']);
        $report = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $theme = $service->install(9, $report['token'], true);
        app('view')->addLocation($theme['view_root']);
        $this->assertSame('Target fallback', trim(view('theme.'.$id.'.home')->render()));

        unset($files['resources/views/theme/'.$id.'/fallback.blade.php']);
        $package = ThemePackageFixture::package($files, $id);
        $package['requires'] = $export['package']['requires'];
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: '.$first.' | '.$fallback]));
        $service->inspect(ThemePackageFixture::archive($files, $package), 9);
    }

    public function test_explicit_requirements_remain_mandatory_for_optional_and_fallback_directives(): void
    {
        foreach (['manifest', 'package'] as $declaration) {
            foreach (["@includeIf('site.missing-fixture-view')", "@includeFirst(['site.missing-fixture-view', 'theme.fixture-theme.layout'])"] as $source) {
                $files = ThemePackageFixture::files();
                $files['resources/views/theme/fixture-theme/home.blade.php'] = $source;
                if ($declaration === 'manifest') {
                    $manifest = json_decode($files['resources/views/theme/fixture-theme/manifest.json'], true);
                    $manifest['requires']['views'] = ['site.missing-fixture-view'];
                    $files['resources/views/theme/fixture-theme/manifest.json'] = json_encode($manifest);
                }
                $package = ThemePackageFixture::package($files);
                if ($declaration === 'package') {
                    $package['requires']['views'] = ['site.missing-fixture-view'];
                }
                try {
                    app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 9);
                    $this->fail('An explicit required view was treated as optional.');
                } catch (RuntimeException $exception) {
                    $this->assertSame(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: site.missing-fixture-view']), $exception->getMessage());
                }
            }
        }
    }

    public function test_nested_private_metadata_reports_and_receipts_remain_readable_and_expire_normally(): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['distribution']['private_metadata'] = array_fill(0, 20000, ['nested' => ['value' => 1]]);
        $this->assertLessThan(1024 * 1024, strlen(json_encode($package)));
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 9);
        $this->assertSame($package, $service->inspection(9, $report['token'])['package']);
        $theme = $service->install(9, $report['token'], true);
        $this->assertSame($package, $theme['package']);
        $this->assertNotNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        $export = $service->export('fixture-theme', 9);
        $download = $service->download(9, $export['token']);
        $reimport = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $this->assertSame($package['distribution'], $reimport['package']['distribution']);
        $this->travel(61)->minutes();
        $service->pruneExpired();
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('geoflow-site-themes/uploads/9/'.$report['token']));
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('geoflow-site-themes/exports/9/'.$export['token']));
        $this->assertNotNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
    }

    public function test_cleanup_tolerates_an_unreadable_owned_directory(): void
    {
        $directory = app(SiteThemePackageStorage::class)->directory('uploads/9');
        chmod($directory, 0000);
        try {
            if (is_readable($directory)) {
                $this->markTestSkipped('The process can read directories with mode 0000.');
            }
            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if (error_reporting() & $severity) {
                    throw new \ErrorException($message, 0, $severity, $file, $line);
                }

                return false;
            });
            try {
                app(SiteThemePackageService::class)->pruneExpired();
                $this->assertDirectoryExists($directory);
            } finally {
                restore_error_handler();
            }
        } finally {
            chmod($directory, 0700);
        }
    }

    public function test_unrepresentable_json_numbers_fail_as_package_validation_and_leave_no_report(): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['private_metadata'] = 'fixture-extreme-exponent';
        $upload = ThemePackageFixture::archive($files, $package);
        $zip = new \ZipArchive;
        $zip->open($upload->getPathname());
        $metadata = str_replace('"fixture-extreme-exponent"', '1e309', $zip->getFromName('package.json'));
        $zip->addFromString('package.json', $metadata);
        $zip->close();
        try {
            app(SiteThemePackageService::class)->inspect($upload, 9);
            $this->fail('An unrepresentable JSON number was stored.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.invalid_package'), $exception->getMessage());
        }
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/uploads/9/*')));
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/staging/*')));
    }

    public function test_json_fraction_types_survive_report_receipt_and_export_roundtrip(): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        $package['distribution']['private_metadata'] = 'fixture-decimal-number';
        $upload = ThemePackageFixture::archive($files, $package);
        $zip = new \ZipArchive;
        $zip->open($upload->getPathname());
        $zip->addFromString('package.json', str_replace('"fixture-decimal-number"', '1.0', $zip->getFromName('package.json')));
        $zip->close();
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect($upload, 9);
        $this->assertSame(1.0, $report['package']['distribution']['private_metadata']);
        $this->assertSame($report['package'], $service->inspection(9, $report['token'])['package']);
        $theme = $service->install(9, $report['token'], true);
        $this->assertSame(1.0, $theme['package']['distribution']['private_metadata']);
        $export = $service->export('fixture-theme', 9);
        $download = $service->download(9, $export['token']);
        $reimport = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $this->assertSame(1.0, $reimport['package']['distribution']['private_metadata']);
    }

    public function test_export_records_contracts_six_page_partition_and_all_manifest_dependency_overrides(): void
    {
        $id = 'fixture-'.strtolower(Str::random(12));
        $dependencyId = 'fixture-'.strtolower(Str::random(12));
        $this->createSource($dependencyId, ThemePackageFixture::files($dependencyId));
        $files = ThemePackageFixture::files($id);
        $files['resources/views/theme/'.$id.'/about.blade.php'] = 'Fixture about';
        $files['resources/views/theme/'.$id.'/home.blade.php'] .= "\n@include('theme.$dependencyId.layout')\n{{ asset('themes/$dependencyId/theme.css') }}\n";
        $manifest = json_decode($files['resources/views/theme/'.$id.'/manifest.json'], true);
        $manifest['dependencies'] = [
            'geoflow' => '^'.config('geoflow.app_version'), 'php' => '>=8.3 <9.0', 'laravel' => '^12.0',
            'views' => ['site.home'], 'assets' => ['themes/'.$dependencyId.'/theme.css'],
        ];
        $manifest['requires'] = ['geoflow' => '>='.config('geoflow.app_version'), 'views' => ['site.partials.seo-head'], 'routes' => ['site.home']];
        $files['resources/views/theme/'.$id.'/manifest.json'] = json_encode($manifest);
        $this->createSource($id, $files);
        $service = app(SiteThemePackageService::class);
        $export = $service->export($id, 9);
        $package = $export['package'];
        $this->assertSame(['site-theme-view-resolver' => 1], $package['exported_with']['contracts']);
        $this->assertSame(['provided' => ['home', 'about'], 'fallback' => ['category', 'article', 'archive-index', 'archive-month']], $package['pages']);
        $this->assertSame('>=8.3 <9.0', $package['requires']['php']);
        $this->assertSame('^12.0', $package['requires']['laravel']);
        $this->assertSame('>='.config('geoflow.app_version'), $package['requires']['geoflow']);
        foreach (['site.home', 'site.partials.seo-head', 'theme.'.$dependencyId.'.layout', 'site.archive-month'] as $view) {
            $this->assertContains($view, $package['requires']['views']);
        }
        $this->assertContains('site.home', $package['requires']['routes']);
        $this->assertContains('themes/'.$dependencyId.'/theme.css', $package['requires']['assets']);
        $this->assertNotContains('public/themes/'.$dependencyId.'/theme.css', array_column($package['files'], 'path'));
        $download = $service->download(9, $export['token']);
        $inspection = $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
        $this->assertSame($package['requires'], $inspection['package']['requires']);
        unlink(public_path('themes/'.$dependencyId.'/theme.css'));
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'assets: themes/'.$dependencyId.'/theme.css']));
        $service->inspect(new UploadedFile($download['path'], 'theme.zip', 'application/zip', null, true), 9);
    }

    public function test_cross_theme_dependencies_cannot_use_an_installed_theme_as_a_runtime_dependency(): void
    {
        $service = app(SiteThemePackageService::class);
        $dependencyId = 'dependency-theme';
        $dependencyFiles = ThemePackageFixture::files($dependencyId);
        $report = $service->inspect(ThemePackageFixture::archive($dependencyFiles, ThemePackageFixture::package($dependencyFiles, $dependencyId)), 9);
        $service->install(9, $report['token'], true);
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n@include('theme.dependency-theme.layout')\n";
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: theme.dependency-theme.layout']));
        $service->inspect(ThemePackageFixture::archive($files), 9);
    }

    public function test_repository_requires_every_receipted_view_and_asset_to_exist_with_the_recorded_size(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $theme = $service->install(8, $report['token'], true);
        $repository = app(InstalledSiteThemeRepository::class);
        foreach ([$theme['views_path'].'/home.blade.php', $theme['assets_path'].'/theme.css'] as $path) {
            $contents = file_get_contents($path);
            unlink($path);
            $this->assertNull($repository->find('fixture-theme'));
            $this->assertSame([], $repository->all());
            file_put_contents($path, $contents);
            $this->assertNotNull($repository->find('fixture-theme'));
            file_put_contents($path, $contents.'changed');
            $this->assertNull($repository->find('fixture-theme'));
            file_put_contents($path, $contents);
        }
    }

    public function test_public_only_theme_directory_conflicts_case_insensitively_during_inspection_and_installation(): void
    {
        $id = 'fixture-'.strtolower(Str::random(12));
        $directory = public_path('themes/'.strtoupper($id));
        $this->sourceDirectories[] = $directory;
        File::ensureDirectoryExists($directory);
        file_put_contents($directory.'/theme.css', 'existing public asset');
        $this->assertDirectoryDoesNotExist(resource_path('views/theme/'.$id));
        $files = ThemePackageFixture::files($id);
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, $id)), 9);
        $this->assertSame('builtin_conflict', $report['conflict']['code']);
        try {
            $service->install(9, $report['token'], true);
            $this->fail('A public-only native theme directory was shadowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.builtin_conflict'), $exception->getMessage());
        }
        $this->assertSame('existing public asset', file_get_contents($directory.'/theme.css'));
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find($id));
    }

    public function test_installation_rechecks_public_theme_collision_created_after_inspection(): void
    {
        $id = 'fixture-'.strtolower(Str::random(12));
        $files = ThemePackageFixture::files($id);
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, $id)), 9);
        $this->assertNull($report['conflict']);
        $directory = public_path('themes/'.$id);
        $this->sourceDirectories[] = $directory;
        File::ensureDirectoryExists($directory);
        $this->expectExceptionMessage(__('admin.theme_packages.error.builtin_conflict'));
        $service->install(9, $report['token'], true);
    }

    public function test_anonymous_components_are_declared_as_core_views_without_executing_them(): void
    {
        $component = 'fixture-'.strtolower(Str::random(12));
        $directory = resource_path('views/components/'.$component);
        $this->sourceDirectories[] = $directory;
        File::ensureDirectoryExists($directory);
        $marker = Storage::disk('local')->path('component-executed');
        file_put_contents($directory.'/card.blade.php', '@php file_put_contents('.var_export($marker, true).", 'unsafe'); @endphp\n<section>{{ \$slot }}</section>");
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n<x-$component.card><x-slot:title>Title</x-slot:title>Body</x-$component.card>\n";
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files), 12);
        $this->assertSame([], $report['risks']);
        $service->install(12, $report['token'], true);
        $export = $service->export('fixture-theme', 12);
        $this->assertContains('components.'.$component.'.card', $export['package']['requires']['views']);
        $this->assertFileDoesNotExist($marker);
    }

    public function test_arbitrary_private_metadata_survives_the_known_display_field_validation(): void
    {
        $files = ThemePackageFixture::files();
        $manifest = json_decode($files['resources/views/theme/fixture-theme/manifest.json'], true);
        $private = ['flags' => [false, null, ['account' => 'synthetic']], 'enabled' => false];
        $manifest['private_metadata'] = $private;
        $manifest['distribution']['private_metadata'] = $private;
        $manifest['distribution']['publish_to_repository'] = false;
        $files['resources/views/theme/fixture-theme/manifest.json'] = json_encode($manifest);
        $package = ThemePackageFixture::package($files);
        $package['distribution'] = $manifest['distribution'];
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, $package), 12);
        $theme = $service->install(12, $report['token'], true);
        $this->assertSame($private, $theme['manifest']['private_metadata']);
        $export = $service->export('fixture-theme', 12);
        $this->assertSame($private, $export['package']['distribution']['private_metadata']);
        $this->assertFalse($export['package']['distribution']['publish_to_repository']);
    }

    public function test_legacy_invalid_manifest_display_data_is_hidden_without_breaking_catalog_reads(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 12);
        $theme = $service->install(12, $report['token'], true);
        $manifestPath = $theme['views_path'].'/manifest.json';
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $manifest['description'] = ['invalid' => 'description'];
        $contents = json_encode($manifest);
        file_put_contents($manifestPath, $contents);
        $receiptPath = Storage::disk('local')->path('geoflow-site-themes/installed/fixture-theme/installation.json');
        $receipt = json_decode(file_get_contents($receiptPath), true);
        foreach ($receipt['package']['files'] as &$file) {
            if (str_ends_with($file['path'], '/manifest.json')) {
                $file['bytes'] = strlen($contents);
                $file['sha256'] = hash('sha256', $contents);
            }
        }
        unset($file);
        $receipt['package']['content_sha256'] = app(SiteThemePackageGuard::class)->contentHash($receipt['package']['files']);
        $receipt['content_sha256'] = $receipt['package']['content_sha256'];
        file_put_contents($receiptPath, json_encode($receipt));
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        $this->assertSame([], app(InstalledSiteThemeRepository::class)->all());
    }

    public function test_different_content_with_same_id_is_rejected_without_overwriting_installation(): void
    {
        $files = ThemePackageFixture::files();
        $service = app(SiteThemePackageService::class);
        $inspection = $service->inspect(ThemePackageFixture::archive($files), 3);
        $installed = $service->install(3, $inspection['token'], true);
        $files['public/themes/fixture-theme/theme.css'] = 'body{color:red}';
        $different = $service->inspect(ThemePackageFixture::archive($files), 3);
        $this->assertSame('theme_conflict', $different['conflict']['code']);
        try {
            $service->install(3, $different['token'], true);
            $this->fail('Conflicting package was installed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.theme_conflict'), $exception->getMessage());
        }
        $this->assertSame($installed['content_sha256'], app(InstalledSiteThemeRepository::class)->find('fixture-theme')['content_sha256']);
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/staging/*')));
    }

    public function test_confirmation_is_required_and_does_not_consume_the_inspection(): void
    {
        $service = app(SiteThemePackageService::class);
        $inspection = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 4);
        try {
            $service->install(4, $inspection['token'], false);
            $this->fail('Unconfirmed package was installed.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.trusted_source_required'), $exception->getMessage());
        }
        $this->assertFalse($service->install(4, $inspection['token'], true)['already_installed']);
    }

    public function test_tokens_are_admin_bound_expire_and_archive_tampering_is_rejected(): void
    {
        $service = app(SiteThemePackageService::class);
        $inspection = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 4);
        foreach ([fn () => $service->inspection(5, $inspection['token']), fn () => $service->install(5, $inspection['token'], true), fn () => $service->inspection(4, '../invalid')] as $operation) {
            $this->assertInvalidToken($operation);
        }
        $archive = Storage::disk('local')->path('geoflow-site-themes/uploads/4/'.$inspection['token'].'/theme.zip');
        file_put_contents($archive, 'tampered', FILE_APPEND);
        $this->assertInvalidToken(fn () => $service->install(4, $inspection['token'], true));
        $fresh = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 4);
        $this->travel(61)->minutes();
        $this->assertInvalidToken(fn () => $service->inspection(4, $fresh['token']));
        $service->pruneExpired();
        $this->assertDirectoryDoesNotExist(dirname($archive));
    }

    public function test_asset_repository_returns_only_receipted_untampered_public_assets(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $theme = $service->install(8, $report['token'], true);
        $repository = app(InstalledSiteThemeRepository::class);
        $asset = $repository->asset('fixture-theme', 'theme.css');
        $this->assertSame('text/css', $asset['mime']);
        $this->assertNull($repository->asset('fixture-theme', '../installation.json'));
        $this->assertNull($repository->asset('fixture-theme', 'home.blade.php'));
        $this->assertNull($repository->asset('fixture-theme', 'manifest.json'));
        file_put_contents($theme['assets_path'].'/extra.js', 'alert(1)');
        $this->assertNull($repository->asset('fixture-theme', 'extra.js'));
        file_put_contents($asset['path'], 'tampered');
        $this->assertNull($repository->asset('fixture-theme', 'theme.css'));
    }

    public function test_receipt_manifest_tampering_hides_the_installed_descriptor(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $theme = $service->install(8, $report['token'], true);
        file_put_contents($theme['views_path'].'/manifest.json', '{"name":"changed"}');
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
    }

    public function test_expired_staging_cleanup_unlinks_a_child_symlink_and_preserves_its_target(): void
    {
        $storage = app(SiteThemePackageStorage::class);
        $stage = 'staging/'.str_repeat('a', 40);
        $directory = $storage->exclusiveDirectory($stage);
        $outside = Storage::disk('local')->path('outside');
        mkdir($outside);
        file_put_contents($outside.'/keep.txt', 'keep');
        symlink($outside, $directory.'/linked');
        $this->travel(61)->minutes();
        app(SiteThemePackageService::class)->pruneExpired();
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame('keep', file_get_contents($outside.'/keep.txt'));
    }

    public function test_repository_empty_reads_do_not_create_directories(): void
    {
        $repository = app(InstalledSiteThemeRepository::class);
        $this->assertSame([], $repository->all());
        $this->assertNull($repository->find('unknown-theme'));
        $this->assertDirectoryDoesNotExist(Storage::disk('local')->path('geoflow-site-themes'));
    }

    public function test_token_lock_blocks_install_and_cleanup_skips_in_flight_directory(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $storage = app(SiteThemePackageStorage::class);
        $lock = fopen($storage->path('locks/'.hash('sha256', 'token:'.$report['token']).'.lock'), 'c+b');
        flock($lock, LOCK_EX);
        try {
            try {
                $service->install(8, $report['token'], true);
                $this->fail('Concurrent operation acquired a held lock.');
            } catch (RuntimeException $exception) {
                $this->assertSame(__('admin.theme_packages.error.busy'), $exception->getMessage());
            }
            $this->travel(61)->minutes();
            $service->pruneExpired();
            $this->assertDirectoryExists($storage->path('uploads/8/'.$report['token']));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $service->pruneExpired();
        $this->assertDirectoryDoesNotExist($storage->path('uploads/8/'.$report['token']));
    }

    public function test_theme_lock_prevents_racing_installs_and_failure_cleans_stage(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $storage = app(SiteThemePackageStorage::class);
        $path = $storage->path('locks/'.hash('sha256', 'theme:fixture-theme').'.lock');
        $lock = fopen($path, 'c+b');
        flock($lock, LOCK_EX);
        try {
            try {
                $service->install(8, $report['token'], true);
                $this->fail('Concurrent theme install acquired a held lock.');
            } catch (RuntimeException $exception) {
                $this->assertSame(__('admin.theme_packages.error.busy'), $exception->getMessage());
            }
            $this->assertSame([], glob($storage->path('staging').'/*'));
            $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertFalse($service->install(8, $report['token'], true)['already_installed']);
    }

    public function test_source_symlink_is_rejected_and_exclusive_export_is_cleaned(): void
    {
        $id = 'fixture-'.strtolower(Str::random(12));
        $this->createSource($id, ThemePackageFixture::files($id));
        $target = Storage::disk('local')->path('secret.txt');
        file_put_contents($target, 'secret');
        symlink($target, resource_path('views/theme/'.$id.'/linked.txt'));
        try {
            app(SiteThemePackageService::class)->export($id, 2);
            $this->fail('Symlink source was exported.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.invalid_path'), $exception->getMessage());
        }
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/exports/2/*')));
        $this->assertSame('secret', file_get_contents($target));
    }

    public function test_installed_symlink_never_exposes_an_external_file(): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), 8);
        $theme = $service->install(8, $report['token'], true);
        $target = Storage::disk('local')->path('external.css');
        file_put_contents($target, 'body{color:#123}');
        unlink($theme['assets_path'].'/theme.css');
        symlink($target, $theme['assets_path'].'/theme.css');
        $this->assertNull(app(InstalledSiteThemeRepository::class)->asset('fixture-theme', 'theme.css'));
    }

    private function createSource(string $id, array $files): void
    {
        $this->sourceDirectories[] = resource_path('views/theme/'.$id);
        $this->sourceDirectories[] = public_path('themes/'.$id);
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname(base_path($path)));
            file_put_contents(base_path($path), $contents);
        }
    }

    private function assertInvalidToken(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid token was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.invalid_token'), $exception->getMessage());
        }
    }
}
