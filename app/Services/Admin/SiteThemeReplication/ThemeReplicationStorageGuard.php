<?php

namespace App\Services\Admin\SiteThemeReplication;

use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class ThemeReplicationStorageGuard
{
    public function __construct(private readonly ThemeReplicationPackagePathGuard $pathGuard) {}

    public function positiveInteger(mixed $value): int
    {
        return $this->pathGuard->positiveInteger($value);
    }

    public function ensureStorageDirectory(string $relativePath): string
    {
        return $this->ensureDirectoryWithinRoot(
            rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR),
            $relativePath,
        );
    }

    public function writeStorageFile(string $relativePath, string $contents): void
    {
        $this->assertSafeRelativePath($relativePath);
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '/.');
        $directoryReal = $this->ensureStorageDirectory($directory);
        $target = Storage::disk('local')->path($relativePath);
        $temporary = $directoryReal.DIRECTORY_SEPARATOR.'.'.basename($relativePath).'.'.Str::random(20).'.tmp';

        if (@lstat($target) !== false && (is_link($target) || is_dir($target))) {
            $this->reject();
        }

        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            $this->reject();
        }

        $published = false;
        try {
            try {
                $remaining = $contents;
                while ($remaining !== '') {
                    $written = fwrite($handle, $remaining);
                    if (! is_int($written) || $written < 1) {
                        $this->reject();
                    }
                    $remaining = substr($remaining, $written);
                }
                if (! fflush($handle)) {
                    $this->reject();
                }
                if (function_exists('fsync') && ! fsync($handle)) {
                    $this->reject();
                }
                $stat = fstat($handle);
                if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] !== strlen($contents)) {
                    $this->reject();
                }
            } finally {
                fclose($handle);
            }

            $this->assertDirectoryWithinRoot(
                rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR),
                $directory,
            );
            if (@lstat($target) !== false && (is_link($target) || is_dir($target))) {
                $this->reject();
            }
            if (! @rename($temporary, $target)) {
                $this->reject();
            }
            $published = true;
            $real = realpath($target);
            if (! is_string($real) || ! $this->isWithinDirectory($real, $directoryReal)) {
                $this->reject();
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            if ($published) {
                @unlink($target);
            }
            throw $exception;
        }
    }

    public function deleteStorageFile(string $relativePath): void
    {
        $this->assertSafeRelativePath($relativePath);
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '/.');
        $directoryReal = $this->assertDirectoryWithinRoot(
            rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR),
            $directory,
        );
        $absolutePath = Storage::disk('local')->path($relativePath);
        $stat = @lstat($absolutePath);
        if ($stat === false) {
            return;
        }
        if (is_link($absolutePath) || ($stat['mode'] & 0170000) !== 0100000) {
            $this->reject();
        }
        $real = realpath($absolutePath);
        if (! is_string($real) || ! $this->isWithinDirectory($real, $directoryReal) || ! @unlink($absolutePath)) {
            $this->reject();
        }
    }

    public function deleteStorageDirectory(string $relativePath): void
    {
        $this->deleteDirectoryWithinRoot(
            rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR),
            $relativePath,
        );
    }

    public function deleteFrameworkDirectory(string $relativePath): void
    {
        $this->deleteDirectoryWithinRoot(
            rtrim(storage_path('framework'), DIRECTORY_SEPARATOR),
            $relativePath,
        );
    }

    private function ensureDirectoryWithinRoot(string $rootAbsolute, string $relativePath): string
    {
        $segments = $this->segments($relativePath);
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (@lstat($current) === false && ! @mkdir($current, 0775)) {
                $this->reject();
            }
            $this->canonicalDirectory($current, $rootReal);
        }

        return $segments === [] ? $rootReal : $this->canonicalDirectory($current, $rootReal);
    }

    private function assertDirectoryWithinRoot(string $rootAbsolute, string $relativePath): string
    {
        $segments = $this->segments($relativePath);
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            $this->canonicalDirectory($current, $rootReal);
        }

        return $segments === [] ? $rootReal : $this->canonicalDirectory($current, $rootReal);
    }

    private function deleteDirectoryWithinRoot(string $rootAbsolute, string $relativePath): void
    {
        $this->assertSafeRelativePath($relativePath);
        $absolutePath = $rootAbsolute.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $this->assertExistingComponentsWithinRoot($rootAbsolute, $relativePath);
        if (@lstat($absolutePath) === false) {
            return;
        }
        $directoryReal = $this->assertDirectoryWithinRoot($rootAbsolute, $relativePath);

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directoryReal, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $itemPath = $item->getPathname();
                $stat = @lstat($itemPath);
                if ($stat === false || ! str_starts_with($itemPath, $directoryReal.DIRECTORY_SEPARATOR)) {
                    $this->reject();
                }
                if (is_link($itemPath)) {
                    if (! @unlink($itemPath)) {
                        $this->reject();
                    }

                    continue;
                }
                if ($item->isDir()) {
                    if (! @rmdir($itemPath)) {
                        $this->reject();
                    }
                } elseif ($item->isFile()) {
                    if (! @unlink($itemPath)) {
                        $this->reject();
                    }
                } else {
                    $this->reject();
                }
            }
            if (! @rmdir($directoryReal)) {
                $this->reject();
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->reject();
        }
    }

    private function assertExistingComponentsWithinRoot(string $rootAbsolute, string $relativePath): void
    {
        $rootReal = $this->canonicalDirectory($rootAbsolute);
        $current = $rootAbsolute;
        foreach ($this->segments($relativePath) as $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (@lstat($current) === false) {
                return;
            }
            $this->canonicalDirectory($current, $rootReal);
        }
    }

    /** @return list<string> */
    private function segments(string $relativePath): array
    {
        $this->assertSafeRelativePath($relativePath);

        return $relativePath === '' ? [] : explode('/', $relativePath);
    }

    private function assertSafeRelativePath(string $relativePath): void
    {
        if (
            $relativePath === ''
            || $relativePath !== trim($relativePath, '/')
            || str_contains($relativePath, '\\')
            || str_contains($relativePath, '//')
            || preg_match('/[\x00-\x1F\x7F]/', $relativePath) === 1
        ) {
            $this->reject();
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                $this->reject();
            }
        }
    }

    private function canonicalDirectory(string $absolutePath, ?string $allowedRoot = null): string
    {
        $stat = @lstat($absolutePath);
        if ($stat === false || is_link($absolutePath) || ($stat['mode'] & 0170000) !== 0040000) {
            $this->reject();
        }
        $real = realpath($absolutePath);
        if (! is_string($real) || ($allowedRoot !== null && ! $this->isWithinDirectory($real, $allowedRoot))) {
            $this->reject();
        }

        return $real;
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function reject(): never
    {
        throw new RuntimeException(__('admin.theme_replication.error.invalid_package_path'));
    }
}
