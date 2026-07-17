<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Services\Admin\SiteThemeReplicationService;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class ThemeReplicationPackageService
{
    public function __construct(
        private readonly SiteThemeReplicationService $replicationService,
        private readonly ThemeReplicationPackagePathGuard $pathGuard,
        private readonly ThemeReplicationStorageGuard $storageGuard,
        private readonly ThemeReplicationStorageLock $storageLock,
    ) {}

    /**
     * @return array{name:string,relative_path:string,absolute_path:string,bytes:int}
     */
    public function createPackage(SiteThemeReplication $replication): array
    {
        $replicationId = $this->pathGuard->positiveInteger($replication->getKey());

        return $this->storageLock->run(
            $replicationId,
            fn (): array => $this->createPackageWhileLocked($replication, $replicationId),
        );
    }

    /**
     * @return array{name:string,relative_path:string,absolute_path:string,bytes:int}
     */
    private function createPackageWhileLocked(SiteThemeReplication $replication, int $replicationId): array
    {
        $packageDir = "geoflow-theme-replications/{$replicationId}/packages";
        $zip = new ZipArchive;
        $zipIsOpen = false;
        $temporaryPath = null;
        $relativePath = null;
        $backupPath = null;
        $finalPackageWritten = false;

        try {
            $replication = $replication->fresh();
            if (! $replication instanceof SiteThemeReplication) {
                $this->reject();
            }

            $themeId = $this->pathGuard->validatedThemeId((string) $replication->theme_id);
            $version = $this->latestVersion($replication);
            $versionNumber = $this->pathGuard->positiveInteger($version->getAttribute('version'));
            $packageName = "{$themeId}-v{$versionNumber}.zip";
            $relativePath = "{$packageDir}/{$packageName}";
            $temporaryPath = $packageDir.'/.'.$packageName.'.'.Str::random(20).'.tmp';
            $this->storageGuard->ensureStorageDirectory($packageDir);
            $absolutePath = Storage::disk('local')->path($relativePath);
            $temporaryAbsolutePath = Storage::disk('local')->path($temporaryPath);
            $draftRoot = "geoflow-theme-replications/{$replicationId}/draft/{$versionNumber}";
            $expectedViewsPath = $draftRoot.'/views';
            $expectedAssetsPath = $draftRoot.'/assets';
            $this->assertPackageBoundary($replication, $version, $versionNumber);
            if (
                (string) $version->draft_views_path !== $expectedViewsPath
                || (string) $version->draft_assets_path !== $expectedAssetsPath
            ) {
                $this->reject();
            }

            $files = $this->verifiedPackageFiles(
                $version,
                $draftRoot,
                $themeId,
            );

            if ($zip->open($temporaryAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
            }
            $zipIsOpen = true;

            foreach ($files as $file) {
                if (! $zip->addFromString($file['entry'], $file['content'])) {
                    throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
            }
            $zipIsOpen = false;

            if (@lstat($absolutePath) !== false) {
                if (is_link($absolutePath) || ! is_file($absolutePath)) {
                    $this->reject();
                }
                $backupPath = $packageDir.'/.'.$packageName.'.'.Str::random(20).'.bak';
                if (! @rename($absolutePath, Storage::disk('local')->path($backupPath))) {
                    throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
                }
            }
            if (! @rename($temporaryAbsolutePath, $absolutePath)) {
                throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
            }
            $finalPackageWritten = true;

            clearstatcache(true, $absolutePath);
            $packageBytes = @filesize($absolutePath);
            if (! is_file($absolutePath) || ! is_int($packageBytes)) {
                throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'));
            }

            $result = [
                'name' => $packageName,
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'bytes' => $packageBytes,
            ];

            $this->replicationService->log($replication, 'info', 'package_created', __('admin.theme_replication.log.package_created'), [
                'package' => $packageName,
                'bytes' => $result['bytes'],
            ]);
            if (is_string($backupPath)) {
                $this->storageGuard->deleteStorageFile($backupPath);
            }

            return $result;
        } catch (Throwable $exception) {
            if ($zipIsOpen) {
                $zip->close();
            }
            unset($zip);

            foreach ([$temporaryPath, $finalPackageWritten ? $relativePath : null] as $cleanupPath) {
                if (! is_string($cleanupPath)) {
                    continue;
                }
                try {
                    $this->storageGuard->deleteStorageFile($cleanupPath);
                } catch (Throwable) {
                    // Preserve the original failure while cleanup remains fail-closed.
                }
            }
            if (is_string($backupPath)) {
                $backupAbsolutePath = Storage::disk('local')->path($backupPath);
                if (@lstat($backupAbsolutePath) !== false && is_string($relativePath)) {
                    @rename($backupAbsolutePath, Storage::disk('local')->path($relativePath));
                }
            }

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(__('admin.theme_replication.error.package_create_failed'), 0, $exception);
        }
    }

    private function latestVersion(SiteThemeReplication $replication): SiteThemeReplicationVersion
    {
        $version = $replication->versions()->latest('version')->first();
        if (! $version) {
            throw new RuntimeException(__('admin.theme_replication.error.no_draft_version'));
        }

        return $version;
    }

    /**
     * @return list<array{entry:string,content:string}>
     */
    private function verifiedPackageFiles(
        SiteThemeReplicationVersion $version,
        string $draftRoot,
        string $themeId,
    ): array {
        $maxFiles = $this->configuredLimit('geoflow.theme_replication_package_max_files');
        $maxFileBytes = $this->configuredLimit('geoflow.theme_replication_package_max_file_bytes');
        $maxTotalBytes = $this->configuredLimit('geoflow.theme_replication_package_max_total_bytes');
        $manifest = $version->getAttribute('files_json');

        if (
            ! is_array($manifest)
            || $manifest === []
            || ($manifest['root_path'] ?? null) !== $draftRoot
            || ($manifest['views_path'] ?? null) !== $draftRoot.'/views'
            || ($manifest['assets_path'] ?? null) !== $draftRoot.'/assets'
        ) {
            $this->reject();
        }

        $records = $manifest['files'] ?? null;
        if (! is_array($records) || ! array_is_list($records) || $records === [] || count($records) > $maxFiles) {
            $this->reject();
        }

        $storageRootAbsolute = rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR);
        $storageRootReal = $this->canonicalDirectory($storageRootAbsolute);
        $draftRootAbsolute = Storage::disk('local')->path($draftRoot);
        $this->assertNoSymbolicLinkComponents($draftRootAbsolute, $storageRootAbsolute);
        $draftRootReal = $this->canonicalDirectory($draftRootAbsolute, $storageRootReal);
        $this->canonicalDirectory(Storage::disk('local')->path($draftRoot.'/views'), $draftRootReal);
        $this->canonicalDirectory(Storage::disk('local')->path($draftRoot.'/assets'), $draftRootReal);

        $files = [];
        $manifestStoragePaths = [];
        $seenPaths = [];
        $seenStoragePaths = [];
        $seenEntries = [];
        $declaredTotalBytes = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                $this->reject();
            }

            $keys = array_keys($record);
            sort($keys);
            if ($keys !== ['bytes', 'checksum', 'path', 'storage_path']) {
                $this->reject();
            }

            $path = $record['path'];
            $storagePath = $record['storage_path'];
            $bytes = $record['bytes'];
            $checksum = $record['checksum'];
            if (
                ! is_string($path)
                || ! is_string($storagePath)
                || ! is_int($bytes)
                || $bytes < 0
                || $bytes > $maxFileBytes
                || ! is_string($checksum)
                || ! preg_match('/\A[0-9a-f]{64}\z/D', $checksum)
            ) {
                $this->reject();
            }

            $this->pathGuard->assertSafeRelativePath($path);
            if (str_starts_with($path, 'views/')) {
                $relativePath = substr($path, strlen('views/'));
                $entryPrefix = "resources/views/theme/{$themeId}/";
            } elseif (str_starts_with($path, 'assets/')) {
                $relativePath = substr($path, strlen('assets/'));
                $entryPrefix = "public/themes/{$themeId}/";
            } else {
                $this->reject();
            }

            $this->pathGuard->assertSafeRelativePath($relativePath);
            $expectedStoragePath = $draftRoot.'/'.$path;
            $entry = $entryPrefix.$relativePath;
            $this->pathGuard->assertSafeArchiveEntry($entry, $entryPrefix);
            if (
                $storagePath !== $expectedStoragePath
                || isset($seenPaths[$path])
                || isset($seenStoragePaths[$storagePath])
                || isset($seenEntries[$entry])
                || $bytes > $maxTotalBytes - $declaredTotalBytes
            ) {
                $this->reject();
            }

            $seenPaths[$path] = true;
            $seenStoragePaths[$storagePath] = true;
            $seenEntries[$entry] = true;
            $declaredTotalBytes += $bytes;
            $manifestStoragePaths[] = $storagePath;
            $files[] = [
                'storage_path' => $storagePath,
                'entry' => $entry,
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        }

        sort($manifestStoragePaths);
        $actualStoragePaths = $this->actualFilePaths($draftRootReal, $draftRoot, $maxFiles);
        if ($manifestStoragePaths !== $actualStoragePaths) {
            $this->reject();
        }

        $actualTotalBytes = 0;
        foreach ($files as &$file) {
            $file['content'] = $this->verifiedFileContent(
                $file,
                $draftRootReal,
                $maxFileBytes,
                $maxTotalBytes,
                $actualTotalBytes,
            );
        }
        unset($file);

        return array_map(
            fn (array $file): array => [
                'entry' => (string) $file['entry'],
                'content' => (string) $file['content'],
            ],
            $files,
        );
    }

    private function assertPackageBoundary(
        SiteThemeReplication $replication,
        SiteThemeReplicationVersion $version,
        int $versionNumber,
    ): void {
        $currentVersion = $this->pathGuard->positiveInteger($replication->getAttribute('current_version'));
        $versionReport = $version->getAttribute('compliance_report_json');
        $versionManifest = $version->getAttribute('files_json');

        if (
            ! $replication->canPackage()
            || $versionNumber !== $currentVersion
            || ! is_array($versionReport)
            || ($versionReport['passed'] ?? null) !== true
            || ! is_array($versionManifest)
            || $replication->getAttribute('generated_files_json') !== $versionManifest
        ) {
            $this->reject();
        }
    }

    private function configuredLimit(string $key): int
    {
        return $this->pathGuard->positiveInteger(config($key));
    }

    private function canonicalDirectory(string $absolutePath, ?string $allowedRoot = null): string
    {
        if (@lstat($absolutePath) === false || is_link($absolutePath) || ! is_dir($absolutePath)) {
            $this->reject();
        }

        $realPath = realpath($absolutePath);
        if (! is_string($realPath) || ($allowedRoot !== null && ! $this->isWithinDirectory($realPath, $allowedRoot))) {
            $this->reject();
        }

        return $realPath;
    }

    private function assertNoSymbolicLinkComponents(string $absolutePath, string $storageRootAbsolute): void
    {
        $prefix = rtrim($storageRootAbsolute, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($absolutePath, $prefix)) {
            $this->reject();
        }

        $currentPath = rtrim($storageRootAbsolute, DIRECTORY_SEPARATOR);
        foreach (explode(DIRECTORY_SEPARATOR, substr($absolutePath, strlen($prefix))) as $segment) {
            if ($segment === '') {
                $this->reject();
            }

            $currentPath .= DIRECTORY_SEPARATOR.$segment;
            if (@lstat($currentPath) === false || is_link($currentPath)) {
                $this->reject();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function actualFilePaths(string $draftRootReal, string $draftRoot, int $maxFiles): array
    {
        $paths = [];

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($draftRootReal, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($iterator as $item) {
                $absolutePath = $item->getPathname();
                if (@lstat($absolutePath) === false || is_link($absolutePath)) {
                    $this->reject();
                }
                if ($item->isDir()) {
                    continue;
                }
                if (! $item->isFile()) {
                    $this->reject();
                }

                $realPath = realpath($absolutePath);
                if (! is_string($realPath) || ! $this->isWithinDirectory($realPath, $draftRootReal)) {
                    $this->reject();
                }

                $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($draftRootReal) + 1));
                $this->pathGuard->assertSafeRelativePath($relativePath);
                $paths[] = $draftRoot.'/'.$relativePath;
                if (count($paths) > $maxFiles) {
                    $this->reject();
                }
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject();
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param  array{storage_path:string,entry:string,bytes:int,checksum:string}  $file
     */
    private function verifiedFileContent(
        array $file,
        string $draftRootReal,
        int $maxFileBytes,
        int $maxTotalBytes,
        int &$actualTotalBytes,
    ): string {
        $absolutePath = Storage::disk('local')->path($file['storage_path']);
        $stat = @lstat($absolutePath);
        if ($stat === false || is_link($absolutePath) || ! is_file($absolutePath)) {
            $this->reject();
        }

        $realPath = realpath($absolutePath);
        $actualBytes = $stat['size'] ?? null;
        if (
            ! is_string($realPath)
            || ! $this->isWithinDirectory($realPath, $draftRootReal)
            || ! is_int($actualBytes)
            || $actualBytes !== $file['bytes']
            || $actualBytes > $maxFileBytes
            || $actualBytes > $maxTotalBytes - $actualTotalBytes
        ) {
            $this->reject();
        }
        $actualTotalBytes += $actualBytes;

        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            $this->reject();
        }

        try {
            $handleStat = fstat($handle);
            if (
                $handleStat === false
                || ($handleStat['mode'] & 0170000) !== 0100000
                || $handleStat['dev'] !== $stat['dev']
                || $handleStat['ino'] !== $stat['ino']
                || $handleStat['size'] !== $actualBytes
            ) {
                $this->reject();
            }

            $content = stream_get_contents($handle);
            if (
                ! is_string($content)
                || strlen($content) !== $file['bytes']
                || ! hash_equals($file['checksum'], hash('sha256', $content))
            ) {
                $this->reject();
            }
        } finally {
            fclose($handle);
        }

        return $content;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function reject(): never
    {
        throw new RuntimeException(__('admin.theme_replication.error.invalid_package_path'));
    }
}
