<?php

namespace App\Services\Admin;

use App\Support\Site\SiteThemeCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SiteThemeEditorService
{
    public const PAGES = ['home', 'category', 'article'];

    private const MAX_BLADE_LENGTH = 600000;

    private const MAX_CSS_LENGTH = 400000;

    public function __construct(private readonly SiteThemeCatalog $catalog) {}

    /**
     * @return array{
     *   theme_id:string,
     *   page:string,
     *   blade:string,
     *   css:string,
     *   blade_path:string,
     *   css_path:string,
     *   draft_exists:bool,
     *   draft_updated_at:string|null
     * }
     */
    public function source(string $themeId, string $page): array
    {
        $this->assertEditable($themeId, $page);

        $current = $this->currentSource($themeId, $page);
        $draft = $this->draftPayload($themeId, $page);

        return [
            'theme_id' => $themeId,
            'page' => $page,
            'blade' => is_array($draft) ? (string) ($draft['blade'] ?? $current['blade']) : $current['blade'],
            'css' => is_array($draft) ? (string) ($draft['css'] ?? $current['css']) : $current['css'],
            'blade_path' => $current['blade_path'],
            'css_path' => $current['css_path'],
            'draft_exists' => is_array($draft),
            'draft_updated_at' => is_array($draft) ? (string) ($draft['updated_at'] ?? '') : null,
        ];
    }

    /**
     * @return array{theme_id:string,page:string,updated_at:string}
     */
    public function saveDraft(string $themeId, string $page, string $blade, string $css, ?int $adminId = null): array
    {
        $this->assertEditable($themeId, $page);
        $this->assertSourceSize($blade, $css);

        $payload = [
            'theme_id' => $themeId,
            'page' => $page,
            'blade' => $blade,
            'css' => $css,
            'admin_id' => $adminId,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];

        Storage::disk('local')->put($this->draftPath($themeId, $page), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'theme_id' => $themeId,
            'page' => $page,
            'updated_at' => (string) $payload['updated_at'],
        ];
    }

    /**
     * @return array{theme_id:string,page:string,backup_dir:string,updated_at:string}
     */
    public function publish(string $themeId, string $page, ?string $blade = null, ?string $css = null, ?int $adminId = null): array
    {
        $this->assertEditable($themeId, $page);

        $source = $this->source($themeId, $page);
        $blade = $blade ?? $source['blade'];
        $css = $css ?? $source['css'];
        $this->assertSourceSize($blade, $css);

        $current = $this->currentSource($themeId, $page);
        $timestamp = Carbon::now()->format('YmdHis');
        $backupDir = "theme-editor/backups/{$themeId}/{$timestamp}-{$page}";

        Storage::disk('local')->put($backupDir.'/'.$page.'.blade.php', $current['blade']);
        Storage::disk('local')->put($backupDir.'/theme.css', $current['css']);
        Storage::disk('local')->put($backupDir.'/meta.json', json_encode([
            'theme_id' => $themeId,
            'page' => $page,
            'admin_id' => $adminId,
            'blade_path' => $current['blade_path'],
            'css_path' => $current['css_path'],
            'created_at' => Carbon::now()->toDateTimeString(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        File::ensureDirectoryExists(dirname($current['blade_path']));
        File::ensureDirectoryExists(dirname($current['css_path']));
        File::put($current['blade_path'], $blade);
        File::put($current['css_path'], $css);

        $this->discardDraft($themeId, $page);
        Artisan::call('view:clear');

        return [
            'theme_id' => $themeId,
            'page' => $page,
            'backup_dir' => $backupDir,
            'updated_at' => Carbon::now()->toDateTimeString(),
        ];
    }

    public function discardDraft(string $themeId, string $page): void
    {
        $this->assertEditable($themeId, $page);
        Storage::disk('local')->delete($this->draftPath($themeId, $page));
    }

    public function assertEditable(string $themeId, string $page): void
    {
        if (! in_array($page, self::PAGES, true) || preg_match('/^[A-Za-z0-9_-]+$/', $themeId) !== 1) {
            throw new NotFoundHttpException;
        }

        if (! in_array($themeId, $this->catalog->ids(), true)) {
            throw new NotFoundHttpException;
        }

        $bladePath = resource_path("views/theme/{$themeId}/{$page}.blade.php");
        if (! is_file($bladePath)) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @return array{blade:string,css:string,blade_path:string,css_path:string}
     */
    private function currentSource(string $themeId, string $page): array
    {
        $bladePath = resource_path("views/theme/{$themeId}/{$page}.blade.php");
        $cssPath = public_path("themes/{$themeId}/theme.css");

        return [
            'blade' => is_file($bladePath) ? (string) file_get_contents($bladePath) : '',
            'css' => is_file($cssPath) ? (string) file_get_contents($cssPath) : '',
            'blade_path' => $bladePath,
            'css_path' => $cssPath,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function draftPayload(string $themeId, string $page): ?array
    {
        $path = $this->draftPath($themeId, $page);
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function draftPath(string $themeId, string $page): string
    {
        return "theme-editor/drafts/{$themeId}/{$page}.json";
    }

    private function assertSourceSize(string $blade, string $css): void
    {
        if (mb_strlen($blade, '8bit') > self::MAX_BLADE_LENGTH || mb_strlen($css, '8bit') > self::MAX_CSS_LENGTH) {
            throw ValidationException::withMessages([
                'blade' => __('admin.theme_editor.error_too_large'),
            ]);
        }
    }
}
