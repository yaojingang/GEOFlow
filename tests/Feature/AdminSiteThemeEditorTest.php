<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSiteThemeEditorTest extends TestCase
{
    use RefreshDatabase;

    public function test_online_theme_editor_routes_and_production_code_are_removed(): void
    {
        $routeNames = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter()
            ->values();

        $this->assertFalse($routeNames->contains(
            static fn (string $name): bool => str_starts_with($name, 'admin.site-settings.theme-editor.')
        ));
        $this->assertFileDoesNotExist(app_path('Http/Controllers/Admin/SiteThemeEditorController.php'));
        $this->assertFileDoesNotExist(app_path('Services/Admin/SiteThemeEditorService.php'));
        $this->assertFileDoesNotExist(resource_path('views/admin/site-theme-editor/edit.blade.php'));

        $routes = File::get(base_path('routes/web.php'));
        $this->assertStringNotContainsString('SiteThemeEditorController', $routes);
        $this->assertStringNotContainsString('theme-editor', $routes);
    }

    public function test_legacy_editor_urls_cannot_execute_or_change_live_theme_files(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $admin = $this->createAdmin('theme_editor_disabled', 'super_admin');
        $marker = storage_path('framework/testing/theme-editor-executed.php');
        File::delete($marker);

        $viewsBefore = $this->directorySnapshot(resource_path('views/theme'));
        $assetsBefore = $this->directorySnapshot(public_path('themes'));
        $base = '/'.trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/').'/site-settings/theme-editor/default/home';
        $payload = [
            'blade' => "@php(file_put_contents('{$marker}', 'executed'))",
            'css' => '@import url("https://external.example/theme.css");',
        ];

        foreach ([$base, $base.'/preview'] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertNotFound();
        }

        foreach ([$base.'/draft', $base.'/publish', $base.'/discard'] as $url) {
            $this->actingAs($admin, 'admin')->postJson($url, $payload)->assertNotFound();
        }

        $this->assertFileDoesNotExist($marker);
        $this->assertSame($viewsBefore, $this->directorySnapshot(resource_path('views/theme')));
        $this->assertSame($assetsBefore, $this->directorySnapshot(public_path('themes')));
    }

    public function test_site_settings_page_has_no_theme_editor_or_preview_entry(): void
    {
        $this->actingAs($this->createAdmin('theme_editor_links_removed', 'super_admin'), 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertDontSee('/theme-editor/', false);
    }

    #[DataProvider('supportedAdminLocales')]
    public function test_removed_theme_editor_has_no_orphaned_translations(string $locale): void
    {
        $messages = require lang_path($locale.'/admin.php');

        $this->assertArrayNotHasKey('theme_editor', $messages);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedAdminLocales(): array
    {
        return [
            'English' => ['en'],
            'Simplified Chinese' => ['zh_CN'],
            'Brazilian Portuguese' => ['pt_BR'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function directorySnapshot(string $path): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $snapshot = [];
        foreach (File::allFiles($path) as $file) {
            $snapshot[$file->getRelativePathname()] = hash_file('sha256', $file->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
