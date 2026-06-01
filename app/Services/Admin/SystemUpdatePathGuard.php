<?php

namespace App\Services\Admin;

use RuntimeException;

class SystemUpdatePathGuard
{
    /**
     * Directories that must never be overwritten by the in-app updater.
     *
     * @var array<int, string>
     */
    private array $blockedPrefixes = [
        '.git/',
        '.longtask/',
        'docker-data/',
        'node_modules/',
        'public/assets/images/',
        'public/storage/',
        'storage/',
        'uploads/',
        'vendor/',
    ];

    /**
     * @var array<int, string>
     */
    private array $allowedPrefixes = [
        'app/',
        'bootstrap/',
        'config/',
        'database/',
        'docker/',
        'docs/',
        'lang/',
        'public/',
        'resources/',
        'routes/',
        'tests/',
    ];

    /**
     * @var array<int, string>
     */
    private array $allowedRootFiles = [
        '.env.example',
        '.env.prod.example',
        'artisan',
        'composer.json',
        'composer.lock',
        'docker-compose.prod.yml',
        'docker-compose.yml',
        'package-lock.json',
        'package.json',
        'phpunit.xml',
        'README.md',
        'vite.config.js',
        'version.json',
    ];

    public function normalize(string $relativePath): string
    {
        $rawPath = str_replace('\\', '/', trim($relativePath));
        $relativePath = ltrim($rawPath, '/');
        $rawSegments = explode('/', $relativePath);
        $segments = array_filter($rawSegments, static fn (string $segment): bool => $segment !== '');

        if (
            $relativePath === ''
            || str_starts_with($rawPath, '/')
            || str_contains($relativePath, '//')
            || str_ends_with($relativePath, '/')
            || str_contains($relativePath, "\0")
            || preg_match('/^[A-Za-z]:\//', $rawPath) === 1
            || count($rawSegments) !== count($segments)
            || in_array('..', $segments, true)
            || in_array('.', $segments, true)
        ) {
            throw new RuntimeException(__('admin.system_updates.error.unsafe_path'));
        }

        return implode('/', $segments);
    }

    public function isAllowedPath(string $relativePath): bool
    {
        try {
            $relativePath = $this->normalize($relativePath);
        } catch (RuntimeException) {
            return false;
        }

        $basename = basename($relativePath);

        if (str_starts_with($basename, '.env') && ! str_ends_with($basename, '.example')) {
            return false;
        }

        foreach ($this->blockedPrefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return false;
            }
        }

        if (! str_contains($relativePath, '/')) {
            return in_array($relativePath, $this->allowedRootFiles, true);
        }

        foreach ($this->allowedPrefixes as $prefix) {
            if (str_starts_with($relativePath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function assertAllowedPath(string $relativePath): string
    {
        $relativePath = $this->normalize($relativePath);
        if (! $this->isAllowedPath($relativePath)) {
            throw new RuntimeException(__('admin.system_updates.error.unsafe_path'));
        }

        return $relativePath;
    }
}
