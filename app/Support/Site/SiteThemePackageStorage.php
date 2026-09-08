<?php

namespace App\Support\Site;

use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackagePathGuard;
use Closure;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class SiteThemePackageStorage
{
    public const ROOT = 'geoflow-site-themes';

    public const MAX_JSON_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly ThemeReplicationPackagePathGuard $paths) {}

    public function path(string $relative): string
    {
        $this->relative($relative);
        $root = rtrim(Storage::disk('local')->path(''), '/');
        $this->assertChain($root, false);
        $path = $root.'/'.self::ROOT.'/'.$relative;
        $this->assertChain($path, false);

        return $path;
    }

    public function directory(string $relative): string
    {
        $path = $this->path($relative);
        $this->assertChain($path, true);
        if (! is_dir($path)) {
            $this->fail('invalid_path');
        }

        return $path;
    }

    public function exclusiveDirectory(string $relative): string
    {
        $this->directory(dirname($relative));
        $path = $this->path($relative);
        if (@lstat($path) !== false || ! @mkdir($path, 0700)) {
            $this->fail('storage_failed');
        }

        return $path;
    }

    public function assertDirectory(string $path): void
    {
        $this->assertChain($path, false);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000) {
            $this->fail('invalid_path');
        }
    }

    public function regular(string $path): void
    {
        $this->assertChain($path, false);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000) {
            $this->fail('invalid_path');
        }
    }

    public function writeJson(string $relative, array $data): void
    {
        $contents = $this->encodeJson($data);
        $this->directory(dirname($relative));
        $path = $this->path($relative);
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            $this->fail('storage_failed');
        }
        try {
            $this->write($handle, $contents);
            if (! fflush($handle)) {
                $this->fail('storage_failed');
            }
        } finally {
            fclose($handle);
        }
    }

    public function encodeJson(array $data, int $maxBytes = self::MAX_JSON_BYTES): string
    {
        try {
            $contents = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            $this->fail('invalid_package');
        }
        if (strlen($contents) > $maxBytes || ! json_validate($contents)) {
            $this->fail('invalid_package');
        }

        return $contents;
    }

    public function readJson(string $relative, int $maxBytes = self::MAX_JSON_BYTES): array
    {
        $path = $this->path($relative);
        $this->regular($path);
        if (filesize($path) > $maxBytes) {
            $this->fail('invalid_package');
        }
        $contents = @file_get_contents($path);
        $data = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($data)) {
            $this->fail('invalid_package');
        }

        return $data;
    }

    /** @param resource $handle */
    public function write($handle, string $contents): void
    {
        while ($contents !== '') {
            $written = fwrite($handle, $contents);
            if (! is_int($written) || $written < 1) {
                $this->fail('storage_failed');
            }
            $contents = substr($contents, $written);
        }
    }

    public function delete(string $relative): void
    {
        if (! preg_match('#\A(?:uploads/[1-9][0-9]*/[A-Za-z0-9]{40}|exports/[1-9][0-9]*/[A-Za-z0-9]{40}|staging/[A-Za-z0-9]{40})(?:/snapshot)?\z#D', $relative)) {
            $this->fail('invalid_path');
        }
        $path = $this->path($relative);
        if (@lstat($path) === false) {
            return;
        }
        $this->deleteTree($path);
    }

    public function lock(string $key, Closure $operation, bool $wait = true): mixed
    {
        $directory = $this->directory('locks');
        $path = $directory.'/'.hash('sha256', $key).'.lock';
        $this->assertChain($path, false);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            $this->fail('storage_failed');
        }
        $deadline = hrtime(true) + max(1, min(60_000, (int) config('geoflow.theme_packages.lock_timeout_milliseconds', 5000))) * 1_000_000;
        try {
            do {
                $this->assertIdentity($handle, $path);
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    try {
                        $this->assertIdentity($handle, $path);

                        return $operation();
                    } finally {
                        flock($handle, LOCK_UN);
                    }
                }
                if (! $wait) {
                    return null;
                }
                usleep(1000);
            } while (hrtime(true) < $deadline);
        } finally {
            fclose($handle);
        }
        $this->fail('busy');
    }

    /** @param resource $handle */
    public function assertIdentity($handle, string $path): void
    {
        $this->regular($path);
        $file = lstat($path);
        $open = fstat($handle);
        if ($open === false || $file['dev'] !== $open['dev'] || $file['ino'] !== $open['ino'] || ($open['mode'] & 0170000) !== 0100000) {
            $this->fail('invalid_path');
        }
    }

    public function relative(string $path): void
    {
        try {
            $this->paths->assertSafeRelativePath($path);
        } catch (RuntimeException) {
            $this->fail('invalid_path');
        }
    }

    public function fail(string $code, array $replace = []): never
    {
        throw new RuntimeException(__('admin.theme_packages.error.'.$code, $replace));
    }

    private function assertChain(string $absolute, bool $create): void
    {
        if (! str_starts_with($absolute, '/')) {
            $this->fail('invalid_path');
        }
        $current = '';
        foreach (explode('/', ltrim($absolute, '/')) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                $this->fail('invalid_path');
            }
            $current .= '/'.$part;
            $stat = @lstat($current);
            if ($stat === false) {
                if (! $create) {
                    return;
                }
                if (! @mkdir($current, 0700) && ! is_dir($current)) {
                    $this->fail('storage_failed');
                }
                $stat = @lstat($current);
            }
            if ($stat === false || is_link($current) || ($current !== $absolute && ($stat['mode'] & 0170000) !== 0040000)) {
                $this->fail('invalid_path');
            }
        }
    }

    private function deleteTree(string $path): void
    {
        $stat = @lstat($path);
        if ($stat === false) {
            return;
        }
        if (($stat['mode'] & 0170000) === 0040000 && ! is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->deleteTree($path.'/'.$entry);
                }
            }
            if (! @rmdir($path)) {
                $this->fail('storage_failed');
            }
        } elseif (! @unlink($path)) {
            $this->fail('storage_failed');
        }
    }
}
