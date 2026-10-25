<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class AdminSiteThemePackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable();
            $table->string('admin_username', 50);
            $table->string('admin_role', 20)->default('admin');
            $table->string('action', 120);
            $table->string('request_method', 10)->default('POST');
            $table->string('page')->default('');
            $table->string('target_type', 50)->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->text('details')->default('');
            $table->timestamp('created_at')->nullable();
        });
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_import_inspect_install_preview_and_separate_activation(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        $this->get($this->url('imports.create'))->assertOk()->assertSee(__('admin.theme_packages.upload_intro'));
        $upload = $this->post($this->url('imports.store'), ['package_file' => ThemePackageFixture::archive(ThemePackageFixture::files())]);
        $upload->assertRedirect();
        $token = basename($upload->headers->get('Location'));
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        $this->get($upload->headers->get('Location'))->assertOk()->assertSee('Fixture theme')->assertSee('trusted_source');
        $this->post($this->url('imports.install', ['token' => $token]))->assertSessionHasErrors('trusted_source');
        $this->post($this->url('imports.install', ['token' => $token]), ['trusted_source' => '1'])->assertOk()->assertSee(__('admin.theme_packages.not_active'));
        $this->assertNotSame('fixture-theme', SiteSettingsBag::get('active_theme'));
        $this->get($this->url('preview', ['themeId' => 'fixture-theme']))->assertOk()->assertSee('sandbox="allow-scripts allow-popups"', false);
        $this->get($this->url('preview.frame', ['themeId' => 'fixture-theme']))->assertOk()->assertSee('Fixture home');
        $this->post(route('admin.site-settings.theme'), ['active_theme' => 'fixture-theme'])->assertRedirect();
        $this->assertSame('fixture-theme', SiteSettingsBag::get('active_theme'));
        $this->get(route('site.home'))->assertOk()->assertSee('Fixture home');
    }

    public function test_export_download_is_bound_to_owner_and_does_not_record_private_payload(): void
    {
        $admin = $this->admin();
        $this->install($admin);
        $export = $this->actingAs($admin, 'admin')->post($this->url('exports.store'), ['theme_id' => 'fixture-theme', 'private_marker' => 'NEVER-LOG-ME']);
        $export->assertOk();
        $data = $export->viewData('export');
        $url = $this->url('exports.download', ['token' => $data['token']]);
        $download = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('PK', $download->streamedContent());
        $this->actingAs($this->admin('second'), 'admin')->get($url)->assertNotFound();
        $logs = json_encode(AdminActivityLog::query()->get()->toArray());
        $this->assertStringNotContainsString('NEVER-LOG-ME', $logs);
        $this->assertStringNotContainsString($data['token'], $logs);
        $this->assertStringContainsString($data['package']['content_sha256'], $logs);
        $this->assertStringContainsString('package_sha256', $logs);
    }

    public function test_non_super_admin_cannot_use_any_package_endpoint_or_activate_imported_theme(): void
    {
        $this->install($this->admin());
        $this->actingAs($this->admin('editor', 'admin'), 'admin');
        foreach (['imports.create', 'imports.show', 'exports.download', 'preview', 'preview.frame'] as $route) {
            $this->get($this->url($route, ['token' => str_repeat('a', 40), 'themeId' => 'fixture-theme']))->assertForbidden();
        }
        foreach (['imports.store', 'exports.store', 'imports.install'] as $route) {
            $this->post($this->url($route, ['token' => str_repeat('a', 40)]))->assertForbidden();
        }
        $this->post(route('admin.site-settings.theme'), ['active_theme' => 'fixture-theme'])->assertForbidden();
        $this->get(route('admin.site-settings.index'))->assertOk()->assertDontSee($this->url('imports.create'), false);
    }

    public function test_upload_requires_zip_and_inspection_tokens_expire(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->post($this->url('imports.store'))->assertSessionHasErrors('package_file');
        $inspection = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        $url = $this->url('imports.show', ['token' => $inspection['token']]);
        $this->actingAs($this->admin('second'), 'admin')->get($url)->assertNotFound();
        $this->travel(61)->minutes();
        $this->actingAs($admin, 'admin')->get($url)->assertNotFound();
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
    }

    public function test_package_mutations_require_csrf_outside_the_test_bypass(): void
    {
        $this->withMiddleware(ValidateCsrfToken::class);
        $this->app->instance('env', 'local');
        try {
            $this->actingAs($this->admin(), 'admin')->post($this->url('exports.store'), ['theme_id' => 'fixture-theme'])->assertStatus(419);
            $this->post($this->url('imports.store'))->assertStatus(419);
            $this->post($this->url('imports.install', ['token' => str_repeat('a', 40)]), ['trusted_source' => true])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_inspection_file_viewer_escapes_source_and_shows_images_without_installing(): void
    {
        $admin = $this->admin();
        $files = ThemePackageFixture::files();
        $sourcePath = 'resources/views/theme/fixture-theme/home.blade.php';
        $source = '@php throw new \\RuntimeException("MUST-NOT-EXECUTE"); @endphp<script>alert("SOURCE")</script>';
        $files[$sourcePath] = $source;
        $imagePath = 'public/themes/fixture-theme/preview.png';
        $files[$imagePath] = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6bS8AAAAASUVORK5CYII=');
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive($files), $admin->id);
        $indexes = array_flip(array_column($report['package']['files'], 'path'));
        $url = $this->url('imports.file', ['token' => $report['token'], 'fileIndex' => $indexes[$sourcePath]]);
        $this->actingAs($admin, 'admin')->get($this->url('imports.show', ['token' => $report['token']]))
            ->assertOk()->assertSee(__('admin.theme_packages.requirements'))->assertSee($url, false);
        $this->get($url)->assertOk()->assertSee($source)->assertDontSee('<script>alert("SOURCE")</script>', false)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => $indexes[$imagePath]]))
            ->assertOk()->assertSee('data:image/png;base64,', false);
        $this->assertNull(app(InstalledSiteThemeRepository::class)->find('fixture-theme'));
        $this->actingAs($this->admin('editor', 'admin'), 'admin')->get($url)->assertForbidden();
        $this->actingAs($this->admin('second'), 'admin')->get($url)->assertNotFound();
        $this->actingAs($admin, 'admin')->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => 999]))->assertNotFound();
        $this->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => str_repeat('9', 24)]))->assertNotFound();
        $this->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => '../report.json']))->assertNotFound();
        $this->travel(61)->minutes();
        $this->get($url)->assertNotFound();
    }

    public function test_inspection_file_viewer_rechecks_archive_integrity(): void
    {
        $admin = $this->admin();
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        file_put_contents(Storage::disk('local')->path('geoflow-site-themes/uploads/'.$admin->id.'/'.$report['token'].'/theme.zip'), 'changed');
        $this->actingAs($admin, 'admin')->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => 0]))->assertNotFound();
    }

    public function test_traditional_chinese_package_pages_use_localized_labels(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->withSession(['locale' => 'zh_TW'])
            ->get($this->url('imports.create'))->assertOk()->assertSee('匯入模板')->assertSee('上傳並檢查');
        $report = app(SiteThemePackageService::class)->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        $this->get($this->url('imports.show', ['token' => $report['token']]))->assertOk()
            ->assertSee('相容性檢查已通過')->assertSee('查看執行環境與相依項目');
        $this->get($this->url('imports.file', ['token' => $report['token'], 'fileIndex' => 0]))->assertOk()
            ->assertSee('查看包內檔案')->assertSee('返回檢查報告');
        $this->assertSame(
            array_keys(Arr::dot(require lang_path('zh_CN/theme_packages.php'))),
            array_keys(Arr::dot(require lang_path('zh_TW/theme_packages.php'))),
        );
        foreach (['theme_package_upload' => '匯入模板', 'theme_package_inspection' => '檢查模板套件', 'theme_package_preview' => '預覽模板'] as $key => $label) {
            $this->assertSame($label, __('admin_pages.'.$key));
        }
    }

    private function admin(string $name = 'owner', string $role = 'super_admin'): Admin
    {
        return Admin::query()->create(['username' => $name, 'password' => 'test-password', 'role' => $role, 'status' => 'active']);
    }

    private function install(Admin $admin): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $admin->id);
        $service->install($admin->id, $report['token'], true);
    }

    private function url(string $name, array $parameters = []): string
    {
        return route('admin.site-settings.theme-packages.'.$name, $parameters);
    }
}
