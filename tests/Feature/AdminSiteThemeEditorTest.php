<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSiteThemeEditorTest extends TestCase
{
    use RefreshDatabase;

    private string $themeId = 'test-live-editor';

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(resource_path("views/theme/{$this->themeId}"));
        File::ensureDirectoryExists(public_path("themes/{$this->themeId}"));
        File::put(resource_path("views/theme/{$this->themeId}/manifest.json"), json_encode([
            'name' => 'Test Live Editor',
            'version' => 'v1.0.0',
            'description' => 'Theme used by live editor tests.',
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::put(resource_path("views/theme/{$this->themeId}/home.blade.php"), '<!doctype html><html><head><meta charset="utf-8"><title>{{ $siteTitle }}</title></head><body><main id="home-preview">{{ $siteTitle }} {{ $articles->count() }}</main></body></html>');
        File::put(resource_path("views/theme/{$this->themeId}/category.blade.php"), '<!doctype html><html><head><meta charset="utf-8"><title>{{ $category->name }}</title></head><body><main id="category-preview">{{ $category->name }}</main></body></html>');
        File::put(resource_path("views/theme/{$this->themeId}/article.blade.php"), '<!doctype html><html><head><meta charset="utf-8"><title>{{ $article->title }}</title></head><body><article id="article-preview">{{ $article->title }} {!! $contentHtml !!}</article></body></html>');
        File::put(public_path("themes/{$this->themeId}/theme.css"), 'body{background:#fff;}');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path("views/theme/{$this->themeId}"));
        File::deleteDirectory(public_path("themes/{$this->themeId}"));
        Storage::disk('local')->deleteDirectory("theme-editor/drafts/{$this->themeId}");
        Storage::disk('local')->deleteDirectory("theme-editor/backups/{$this->themeId}");

        parent::tearDown();
    }

    public function test_super_admin_can_open_live_theme_editor(): void
    {
        $this->actingAs($this->createAdmin('theme_editor_root', 'super_admin'), 'admin')
            ->get(route('admin.site-settings.theme-editor.edit', ['themeId' => $this->themeId, 'page' => 'home']))
            ->assertOk()
            ->assertSee(__('admin.theme_editor.heading'))
            ->assertSee($this->themeId)
            ->assertSee('id="theme-editor-preview"', false)
            ->assertSee('id="theme-editor-blade"', false)
            ->assertSee('id="theme-editor-css"', false);
    }

    public function test_standard_admin_cannot_open_live_theme_editor(): void
    {
        $this->actingAs($this->createAdmin('theme_editor_admin', 'admin'), 'admin')
            ->get(route('admin.site-settings.theme-editor.edit', ['themeId' => $this->themeId, 'page' => 'home']))
            ->assertForbidden();
    }

    public function test_editor_saves_draft_and_preview_uses_draft_source(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $blade = '<!doctype html><html><head><meta charset="utf-8"></head><body><main>Draft Preview Marker</main></body></html>';
        $css = '.draft-marker{color:#123456;}';

        $this->actingAs($this->createAdmin('theme_editor_draft', 'super_admin'), 'admin')
            ->postJson(route('admin.site-settings.theme-editor.draft', ['themeId' => $this->themeId, 'page' => 'home']), [
                'blade' => $blade,
                'css' => $css,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($this->createAdmin('theme_editor_preview', 'super_admin'), 'admin')
            ->get(route('admin.site-settings.theme-editor.preview', ['themeId' => $this->themeId, 'page' => 'home']))
            ->assertOk()
            ->assertSee('Draft Preview Marker')
            ->assertSee('.draft-marker{color:#123456;}', false);
    }

    public function test_editor_preview_shows_error_panel_for_invalid_draft(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($this->createAdmin('theme_editor_invalid_draft', 'super_admin'), 'admin')
            ->postJson(route('admin.site-settings.theme-editor.draft', ['themeId' => $this->themeId, 'page' => 'home']), [
                'blade' => '<!doctype html><html><body>@php($broken = )</body></html>',
                'css' => '',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($this->createAdmin('theme_editor_invalid_preview', 'super_admin'), 'admin')
            ->get(route('admin.site-settings.theme-editor.preview', ['themeId' => $this->themeId, 'page' => 'home']))
            ->assertOk()
            ->assertSee(__('admin.theme_editor.preview_error_title'))
            ->assertSee('box', false);
    }

    public function test_editor_publish_backs_up_and_writes_theme_files(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $blade = '<!doctype html><html><head><meta charset="utf-8"></head><body><main>Published Theme Marker</main></body></html>';
        $css = 'body{background:#ffffff}.published-marker{display:block;}';

        $this->actingAs($this->createAdmin('theme_editor_publish', 'super_admin'), 'admin')
            ->postJson(route('admin.site-settings.theme-editor.publish', ['themeId' => $this->themeId, 'page' => 'home']), [
                'blade' => $blade,
                'css' => $css,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['backup_dir']);

        $this->assertSame($blade, (string) file_get_contents(resource_path("views/theme/{$this->themeId}/home.blade.php")));
        $this->assertSame($css, (string) file_get_contents(public_path("themes/{$this->themeId}/theme.css")));
        $this->assertNotEmpty(Storage::disk('local')->allFiles("theme-editor/backups/{$this->themeId}"));
    }

    public function test_site_settings_page_exposes_editor_links_to_super_admin(): void
    {
        $response = $this->actingAs($this->createAdmin('theme_editor_links', 'super_admin'), 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk();

        $response
            ->assertSee('Test Live Editor')
            ->assertSee(route('admin.site-settings.theme-editor.edit', ['themeId' => $this->themeId, 'page' => 'home'], false), false)
            ->assertSee(route('admin.site-settings.theme-editor.edit', ['themeId' => $this->themeId, 'page' => 'category'], false), false)
            ->assertSee(route('admin.site-settings.theme-editor.edit', ['themeId' => $this->themeId, 'page' => 'article'], false), false);
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
