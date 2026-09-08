<?php

namespace App\Support\Site;

use App\Services\Admin\SiteThemePackageGuard;
use ErrorException;
use RuntimeException;

final class InstalledSiteThemeRepository
{
    public function __construct(
        private readonly SiteThemePackageStorage $storage,
        private readonly SiteThemePackageGuard $guard,
    ) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        try {
            $root = $this->storage->path('installed');
            if (! is_dir($root)) {
                return [];
            }
            $themes = [];
            foreach (scandir($root) ?: [] as $id) {
                if ($id === '.' || $id === '..') {
                    continue;
                }
                $theme = $this->find($id);
                if ($theme !== null) {
                    $themes[] = $theme;
                }
            }
            usort($themes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $themes;
        } catch (RuntimeException|ErrorException) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        try {
            $this->guard->themeId($id);
            $root = 'installed/'.$id;
            if (! is_file($this->storage->path($root.'/installation.json'))) {
                return null;
            }
            $receipt = $this->storage->readJson($root.'/installation.json');
            $package = $receipt['package'] ?? null;
            $this->guard->package($package, checkCompatibility: false, checkLimits: false);
            if ($package['theme']['id'] !== $id || ($receipt['content_sha256'] ?? null) !== $package['content_sha256'] || ! is_string($receipt['installed_at'] ?? null)) {
                return null;
            }
            foreach ($package['files'] as $file) {
                $filePath = $this->storage->path($root.'/'.$file['path']);
                $this->storage->regular($filePath);
                if (filesize($filePath) !== $file['bytes']) {
                    return null;
                }
            }
            $manifest = [];
            foreach ($package['files'] as $file) {
                if ($file['path'] === 'resources/views/theme/'.$id.'/manifest.json') {
                    $manifestPath = $this->storage->path($root.'/'.$file['path']);
                    $this->storage->regular($manifestPath);
                    if (filesize($manifestPath) !== $file['bytes'] || ! hash_equals($file['sha256'], (string) hash_file('sha256', $manifestPath))) {
                        return null;
                    }
                    $manifest = $this->guard->manifest($root, $id, maxBytes: $file['bytes']);
                    break;
                }
            }
            $viewsPath = $this->storage->path($root.'/resources/views/theme/'.$id);
            if (! is_dir($viewsPath)) {
                return null;
            }

            return [
                'id' => $id,
                'name' => $package['theme']['name'],
                'version' => $package['theme']['version'],
                'description' => $manifest['description'] ?? '',
                'source' => 'installed',
                'manifest' => $manifest,
                'package' => $package,
                'content_sha256' => $package['content_sha256'],
                'views_path' => $viewsPath,
                'view_root' => $this->storage->path($root.'/resources/views'),
                'assets_path' => $this->storage->path($root.'/public/themes/'.$id),
                'installed_at' => $receipt['installed_at'],
            ];
        } catch (RuntimeException|ErrorException) {
            return null;
        }
    }

    /** @return array{path:string,mime:string,etag:string}|null */
    public function asset(string $id, string $relative): ?array
    {
        try {
            $this->storage->relative($relative);
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (! isset(SiteThemePackageGuard::ASSET_MIMES[$extension])) {
                return null;
            }
            $theme = $this->find($id);
            if ($theme === null) {
                return null;
            }
            $logical = 'public/themes/'.$id.'/'.$relative;
            $this->guard->filePath($logical, $id);
            $record = null;
            foreach ($theme['package']['files'] as $file) {
                if ($file['path'] === $logical) {
                    $record = $file;
                    break;
                }
            }
            if ($record === null) {
                return null;
            }
            $path = $this->storage->path('installed/'.$id.'/'.$logical);
            $this->storage->regular($path);
            if (filesize($path) !== $record['bytes'] || ! hash_equals($record['sha256'], (string) hash_file('sha256', $path))) {
                return null;
            }

            return ['path' => $path, 'mime' => SiteThemePackageGuard::ASSET_MIMES[$extension], 'etag' => $record['sha256']];
        } catch (RuntimeException|ErrorException) {
            return null;
        }
    }
}
