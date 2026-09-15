<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\ThemeRevision;
use App\Services\Admin\SiteThemePackageGuard;
use App\Support\Site\SiteThemePackageStorage;
use Illuminate\Support\Str;

final class ThemeRevisionStorage
{
    public function __construct(public readonly SiteThemePackageStorage $storage, private SiteThemePackageGuard $guard) {}

    /** @param array<string, string> $contents */
    public function create(string $workspace, string $theme, array $contents, array $settings, ?string $parent = null): ThemeRevision
    {
        ksort($contents);
        $files = [];
        $folded = [];
        $components = [];
        $total = 0;
        foreach ($contents as $path => $bytes) {
            $this->validatePath($path, $theme);
            if (isset($folded[strtolower($path)]) || strlen($bytes) > $this->guard->limit('max_file_bytes')) {
                throw new ApiException('invalid_theme_file', '主题文件重复或超过大小限制', 422);
            }
            $prefix = '';
            foreach (explode('/', $path) as $segment) {
                $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
                $foldedPrefix = strtolower($prefix);
                if (isset($components[$foldedPrefix]) && $components[$foldedPrefix] !== $prefix) {
                    throw new ApiException('invalid_theme_file', '主题文件路径的目录或文件名存在大小写冲突', 422);
                }
                $components[$foldedPrefix] = $prefix;
            }
            $folded[strtolower($path)] = true;
            $total += strlen($bytes);
            $files[$path] = ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
        }
        foreach (array_keys($files) as $path) {
            $parentPath = dirname($path);
            while ($parentPath !== '.') {
                if (isset($folded[strtolower($parentPath)])) {
                    throw new ApiException('invalid_theme_file', '主题文件路径与另一文件所需的目录冲突', 422);
                }
                $parentPath = dirname($parentPath);
            }
        }
        if (count($files) > $this->guard->limit('max_files') || $total > $this->guard->limit('max_total_bytes')) {
            throw new ApiException('theme_limit_exceeded', '主题文件数量或总大小超过限制', 422);
        }

        return $this->storage->lock('managed-theme-storage', function () use ($workspace, $theme, $contents, $settings, $parent, $files, $total): ThemeRevision {
            $root = $this->storage->directory('revisions');
            $free = disk_free_space($root);
            if ($free === false || $free - $total < 1024 * 1024 * 1024 || ThemeRevision::query()->sum('total_bytes') + $total > 2 * 1024 * 1024 * 1024) {
                throw new ApiException('theme_storage_quota', '主题存储配额不足，请先检查可清理版本和磁盘容量', 507);
            }
            $revision = ThemeRevision::query()->create([
                'id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'parent_id' => $parent,
                'theme_id' => $theme, 'state' => 'preparing', 'files' => $files, 'settings' => $settings,
                'dependencies' => $this->dependencies(), 'content_sha256' => hash('sha256', json_encode([$files, $settings], JSON_THROW_ON_ERROR)),
                'total_bytes' => $total,
            ]);
            $relative = 'revisions/'.$revision->id;
            $this->storage->exclusiveDirectory($relative);
            foreach ($contents as $path => $bytes) {
                $this->storage->directory($relative.'/'.dirname($path));
                $absolute = $this->storage->path($relative.'/'.$path);
                $handle = @fopen($absolute, 'xb');
                if ($handle === false) {
                    throw new ApiException('theme_storage_failed', '无法准备主题文件，当前线上版本保持可用', 507);
                }
                try {
                    $this->storage->write($handle, $bytes);
                    if (! fflush($handle) || ! fsync($handle)) {
                        throw new ApiException('theme_storage_failed', '主题文件未持久化', 507);
                    }
                } finally {
                    fclose($handle);
                }
                $this->storage->regular($absolute);
                if (! hash_equals($files[$path]['sha256'], (string) hash_file('sha256', $absolute))) {
                    throw new ApiException('theme_storage_failed', '主题文件校验失败', 507);
                }
            }
            $this->storage->writeJson($relative.'/revision.json', ['id' => $revision->id, 'files' => $files, 'content_sha256' => $revision->content_sha256]);
            $revision->update(['state' => 'ready']);

            return $revision;
        });
    }

    /** @return array<string, string> */
    public function contents(ThemeRevision $revision): array
    {
        if ($revision->state !== 'ready') {
            throw new ApiException('revision_unavailable', '主题版本尚未准备完成', 409);
        }
        $contents = [];
        foreach ($revision->files as $path => $record) {
            $absolute = $this->path($revision, $path);
            $bytes = $this->read($absolute, $this->guard->limit('max_file_bytes'));
            if (strlen($bytes) !== $record['bytes'] || ! hash_equals($record['sha256'], hash('sha256', $bytes))) {
                throw new ApiException('revision_integrity_failed', '主题版本文件校验失败', 409);
            }
            $contents[$path] = $bytes;
        }

        return $contents;
    }

    public function path(ThemeRevision $revision, string $path): string
    {
        $this->validatePath($path, $revision->theme_id);
        if (! array_key_exists($path, $revision->files)) {
            throw new ApiException('theme_file_not_found', '文件不在该版本清单中', 404);
        }

        return $this->storage->path('revisions/'.$revision->id.'/'.$path);
    }

    public function validatePath(string $path, string $theme): void
    {
        try {
            if (str_starts_with($path, 'resources/views/site/') && str_ends_with($path, '.blade.php')) {
                $this->storage->relative($path);

                return;
            }
            $this->guard->filePath($path, $theme);
        } catch (\RuntimeException) {
            throw new ApiException('invalid_theme_path', '文件路径不属于此主题允许的逻辑目录', 422);
        }
    }

    public function read(string $absolute, int $limit): string
    {
        $this->storage->regular($absolute);
        if (filesize($absolute) > $limit) {
            throw new ApiException('theme_file_too_large', '文件超过传输限制', 422);
        }
        $handle = @fopen($absolute, 'rb');
        if ($handle === false) {
            throw new ApiException('theme_storage_failed', '无法读取主题文件', 507);
        }
        try {
            $this->storage->assertIdentity($handle, $absolute);
            $bytes = stream_get_contents($handle, $limit + 1);
            if ($bytes === false || strlen($bytes) > $limit) {
                throw new ApiException('theme_storage_failed', '主题文件读取失败或超过限制', 507);
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    public function dependencies(): array
    {
        $files = [base_path('version.json'), base_path('routes/web.php'), app_path('Support/Site/SiteThemeViewResolver.php')];
        foreach ([resource_path('views/site'), app_path('Http/Controllers/Site')] as $directory) {
            if (is_dir($directory)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile() && ! $file->isLink()) {
                        $files[] = $file->getPathname();
                    }
                }
            }
        }
        sort($files);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[substr($file, strlen(base_path()) + 1)] = hash_file('sha256', $file);
        }

        return ['core_build' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)), 'files' => $hashes];
    }
}
