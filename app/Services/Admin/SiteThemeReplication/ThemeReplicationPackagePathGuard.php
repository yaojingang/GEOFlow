<?php

namespace App\Services\Admin\SiteThemeReplication;

use RuntimeException;

class ThemeReplicationPackagePathGuard
{
    public function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX,
                ],
            ]);

            if (is_int($integer) && (string) $integer === $value) {
                return $integer;
            }
        }

        $this->reject();
    }

    public function validatedThemeId(string $themeId): string
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{1,78}[A-Za-z0-9]\z/D', $themeId)) {
            throw new RuntimeException(__('admin.theme_replication.validation.theme_id_invalid'));
        }

        return $themeId;
    }

    public function assertSafeRelativePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:/', $path)
            || preg_match('/[\x00-\x1F\x7F]/', $path)
            || preg_match('/[^\x20-\x7E]/', $path)
        ) {
            $this->reject();
        }

        foreach (explode('/', $path) as $segment) {
            if (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/\A[A-Za-z]:/', $segment)
            ) {
                $this->reject();
            }
        }
    }

    public function assertSafeArchiveEntry(string $entry, string $allowedPrefix): void
    {
        $this->assertSafeRelativePath($entry);

        $prefix = rtrim($allowedPrefix, '/').'/';
        $this->assertSafeRelativePath(rtrim($prefix, '/'));
        if ($entry === $prefix || ! str_starts_with($entry, $prefix)) {
            $this->reject();
        }
    }

    private function reject(): never
    {
        throw new RuntimeException(__('admin.theme_replication.error.invalid_package_path'));
    }
}
