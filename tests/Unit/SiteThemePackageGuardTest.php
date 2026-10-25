<?php

namespace Tests\Unit;

use App\Services\Admin\SiteThemePackageGuard;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\SiteThemePackageStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\DynamicComponent;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;
use ZipArchive;

class SiteThemePackageGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    #[DataProvider('unsafePaths')]
    public function test_package_rejects_unsafe_or_unapproved_logical_files(string $path): void
    {
        $this->expectException(RuntimeException::class);
        app(SiteThemePackageGuard::class)->filePath($path, 'fixture-theme');
    }

    public static function unsafePaths(): array
    {
        return [
            'traversal' => ['public/themes/fixture-theme/../../escape.css'],
            'absolute' => ['/public/themes/fixture-theme/a.css'],
            'backslash' => ['public/themes/fixture-theme/a\\b.css'],
            'control' => ["public/themes/fixture-theme/a\n.css"],
            'drive' => ['C:/public/themes/fixture-theme/a.css'],
            'unknown root' => ['storage/themes/fixture-theme/a.css'],
            'other theme' => ['public/themes/other-theme/a.css'],
            'plain php' => ['resources/views/theme/fixture-theme/run.php'],
            'public php' => ['public/themes/fixture-theme/home.blade.php'],
            'env' => ['resources/views/theme/fixture-theme/.env'],
            'shell' => ['public/themes/fixture-theme/install.sh'],
            'htaccess' => ['public/themes/fixture-theme/.htaccess'],
            'executable extension' => ['public/themes/fixture-theme/install.cgi'],
            'unapproved asset' => ['public/themes/fixture-theme/payload.zip'],
            'hidden directory' => ['public/themes/fixture-theme/.git/a.css'],
            'view assets outside asset dir' => ['resources/views/theme/fixture-theme/theme.css'],
            'trailing windows dot' => ['public/themes/fixture-theme/assets./theme.css'],
        ];
    }

    public function test_approved_extensions_cover_template_source_and_bundled_assets(): void
    {
        $guard = app(SiteThemePackageGuard::class);
        foreach (array_keys(SiteThemePackageGuard::ASSET_MIMES) as $extension) {
            $guard->filePath('public/themes/fixture-theme/file.'.$extension, 'fixture-theme');
            $guard->filePath('resources/views/theme/fixture-theme/assets/file.'.$extension, 'fixture-theme');
        }
        foreach (['home.blade.php', 'manifest.json', 'README.md', 'notes.txt'] as $file) {
            $guard->filePath('resources/views/theme/fixture-theme/'.$file, 'fixture-theme');
        }
        $this->addToAssertionCount(1);
    }

    #[DataProvider('maliciousArchiveCases')]
    public function test_malicious_archives_are_rejected_and_do_not_leave_extracted_data(string $case): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files);
        if ($case === 'extra') {
            $files['public/themes/fixture-theme/unlisted.css'] = 'unlisted';
        } elseif ($case === 'missing') {
            unset($files['public/themes/fixture-theme/theme.css']);
        } elseif ($case === 'hash') {
            $files['public/themes/fixture-theme/theme.css'] = 'changed';
        } elseif ($case === 'case') {
            $files['public/themes/fixture-theme/THEME.css'] = 'different case';
            $package = ThemePackageFixture::package($files);
        } elseif ($case === 'another-theme') {
            $files['public/themes/other-theme/theme.css'] = 'other';
        } elseif ($case === 'traversal') {
            $files['../../outside.txt'] = 'escape';
        } elseif ($case === 'unknown') {
            $files['install.php'] = '<?php exit;';
        } elseif ($case === 'content-hash') {
            $package['content_sha256'] = str_repeat('0', 64);
        } elseif ($case === 'format-version') {
            $package['format_version'] = 99;
        } elseif ($case === 'manifest-id') {
            $files['resources/views/theme/fixture-theme/manifest.json'] = '{"id":"another-theme"}';
            $package = ThemePackageFixture::package($files);
        }
        $upload = ThemePackageFixture::archive($files, $package);
        if (in_array($case, ['symlink', 'fifo', 'encrypted', 'directory-collision'], true)) {
            $zip = new ZipArchive;
            $zip->open($upload->getPathname());
            if ($case === 'encrypted') {
                $zip->setEncryptionName('public/themes/fixture-theme/theme.css', ZipArchive::EM_AES_256, 'password');
            } elseif ($case === 'directory-collision') {
                $zip->addEmptyDir('public/themes/fixture-theme/theme.css');
            } else {
                $zip->setExternalAttributesName('public/themes/fixture-theme/theme.css', ZipArchive::OPSYS_UNIX, ($case === 'symlink' ? 0120777 : 0010644) << 16);
            }
            $zip->close();
        }
        try {
            app(SiteThemePackageService::class)->inspect($upload, 11);
            $this->fail('Malicious archive was accepted: '.$case);
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/staging/*')));
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/uploads/11/*')));
    }

    public static function maliciousArchiveCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['extra', 'missing', 'hash', 'case', 'another-theme', 'traversal', 'unknown', 'content-hash', 'format-version', 'manifest-id', 'symlink', 'fifo', 'encrypted', 'directory-collision']);
    }

    #[DataProvider('sizeLimits')]
    public function test_archive_file_count_individual_and_total_limits_are_enforced(string $key, int $limit, string $error): void
    {
        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/large.js'] = str_repeat('x', 10000);
        $upload = ThemePackageFixture::archive($files);
        config(['geoflow.theme_packages.'.$key => $limit]);
        $this->expectExceptionMessage(__('admin.theme_packages.error.'.$error));
        app(SiteThemePackageService::class)->inspect($upload, 12);
    }

    public static function sizeLimits(): array
    {
        return [
            'archive' => ['max_archive_bytes', 10, 'archive_too_large'],
            'file count' => ['max_files', 2, 'too_many_files'],
            'file bytes' => ['max_file_bytes', 1000, 'file_too_large'],
            'total bytes' => ['max_total_bytes', 1000, 'total_too_large'],
        ];
    }

    public function test_declared_sizes_cannot_hide_actual_archive_contents(): void
    {
        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/large.js'] = str_repeat('a', 50000);
        $package = ThemePackageFixture::package($files);
        $package['files'][array_key_last($package['files'])]['bytes'] = 1;
        $package['content_sha256'] = app(SiteThemePackageGuard::class)->contentHash($package['files']);
        $upload = ThemePackageFixture::archive($files, $package);
        config(['geoflow.theme_packages.max_file_bytes' => 1024]);
        $this->expectExceptionMessage(__('admin.theme_packages.error.file_too_large'));
        app(SiteThemePackageService::class)->inspect($upload, 12);
    }

    public function test_zip_headers_cannot_understate_the_actual_decompressed_stream(): void
    {
        $files = ThemePackageFixture::files();
        $logical = 'public/themes/fixture-theme/large.js';
        $files[$logical] = str_repeat('payload', 10000);
        $package = ThemePackageFixture::package($files);
        $package['files'][array_key_last($package['files'])]['bytes'] = 1;
        $package['content_sha256'] = app(SiteThemePackageGuard::class)->contentHash($package['files']);
        $upload = ThemePackageFixture::archive($files, $package);
        $bytes = file_get_contents($upload->getPathname());
        foreach (["PK\x03\x04" => [30, 22], "PK\x01\x02" => [46, 24]] as $signature => [$nameOffset, $sizeOffset]) {
            $offset = 0;
            while (($offset = strpos($bytes, $signature, $offset)) !== false) {
                if (substr($bytes, $offset + $nameOffset, strlen($logical)) === $logical) {
                    $bytes = substr_replace($bytes, pack('V', 1), $offset + $sizeOffset, 4);
                    break;
                }
                $offset += 4;
            }
        }
        file_put_contents($upload->getPathname(), $bytes);
        config(['geoflow.theme_packages.max_file_bytes' => 1024]);
        try {
            app(SiteThemePackageService::class)->inspect($upload, 12);
            $this->fail('ZIP headers hid an oversized decompressed stream.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([], glob(Storage::disk('local')->path('geoflow-site-themes/staging/*')));
    }

    public function test_duplicate_zip_entries_are_rejected_by_index_even_when_names_are_identical(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/HOME.blade.php'] = 'duplicate';
        $upload = ThemePackageFixture::archive($files);
        $bytes = file_get_contents($upload->getPathname());
        file_put_contents($upload->getPathname(), str_replace('HOME.blade.php', 'home.blade.php', $bytes));
        $this->expectException(RuntimeException::class);
        app(SiteThemePackageService::class)->inspect($upload, 12);
    }

    public function test_implicit_directory_case_collisions_are_rejected(): void
    {
        $files = ThemePackageFixture::files();
        $files['public/themes/fixture-theme/Fonts/one.woff'] = 'font one';
        $files['public/themes/fixture-theme/fonts/two.woff'] = 'font two';
        $this->expectExceptionMessage(__('admin.theme_packages.error.duplicate_path'));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
    }

    public function test_missing_static_dependency_fails_and_base_template_metadata_is_only_provenance(): void
    {
        $files = ThemePackageFixture::files();
        $manifest = json_decode($files['resources/views/theme/fixture-theme/manifest.json'], true);
        $manifest['base_template_id'] = 'missing-source-theme';
        $files['resources/views/theme/fixture-theme/manifest.json'] = json_encode($manifest);
        $service = app(SiteThemePackageService::class);
        $this->assertNull($service->inspect(ThemePackageFixture::archive($files), 11)['conflict']);
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "@include('site.missing-fixture-view')";
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: site.missing-fixture-view']));
        $service->inspect(ThemePackageFixture::archive($files), 11);
    }

    public function test_dynamic_dependency_requires_explicit_declaration_while_php_and_loop_variables_are_allowed(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= '@php $items = []; @endphp @foreach($items as $item){{ $item }}@endforeach @include($partial)';
        $service = app(SiteThemePackageService::class);
        try {
            $service->inspect(ThemePackageFixture::archive($files), 11);
            $this->fail('Undeclared dynamic view was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.dynamic_dependency', ['dependency' => 'views', 'path' => 'resources/views/theme/fixture-theme/home.blade.php']), $exception->getMessage());
        }
        $package = ThemePackageFixture::package($files);
        $package['requires']['views'] = ['theme.fixture-theme.layout'];
        $inspection = $service->inspect(ThemePackageFixture::archive($files, $package), 11);
        $this->assertCount(1, $inspection['risks']);
    }

    public function test_route_static_name_with_dynamic_parameters_is_resolved_as_static(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "{{ route('site.home', ['page' => \$page]) }}";
        $inspection = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 11);
        $this->assertSame([], $inspection['risks']);
    }

    public function test_incompatible_framework_or_unknown_contract_is_rejected(): void
    {
        $files = ThemePackageFixture::files();
        foreach ([['laravel' => '999.*'], ['contracts' => ['site-theme-view-resolver' => 2]], ['geoflow' => '<1.0.0']] as $mismatch) {
            $package = ThemePackageFixture::package($files);
            $package['requires'] = array_replace($package['requires'], $mismatch);
            try {
                app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 11);
                $this->fail('Incompatible package was accepted.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidPagePartitions')]
    public function test_page_partition_must_cover_exactly_the_actual_six_page_contract(array $pages): void
    {
        $files = ThemePackageFixture::files();
        $package = ThemePackageFixture::package($files, overrides: ['pages' => $pages]);
        $this->expectExceptionMessage(__('admin.theme_packages.error.invalid_package'));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 12);
    }

    public static function invalidPagePartitions(): array
    {
        return [
            'legacy flat list' => [['home']],
            'missing page' => [['provided' => ['home'], 'fallback' => ['category', 'article', 'about', 'archive-index']]],
            'duplicate page' => [['provided' => ['home', 'home'], 'fallback' => ['category', 'article', 'about', 'archive-index', 'archive-month']]],
            'overlap' => [['provided' => ['home'], 'fallback' => ['home', 'category', 'article', 'about', 'archive-index', 'archive-month']]],
            'unknown page' => [['provided' => ['home', 'contact'], 'fallback' => ['category', 'article', 'about', 'archive-index', 'archive-month']]],
            'incorrect provided' => [['provided' => ['home', 'about'], 'fallback' => ['category', 'article', 'archive-index', 'archive-month']]],
            'incorrect fallback' => [['provided' => [], 'fallback' => ['home', 'category', 'article', 'about', 'archive-index', 'archive-month']]],
        ];
    }

    public function test_exporter_contract_metadata_is_required_and_validated(): void
    {
        $files = ThemePackageFixture::files();
        foreach ([null, ['site-theme-view-resolver' => 2], ['unknown-contract' => 1]] as $contracts) {
            $package = ThemePackageFixture::package($files);
            $package['exported_with']['contracts'] = $contracts;
            try {
                app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 12);
                $this->fail('Invalid exporter contract was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame(__('admin.theme_packages.error.incompatible', ['component' => 'exported_with.contracts']), $exception->getMessage());
            }
        }
    }

    #[DataProvider('standardBladeDependencies')]
    public function test_standard_blade_entries_reject_missing_literal_view_dependencies(string $directive): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n".$directive."\n";
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: site.missing-fixture-view']));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
    }

    public static function standardBladeDependencies(): array
    {
        return [
            'include isolated' => ["@includeIsolated('site.missing-fixture-view')"],
            'include when' => ["@includeWhen(true, 'site.missing-fixture-view')"],
            'include unless' => ["@includeUnless(false, 'site.missing-fixture-view')"],
            'include first' => ["@includeFirst(['site.missing-fixture-view'])"],
            'each' => ["@each('site.missing-fixture-view', [], 'item')"],
            'each empty view' => ["@each('theme.fixture-theme.layout', [], 'item', 'site.missing-fixture-view')"],
            'component' => ["@component('site.missing-fixture-view')@endcomponent"],
            'nested condition' => ["@includeWhen(in_array('a', ['a', 'b']), 'site.missing-fixture-view')"],
        ];
    }

    #[DataProvider('nonExecutingBladeText')]
    public function test_literal_blade_text_does_not_create_runtime_dependencies(string $source): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] = $source;
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
        $this->assertSame([], $report['risks']);
    }

    public static function nonExecutingBladeText(): array
    {
        return [
            'verbatim' => ["@verbatim\n@include('site.missing-fixture-view')\n{{ route('site.missing-fixture-route') }}\n<x-no-such-fixture-component />\n@endverbatim\n"],
            'escaped include' => ["@@include('site.missing-fixture-view')\n"],
            'escaped dynamic include' => ['@@include($partial)'],
            'escaped nested helper' => ["@@include(view('site.missing-fixture-view'), ['a' => ['b']])\n"],
        ];
    }

    public function test_masking_literal_blade_text_preserves_adjacent_executing_dependencies(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] = "@@include('site.optional-fixture-view')\n@verbatim\n{{ route('unused') }}\n@endverbatim\n@includeIsolated('site.missing-fixture-view')\n";
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: site.missing-fixture-view']));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
    }

    public function test_dynamic_isolated_include_requires_explicit_dependencies(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] = '@includeIsolated($partial)';
        $this->expectExceptionMessage(__('admin.theme_packages.error.dynamic_dependency', ['dependency' => 'views', 'path' => 'resources/views/theme/fixture-theme/home.blade.php']));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
    }

    #[DataProvider('packageLimits')]
    public function test_installed_package_validation_can_skip_current_import_limits(string $key, string $error): void
    {
        $package = ThemePackageFixture::package(ThemePackageFixture::files());
        config(['geoflow.theme_packages.'.$key => 1]);
        $guard = app(SiteThemePackageGuard::class);
        $this->assertCount(count($package['files']), $guard->package($package, checkCompatibility: false, checkLimits: false));
        $this->expectExceptionMessage(__('admin.theme_packages.error.'.$error));
        $guard->package($package);
    }

    public static function packageLimits(): array
    {
        return [
            ['max_files', 'too_many_files'],
            ['max_file_bytes', 'file_too_large'],
            ['max_total_bytes', 'total_too_large'],
        ];
    }

    public function test_skipping_import_limits_keeps_file_integrity_validation(): void
    {
        $package = ThemePackageFixture::package(ThemePackageFixture::files());
        $package['files'][0]['bytes']++;
        $this->expectExceptionMessage(__('admin.theme_packages.error.manifest_mismatch'));
        app(SiteThemePackageGuard::class)->package($package, checkCompatibility: false, checkLimits: false);
    }

    public function test_manifest_read_limit_can_use_its_receipted_size(): void
    {
        $storage = app(SiteThemePackageStorage::class);
        $relative = 'installed/fixture-theme/resources/views/theme/fixture-theme/manifest.json';
        $manifest = ['id' => 'fixture-theme', 'description' => str_repeat('m', 100)];
        $storage->writeJson($relative, $manifest);
        $bytes = filesize($storage->path($relative));
        config(['geoflow.theme_packages.max_file_bytes' => 1]);
        $guard = app(SiteThemePackageGuard::class);
        $this->assertSame($manifest, $guard->manifest('installed/fixture-theme', 'fixture-theme', maxBytes: $bytes));
        $this->expectExceptionMessage(__('admin.theme_packages.error.invalid_package'));
        $guard->manifest('installed/fixture-theme', 'fixture-theme', maxBytes: $bytes - 1);
    }

    public function test_internal_json_rejects_oversized_reports_before_creating_an_unreadable_file(): void
    {
        $storage = app(SiteThemePackageStorage::class);
        $relative = 'uploads/12/'.str_repeat('a', 40).'/report.json';
        try {
            $storage->writeJson($relative, ['risks' => [str_repeat('r', 2 * 1024 * 1024)]]);
            $this->fail('An unreadable report was written.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.invalid_package'), $exception->getMessage());
        }
        $this->assertFileDoesNotExist($storage->path($relative));
    }

    public function test_internal_json_rejects_unreadable_depth_before_writing(): void
    {
        $storage = app(SiteThemePackageStorage::class);
        $data = ['leaf' => true];
        for ($depth = 1; $depth < 511; $depth++) {
            $data = ['nested' => $data];
        }
        $directory = 'uploads/12/'.str_repeat('a', 40);
        $storage->writeJson($directory.'/readable.json', $data);
        $this->assertSame($data, $storage->readJson($directory.'/readable.json'));
        try {
            $storage->writeJson($directory.'/unreadable.json', ['nested' => $data]);
            $this->fail('An unreadable JSON depth was written.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.invalid_package'), $exception->getMessage());
        }
        $this->assertFileDoesNotExist($storage->path($directory.'/unreadable.json'));
    }

    public function test_include_first_accepts_an_existing_candidate_and_each_allows_raw_empty_output(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n@includeFirst(['site.missing-fixture-view', 'theme.fixture-theme.layout'])\n@each('theme.fixture-theme.layout', [], 'item', 'raw|None')\n";
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
        $this->assertSame([], $report['risks']);
    }

    public function test_dynamic_conditional_and_first_dependencies_require_explicit_declarations(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n@includeWhen(true, \$partial)\n@includeFirst(\$candidates)\n";
        try {
            app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
            $this->fail('Dynamic directives were accepted without declarations.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.dynamic_dependency', ['dependency' => 'views', 'path' => 'resources/views/theme/fixture-theme/home.blade.php']), $exception->getMessage());
        }
        $package = ThemePackageFixture::package($files);
        $package['requires']['views'] = ['theme.fixture-theme.layout'];
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 12);
        $this->assertCount(1, $report['risks']);
    }

    #[DataProvider('invalidPresentationMetadata')]
    public function test_known_display_metadata_has_safe_scalar_types_before_inspection(string $scope, string $field, mixed $value): void
    {
        $files = ThemePackageFixture::files();
        if ($scope === 'manifest') {
            $manifest = json_decode($files['resources/views/theme/fixture-theme/manifest.json'], true);
            data_set($manifest, $field, $value);
            $files['resources/views/theme/fixture-theme/manifest.json'] = json_encode($manifest);
        }
        $package = ThemePackageFixture::package($files);
        if ($scope === 'package') {
            data_set($package, $field, $value);
        }
        $this->expectExceptionMessage(__('admin.theme_packages.error.invalid_package'));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 12);
    }

    public static function invalidPresentationMetadata(): array
    {
        return [
            'description object' => ['manifest', 'description', ['text' => 'bad']],
            'description null' => ['manifest', 'description', null],
            'manifest name object' => ['manifest', 'name', ['text' => 'bad']],
            'manifest visibility object' => ['manifest', 'visibility', ['private' => true]],
            'manifest distribution wrong type' => ['manifest', 'distribution', 'private'],
            'manifest distribution note object' => ['manifest', 'distribution.note', ['text' => 'bad']],
            'manifest distribution customer object' => ['manifest', 'distribution.customer', ['name' => 'bad']],
            'manifest distribution publish string' => ['manifest', 'distribution.publish_to_repository', 'false'],
            'package distribution visibility object' => ['package', 'distribution.visibility', ['private' => true]],
            'package distribution note object' => ['package', 'distribution.note', ['text' => 'bad']],
            'package distribution customer object' => ['package', 'distribution.customer', ['name' => 'bad']],
            'package distribution publish number' => ['package', 'distribution.publish_to_repository', 0],
        ];
    }

    public function test_unknown_anonymous_component_is_rejected_without_compiling_it(): void
    {
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n<x-no-such-geoflow-component />\n";
        $this->expectExceptionMessage(__('admin.theme_packages.error.missing_dependency', ['dependency' => 'views: components.no-such-geoflow-component']));
        app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
    }

    #[DataProvider('unresolvedComponentTags')]
    public function test_dynamic_namespaced_and_class_components_require_explicit_views_and_report_risk(string $tag): void
    {
        app('blade.compiler')->component(DynamicComponent::class, 'fixture-class-component');
        $files = ThemePackageFixture::files();
        $files['resources/views/theme/fixture-theme/home.blade.php'] .= "\n".$tag."\n";
        try {
            app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), 12);
            $this->fail('Unresolved component was accepted without a declaration.');
        } catch (RuntimeException $exception) {
            $this->assertSame(__('admin.theme_packages.error.dynamic_dependency', ['dependency' => 'views', 'path' => 'resources/views/theme/fixture-theme/home.blade.php']), $exception->getMessage());
        }
        $package = ThemePackageFixture::package($files);
        $package['requires']['views'] = ['site.home'];
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files, $package), 12);
        $this->assertCount(1, $report['risks']);
    }

    public static function unresolvedComponentTags(): array
    {
        return [
            'dynamic component' => ['<x-dynamic-component :component="$component" />'],
            'namespaced component' => ['<x-fixture::notice />'],
            'class alias' => ['<x-fixture-class-component />'],
        ];
    }

    public function test_storage_path_chain_and_cleanup_reject_symlink_root_without_touching_target(): void
    {
        $target = Storage::disk('local')->path('outside');
        mkdir($target);
        file_put_contents($target.'/keep.txt', 'keep');
        symlink($target, Storage::disk('local')->path('geoflow-site-themes'));
        try {
            app(SiteThemePackageStorage::class)->directory('uploads/1/'.str_repeat('a', 40));
            $this->fail('Storage followed a symlink.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('keep', file_get_contents($target.'/keep.txt'));
    }
}
