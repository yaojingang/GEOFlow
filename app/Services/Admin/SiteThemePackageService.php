<?php

namespace App\Services\Admin;

use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteThemePackageStorage;
use Closure;
use FilesystemIterator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

final class SiteThemePackageService
{
    public function __construct(
        private readonly SiteThemePackageGuard $guard,
        private readonly SiteThemePackageStorage $storage,
        private readonly InstalledSiteThemeRepository $installed,
    ) {}

    /** @return array{token:string,name:string,bytes:int,package:array,risks:list<string>,expires_at:string} */
    public function export(string $themeId, int $adminId): array
    {
        $this->owner($adminId);
        $id = $this->guard->themeId($themeId);
        $this->pruneExpired();
        $token = Str::random(40);

        return $this->storage->lock('token:'.$token, function () use ($id, $adminId, $token): array {
            $directory = 'exports/'.$adminId.'/'.$token;
            $this->storage->exclusiveDirectory($directory);
            try {
                $source = $this->source($id);
                $snapshot = $directory.'/snapshot';
                $this->storage->directory($snapshot);
                $files = $this->snapshot($id, $source, $snapshot);
                $manifest = $this->guard->manifest($snapshot, $id);
                $previous = $source['package'] ?? [];
                $requirements = $this->guard->exportRequirements($manifest, $previous['requires'] ?? []);
                $package = [
                    'format' => SiteThemePackageGuard::FORMAT,
                    'format_version' => 1,
                    'theme' => ['id' => $id, 'name' => (string) ($manifest['name'] ?? $previous['theme']['name'] ?? $id), 'version' => (string) ($manifest['version'] ?? $previous['theme']['version'] ?? '1.0.0')],
                    'created_at' => now()->toIso8601String(),
                    'exported_with' => ['geoflow' => (string) config('geoflow.app_version'), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'contracts' => SiteThemePackageGuard::CONTRACTS],
                    'requires' => $requirements,
                    'distribution' => $previous['distribution'] ?? $manifest['distribution'] ?? ['visibility' => (string) ($manifest['visibility'] ?? 'private')],
                    'pages' => $this->guard->pages($files, $id),
                    'files' => $files,
                    'content_sha256' => $this->guard->contentHash($files),
                ];
                $this->guard->package($package);
                $resolved = [];
                $risks = $this->guard->dependencies($package, $snapshot, $manifest, $resolved);
                $package['requires'] = array_replace($package['requires'], $resolved);
                $path = $this->storage->path($directory.'/theme.zip');
                $zip = new ZipArchive;
                if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                    $this->storage->fail('storage_failed');
                }
                try {
                    $metadata = $this->storage->encodeJson($package, 1024 * 1024);
                    if (! $zip->addFromString('package.json', $metadata)) {
                        $this->storage->fail('invalid_package');
                    }
                    foreach ($files as $file) {
                        $sourcePath = $this->storage->path($snapshot.'/'.$file['path']);
                        $this->storage->regular($sourcePath);
                        if (! $zip->addFile($sourcePath, $file['path'])) {
                            $this->storage->fail('storage_failed');
                        }
                    }
                } finally {
                    if (! $zip->close()) {
                        $this->storage->fail('storage_failed');
                    }
                }
                $this->verifyTree($snapshot, $package);
                $bytes = filesize($path);
                if (! is_int($bytes) || $bytes > $this->guard->limit('max_archive_bytes')) {
                    $this->storage->fail('archive_too_large');
                }
                $report = [
                    'token' => $token, 'admin_id' => $adminId, 'name' => $id.'.zip',
                    'bytes' => $bytes, 'package' => $package, 'risks' => $risks,
                    'expires_at' => $this->expiry(), 'archive_sha256' => hash_file('sha256', $path),
                ];
                $this->storage->writeJson($directory.'/report.json', $report);
                $this->storage->delete($snapshot);

                return $this->publicReport($report);
            } catch (Throwable $exception) {
                $this->storage->delete($directory);
                throw $exception;
            }
        });
    }

    /** @return array{path:string,name:string,bytes:int,package:array} */
    public function download(int $adminId, string $token): array
    {
        $this->identity($adminId, $token);

        return $this->storage->lock('token:'.$token, function () use ($adminId, $token): array {
            [$report, $path] = $this->stored('exports', $adminId, $token);

            return ['path' => $path, 'name' => $report['name'], 'bytes' => $report['bytes'], 'package' => $report['package']];
        });
    }

    /** @param Closure(resource):void $consumer */
    public function streamDownload(int $adminId, string $token, Closure $consumer): void
    {
        $this->identity($adminId, $token);
        $this->storage->lock('token:'.$token, function () use ($adminId, $token, $consumer): void {
            [$report, $path] = $this->stored('exports', $adminId, $token);
            $stream = @fopen($path, 'rb');
            if ($stream === false) {
                $this->storage->fail('invalid_token');
            }
            try {
                $this->storage->assertIdentity($stream, $path);
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream, $this->guard->limit('max_archive_bytes') + 1);
                if ($bytes !== $report['bytes'] || ! hash_equals($report['archive_sha256'], hash_final($hash)) || ! rewind($stream)) {
                    $this->storage->fail('invalid_token');
                }
                $consumer($stream);
            } finally {
                fclose($stream);
            }
        });
    }

    /** @return array{token:string,package:array,risks:list<string>,expires_at:string,bytes:int,conflict:?array{code:string,message:string}} */
    public function inspect(UploadedFile $file, int $adminId): array
    {
        $this->owner($adminId);
        if (! $file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'zip') {
            $this->storage->fail('invalid_archive');
        }
        $this->pruneExpired();
        $token = Str::random(40);

        return $this->storage->lock('token:'.$token, function () use ($file, $adminId, $token): array {
            $directory = 'uploads/'.$adminId.'/'.$token;
            $stage = 'staging/'.$token;
            $this->storage->exclusiveDirectory($directory);
            $stageCreated = false;
            try {
                $this->storage->exclusiveDirectory($stage);
                $stageCreated = true;
                $archive = $this->storage->path($directory.'/theme.zip');
                $source = $file->getRealPath();
                if (! is_string($source)) {
                    $this->storage->fail('invalid_archive');
                }
                $copied = $this->copyFile($source, $archive, $this->guard->limit('max_archive_bytes'), 'archive_too_large');
                $checked = $this->guard->unpack($archive, $stage);
                $this->assertArchiveHash($archive, $copied['sha256']);
                $report = [
                    'token' => $token, 'admin_id' => $adminId, 'package' => $checked['package'],
                    'risks' => $checked['risks'], 'expires_at' => $this->expiry(),
                    'archive_sha256' => $copied['sha256'], 'bytes' => $copied['bytes'],
                ];
                $this->storage->writeJson($directory.'/report.json', $report);

                return $this->publicReport($report) + ['conflict' => $this->conflict($checked['package'])];
            } catch (Throwable $exception) {
                $this->storage->delete($directory);
                throw $exception;
            } finally {
                if ($stageCreated) {
                    $this->storage->delete($stage);
                }
            }
        });
    }

    /** @return array{token:string,package:array,risks:list<string>,expires_at:string,bytes:int,conflict:?array{code:string,message:string}} */
    public function inspection(int $adminId, string $token): array
    {
        $this->identity($adminId, $token);

        return $this->storage->lock('token:'.$token, function () use ($adminId, $token): array {
            [$report] = $this->stored('uploads', $adminId, $token);

            return $this->publicReport($report) + ['conflict' => $this->conflict($report['package'])];
        });
    }

    /** @return array{path:string,bytes:int,sha256:string,contents:string} */
    public function inspectionFile(int $adminId, string $token, int $fileIndex): array
    {
        $this->identity($adminId, $token);

        return $this->storage->lock('token:'.$token, function () use ($adminId, $token, $fileIndex): array {
            [$report, $archive] = $this->stored('uploads', $adminId, $token);
            $file = $report['package']['files'][$fileIndex] ?? null;
            if (! is_array($file)) {
                $this->storage->fail('invalid_path');
            }
            $this->guard->filePath($file['path'], $report['package']['theme']['id']);
            $zip = new ZipArchive;
            if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
                $this->storage->fail('invalid_archive');
            }
            try {
                // Read the checked entry as bytes; source is never compiled during inspection.
                $contents = $zip->getFromName($file['path'], $file['bytes'] + 1);
                if (! is_string($contents) || strlen($contents) !== $file['bytes'] || ! hash_equals($file['sha256'], hash('sha256', $contents))) {
                    $this->storage->fail('manifest_mismatch');
                }

                return $file + ['contents' => $contents];
            } finally {
                $zip->close();
            }
        });
    }

    /** @return array<string,mixed> Installed repository descriptor and already_installed boolean. */
    public function install(int $adminId, string $token, bool $trustedSource): array
    {
        $this->identity($adminId, $token);
        if (! $trustedSource) {
            $this->storage->fail('trusted_source_required');
        }

        return $this->storage->lock('token:'.$token, function () use ($adminId, $token): array {
            [$report, $archive] = $this->stored('uploads', $adminId, $token);
            $stage = 'staging/'.$token;
            $this->storage->exclusiveDirectory($stage);
            try {
                $checked = $this->guard->unpack($archive, $stage);
                if ($checked['package'] !== $report['package']) {
                    $this->storage->fail('package_changed');
                }
                $this->assertArchiveHash($archive, $report['archive_sha256']);
                $package = $checked['package'];
                $id = $package['theme']['id'];

                return $this->storage->lock('theme:'.strtolower($id), function () use ($adminId, $stage, $package, $id, $report): array {
                    $conflict = $this->conflict($package);
                    if ($conflict !== null && $conflict['code'] !== 'already_installed') {
                        $this->storage->fail($conflict['code']);
                    }
                    if ($conflict !== null) {
                        $existing = $this->installed->find($id);
                        if ($existing === null) {
                            $this->storage->fail('theme_conflict');
                        }
                        $this->verifyTree('installed/'.$id, $existing['package']);

                        return $existing + ['already_installed' => true];
                    }
                    $this->verifyTree($stage, $package);
                    $this->storage->writeJson($stage.'/installation.json', [
                        'package' => $package, 'archive_sha256' => $report['archive_sha256'],
                        'content_sha256' => $package['content_sha256'],
                        'installed_at' => now()->toIso8601String(), 'installed_by' => $adminId,
                    ]);
                    $this->storage->directory('installed');
                    $destination = $this->storage->path('installed/'.$id);
                    $source = $this->storage->path($stage);
                    if (@lstat($destination) !== false || ! @rename($source, $destination)) {
                        $this->storage->fail('storage_failed');
                    }
                    $theme = $this->installed->find($id);
                    if ($theme === null) {
                        $this->storage->fail('storage_failed');
                    }

                    return $theme + ['already_installed' => false];
                });
            } finally {
                $this->storage->delete($stage);
            }
        });
    }

    public function pruneExpired(): void
    {
        foreach (['uploads', 'exports'] as $kind) {
            foreach ($this->entries($kind) as $admin) {
                if (! preg_match('/\A[1-9][0-9]*\z/D', $admin)) {
                    continue;
                }
                foreach ($this->entries($kind.'/'.$admin) as $token) {
                    if (preg_match('/\A[A-Za-z0-9]{40}\z/D', $token)) {
                        $this->pruneDirectory($kind.'/'.$admin.'/'.$token, $token);
                    }
                }
            }
        }
        foreach ($this->entries('staging') as $token) {
            if (preg_match('/\A[A-Za-z0-9]{40}\z/D', $token)) {
                $this->pruneDirectory('staging/'.$token, $token);
            }
        }
    }

    private function source(string $id): array
    {
        $builtIn = resource_path('views/theme/'.$id);
        if (is_dir($builtIn)) {
            return ['views_path' => $builtIn, 'assets_path' => public_path('themes/'.$id)];
        }
        $installed = $this->installed->find($id);
        if ($installed === null) {
            $this->storage->fail('theme_not_found');
        }

        return $installed;
    }

    private function snapshot(string $id, array $source, string $snapshot): array
    {
        $files = [];
        $folded = [];
        $total = 0;
        foreach (['views_path' => 'resources/views/theme/'.$id, 'assets_path' => 'public/themes/'.$id] as $key => $prefix) {
            $directory = $source[$key];
            if (@lstat($directory) === false) {
                continue;
            }
            if (is_link($directory) || ! is_dir($directory)) {
                $this->storage->fail('invalid_path');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    $this->storage->fail('invalid_path');
                }
                if ($item->isDir()) {
                    continue;
                }
                $sourcePath = $item->getPathname();
                $this->storage->regular($sourcePath);
                $path = $prefix.'/'.substr($sourcePath, strlen($directory) + 1);
                $this->guard->filePath($path, $id);
                if (isset($folded[strtolower($path)])) {
                    $this->storage->fail('duplicate_path');
                }
                $folded[strtolower($path)] = true;
                if (count($files) >= $this->guard->limit('max_files')) {
                    $this->storage->fail('too_many_files');
                }
                $this->storage->directory(dirname($snapshot.'/'.$path));
                $record = $this->copyFile($sourcePath, $this->storage->path($snapshot.'/'.$path), min($this->guard->limit('max_file_bytes'), $this->guard->limit('max_total_bytes') - $total), 'file_too_large');
                $total += $record['bytes'];
                $files[] = ['path' => $path] + $record;
            }
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $files;
    }

    private function copyFile(string $source, string $destination, int $limit, string $error): array
    {
        $this->storage->regular($source);
        $input = @fopen($source, 'rb');
        if ($input === false) {
            $this->storage->fail('storage_failed');
        }
        $output = null;
        try {
            $this->storage->assertIdentity($input, $source);
            $before = fstat($input);
            if ($before['size'] > $limit) {
                $this->storage->fail($error);
            }
            $output = @fopen($destination, 'xb');
            if ($output === false) {
                $this->storage->fail('storage_failed');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            while (! feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false || ($chunk === '' && ! feof($input))) {
                    $this->storage->fail('storage_failed');
                }
                $bytes += strlen($chunk);
                if ($bytes > $limit) {
                    $this->storage->fail($error);
                }
                $this->storage->write($output, $chunk);
                hash_update($hash, $chunk);
            }
            $this->storage->assertIdentity($input, $source);
            $after = fstat($input);
            if ($before['size'] !== $bytes || $after['size'] !== $before['size'] || $after['mtime'] !== $before['mtime'] || $after['ctime'] !== $before['ctime'] || ! fflush($output)) {
                $this->storage->fail('package_changed');
            }

            return ['bytes' => $bytes, 'sha256' => hash_final($hash)];
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    private function verifyTree(string $root, array $package): void
    {
        foreach ($package['files'] as $file) {
            $path = $this->storage->path($root.'/'.$file['path']);
            $this->storage->regular($path);
            if (filesize($path) !== $file['bytes'] || ! hash_equals($file['sha256'], (string) hash_file('sha256', $path))) {
                $this->storage->fail('package_changed');
            }
        }
    }

    private function stored(string $kind, int $adminId, string $token): array
    {
        try {
            $directory = $kind.'/'.$adminId.'/'.$token;
            $report = $this->storage->readJson($directory.'/report.json');
            if (($report['admin_id'] ?? null) !== $adminId || ($report['token'] ?? null) !== $token || ! is_string($report['expires_at'] ?? null) || strtotime($report['expires_at']) <= now()->timestamp) {
                $this->storage->fail('invalid_token');
            }
            $path = $this->storage->path($directory.'/theme.zip');
            if (! is_string($report['archive_sha256'] ?? null)) {
                $this->storage->fail('invalid_token');
            }
            $this->assertArchiveHash($path, $report['archive_sha256']);

            return [$report, $path];
        } catch (RuntimeException) {
            $this->storage->fail('invalid_token');
        }
    }

    private function conflict(array $package): ?array
    {
        $id = $package['theme']['id'];
        foreach ([resource_path('views/theme'), public_path('themes')] as $builtInRoot) {
            foreach (is_dir($builtInRoot) ? (scandir($builtInRoot) ?: []) : [] as $entry) {
                if (strcasecmp($id, $entry) === 0 && @lstat($builtInRoot.'/'.$entry) !== false) {
                    return ['code' => 'builtin_conflict', 'message' => __('admin.theme_packages.error.builtin_conflict')];
                }
            }
        }
        foreach ($this->entries('installed') as $entry) {
            if (strcasecmp($id, $entry) === 0) {
                $existing = $this->installed->find($entry);
                $same = $entry === $id && $existing !== null && hash_equals($existing['content_sha256'], $package['content_sha256']);
                $code = $same ? 'already_installed' : 'theme_conflict';

                return ['code' => $code, 'message' => __('admin.theme_packages.error.'.$code)];
            }
        }

        return null;
    }

    private function assertArchiveHash(string $path, string $hash): void
    {
        $this->storage->regular($path);
        if (filesize($path) > $this->guard->limit('max_archive_bytes') || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            $this->storage->fail('package_changed');
        }
    }

    private function publicReport(array $report): array
    {
        unset($report['admin_id'], $report['archive_sha256']);

        return $report;
    }

    private function identity(int $adminId, string $token): void
    {
        $this->owner($adminId);
        if (! preg_match('/\A[A-Za-z0-9]{40}\z/D', $token)) {
            $this->storage->fail('invalid_token');
        }
    }

    private function owner(int $adminId): void
    {
        if ($adminId < 1) {
            $this->storage->fail('invalid_token');
        }
    }

    private function expiry(): string
    {
        return now()->addMinutes(max(1, (int) config('geoflow.theme_packages.ttl_minutes', 60)))->toIso8601String();
    }

    private function entries(string $relative): array
    {
        try {
            $path = $this->storage->path($relative);

            return is_dir($path) ? array_values(array_diff(@scandir($path) ?: [], ['.', '..'])) : [];
        } catch (RuntimeException) {
            return [];
        }
    }

    private function pruneDirectory(string $directory, string $token): void
    {
        try {
            $this->storage->lock('token:'.$token, function () use ($directory): void {
                $path = $this->storage->path($directory);
                $stat = @lstat($path);
                if ($stat === false) {
                    return;
                }
                $expired = $stat['mtime'] <= now()->subMinutes(max(1, (int) config('geoflow.theme_packages.ttl_minutes', 60)))->timestamp;
                if (is_file($path.'/report.json')) {
                    $report = $this->storage->readJson($directory.'/report.json');
                    $expired = is_string($report['expires_at'] ?? null) && strtotime($report['expires_at']) <= now()->timestamp;
                }
                if ($expired) {
                    $this->storage->delete($directory);
                }
            }, false);
        } catch (RuntimeException) {
            // A malformed or busy owned directory remains untouched for manual inspection.
        }
    }
}
