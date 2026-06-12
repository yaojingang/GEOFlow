<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplicationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class ThemeReplicationPackageService
{
    public function __construct(private readonly SiteThemeReplicationService $replicationService) {}

    /**
     * @return array{name:string,relative_path:string,absolute_path:string,bytes:int}
     */
    public function createPackage(SiteThemeReplication $replication): array
    {
        $version = $this->latestVersion($replication);
        $themeId = (string) $replication->theme_id;
        $versionNumber = (int) $version->version;
        $packageDir = "geoflow-theme-replications/{$replication->id}/packages";
        $packageName = "{$themeId}-v{$versionNumber}.zip";
        $relativePath = "{$packageDir}/{$packageName}";

        Storage::disk('local')->makeDirectory($packageDir);
        $absolutePath = Storage::disk('local')->path($relativePath);

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
        }

        $this->addDirectoryToZip($zip, (string) $version->draft_views_path, "resources/views/theme/{$themeId}");
        $this->addDirectoryToZip($zip, (string) $version->draft_assets_path, "public/themes/{$themeId}");
        if (! $zip->close()) {
            throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
        }

        clearstatcache(true, $absolutePath);

        $result = [
            'name' => $packageName,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'bytes' => is_file($absolutePath) ? (int) filesize($absolutePath) : 0,
        ];

        $this->replicationService->log($replication, 'info', 'package_created', __('admin.theme_replication.log.package_created'), [
            'package' => $packageName,
            'bytes' => $result['bytes'],
        ]);

        return $result;
    }

    private function latestVersion(SiteThemeReplication $replication): SiteThemeReplicationVersion
    {
        $version = $replication->versions()->latest('version')->first();
        if (! $version) {
            throw new RuntimeException(__('admin.theme_replication.error.no_draft_version'));
        }

        return $version;
    }

    private function addDirectoryToZip(ZipArchive $zip, string $storageDirectory, string $targetDirectory): void
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

            $zip->addFile(Storage::disk('local')->path($sourcePath), $targetDirectory.'/'.$relative);
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
