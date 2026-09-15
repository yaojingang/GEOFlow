<?php

declare(strict_types=1);

namespace GeoFlow\Distribution;

use RuntimeException;

final class StandaloneFiles
{
    public static function directory(string $path): string
    {
        if (! str_starts_with($path, '/') || is_link($path)) {
            throw new RuntimeException('Use an absolute, non-symlink directory.');
        }
        if (! is_dir($path) && ! @mkdir($path, 0700, true)) {
            throw new RuntimeException('Cannot create distribution directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve distribution directory.');
        }

        return $resolved;
    }

    public static function read(string $path, int $maximum): string
    {
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > $maximum) {
            throw new RuntimeException('Input must be a bounded regular file: '.basename($path));
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read distribution input.');
        }
        try {
            self::identity($handle, $path);
            $bytes = stream_get_contents($handle, $maximum + 1);
            if ($bytes === false || strlen($bytes) > $maximum) {
                throw new RuntimeException('Distribution input exceeds its limit.');
            }

            return $bytes;
        } finally {
            fclose($handle);
        }
    }

    public static function writeNew(string $path, string $bytes, int $mode = 0600): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot prepare distribution file: '.basename($path));
        }
        try {
            self::identity($handle, $path);
            if (! chmod($path, $mode)) {
                throw new RuntimeException('Cannot protect distribution file.');
            }
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Cannot write distribution file.');
                }
                $offset += $written;
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Distribution file was not durably written.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function replace(string $path, string $bytes, int $mode = 0600): void
    {
        self::regularOrMissing($path);
        $temporary = dirname($path).'/.geoflow-write-'.bin2hex(random_bytes(12));
        try {
            self::writeNew($temporary, $bytes, $mode);
            self::regularOrMissing($path);
            if (! rename($temporary, $path)) {
                throw new RuntimeException('Cannot activate distribution file.');
            }
            self::syncDirectory(dirname($path));
        } finally {
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
        }
    }

    public static function regularOrMissing(string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1)) {
            throw new RuntimeException('Distribution target must be a regular file with one link.');
        }
    }

    /** @return resource */
    public static function lock(string $path)
    {
        self::regularOrMissing($path);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Cannot open distribution lock.');
        }
        try {
            self::identity($handle, $path);
            if (! chmod($path, 0600) || ! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Cannot lock distribution operation.');
            }
            self::identity($handle, $path);

            return $handle;
        } catch (\Throwable $exception) {
            fclose($handle);
            throw $exception;
        }
    }

    /** @param resource $handle */
    private static function identity($handle, string $path): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $opened = fstat($handle);
        if ($stat === false || $opened === false || ($stat['mode'] & 0170000) !== 0100000
            || $stat['dev'] !== $opened['dev'] || $stat['ino'] !== $opened['ino']) {
            throw new RuntimeException('Distribution file changed while opening.');
        }
    }

    public static function syncDirectory(string $path): void
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot open directory for persistence.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Filesystem does not support durable directory updates.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function removeTree(string $path): void
    {
        if (is_link($path) || ! is_dir($path)) {
            throw new RuntimeException('Refusing to clean an unexpected distribution directory.');
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                self::removeTree($entry->getPathname());
            } elseif (! unlink($entry->getPathname())) {
                throw new RuntimeException('Cannot clean distribution file.');
            }
        }
        if (! rmdir($path)) {
            throw new RuntimeException('Cannot clean distribution directory.');
        }
    }
}
