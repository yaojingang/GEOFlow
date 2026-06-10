<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplicationService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ThemeReplicationPublishService
{
    public function __construct(
        private readonly SiteThemeReplicationService $replicationService,
        private readonly ThemeReplicationPackageService $packageService,
    ) {}

    /**
     * @return array{mode:string,message:string,package?:array{name:string,relative_path:string,absolute_path:string,bytes:int}}
     */
    public function publish(SiteThemeReplication $replication): array
    {
        if (! $replication->canPublish()) {
            throw new RuntimeException(__('admin.theme_replication.message.publish_unavailable'));
        }

        $diagnostics = $this->replicationService->deploymentDiagnostics();
        if (empty($diagnostics['can_publish_directly'])) {
            return [
                'mode' => 'package',
                'message' => __('admin.theme_replication.message.package_ready'),
                'package' => $this->packageService->createPackage($replication),
            ];
        }

        $version = $this->latestVersion($replication);
        $themeId = (string) $replication->theme_id;
        $targetViewsPath = resource_path("views/theme/{$themeId}");
        $targetAssetsPath = public_path("themes/{$themeId}");

        if ($this->targetExistsForAnotherTheme($replication, $targetViewsPath, $targetAssetsPath)) {
            throw new RuntimeException(__('admin.theme_replication.error.theme_target_exists'));
        }

        File::ensureDirectoryExists($targetViewsPath);
        File::ensureDirectoryExists($targetAssetsPath);

        $this->copyStorageDirectoryToFilesystem((string) $version->draft_views_path, $targetViewsPath);
        $this->copyStorageDirectoryToFilesystem((string) $version->draft_assets_path, $targetAssetsPath);

        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_PUBLISHED,
            'published_theme_path' => $targetViewsPath,
            'published_asset_path' => $targetAssetsPath,
            'published_at' => now(),
            'error_message' => null,
        ])->save();

        $this->replicationService->log($replication, 'info', 'published', __('admin.theme_replication.log.published'), [
            'version' => (int) $version->version,
            'views_path' => $targetViewsPath,
            'assets_path' => $targetAssetsPath,
        ]);

        return [
            'mode' => 'direct',
            'message' => __('admin.theme_replication.message.published'),
        ];
    }

    private function latestVersion(SiteThemeReplication $replication): SiteThemeReplicationVersion
    {
        $version = $replication->versions()->latest('version')->first();
        if (! $version) {
            throw new RuntimeException(__('admin.theme_replication.error.no_draft_version'));
        }

        return $version;
    }

    private function targetExistsForAnotherTheme(SiteThemeReplication $replication, string $viewsPath, string $assetsPath): bool
    {
        if ((string) $replication->published_theme_path === $viewsPath && (string) $replication->published_asset_path === $assetsPath) {
            return false;
        }

        return (is_dir($viewsPath) && count(File::allFiles($viewsPath)) > 0)
            || (is_dir($assetsPath) && count(File::allFiles($assetsPath)) > 0);
    }

    private function copyStorageDirectoryToFilesystem(string $storageDirectory, string $targetDirectory): void
    {
        $storageDirectory = trim($storageDirectory, '/');
        if ($storageDirectory === '' || ! Storage::disk('local')->exists($storageDirectory)) {
            throw new RuntimeException(__('admin.theme_replication.error.source_missing'));
        }

        foreach (Storage::disk('local')->allFiles($storageDirectory) as $sourcePath) {
            $relative = Str::after($sourcePath, $storageDirectory.'/');
            if (! $this->isSafeRelativePath($relative)) {
                continue;
            }

            $targetPath = $targetDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($targetPath));
            File::put($targetPath, Storage::disk('local')->get($sourcePath));
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && ! str_starts_with($path, '/')
            && ! str_contains($path, "\\");
    }
}
