<?php

namespace App\Services\Admin;

use RuntimeException;

class SystemUpdateArchiveValidator
{
    public function assertAllowedArchiveUrl(string $archiveUrl): void
    {
        $archive = $this->parseUnambiguousHttpsUrl($archiveUrl);
        $scheme = strtolower((string) ($archive['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }

        $allowedRepository = trim((string) config('geoflow.update_allowed_repository', ''), '/');
        if ($allowedRepository === '') {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }
        if (! str_contains($allowedRepository, '://')) {
            $allowedRepository = 'https://'.$allowedRepository;
        }

        $allowed = $this->parseUnambiguousHttpsUrl($allowedRepository);
        if (strtolower((string) ($allowed['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }

        $archiveHost = strtolower((string) ($archive['host'] ?? ''));
        $allowedHost = strtolower((string) ($allowed['host'] ?? ''));
        $archivePath = (string) ($archive['path'] ?? '/');
        $allowedPath = $this->normalizedRepositoryPath((string) ($allowed['path'] ?? '/'));

        $isSameRepositoryHost = $archiveHost === $allowedHost
            && $this->pathMatchesRepository($archivePath, $allowedPath);
        $isGitHubCodeload = $allowedHost === 'github.com'
            && $archiveHost === 'codeload.github.com'
            && $this->pathMatchesRepository($archivePath, $allowedPath);

        if (! $isSameRepositoryHost && ! $isGitHubCodeload) {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUnambiguousHttpsUrl(string $url): array
    {
        if (
            $url === ''
            || $url !== trim($url)
            || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || str_contains($url, '\\')
            || str_contains($url, '%')
        ) {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }

        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }

        $path = (string) ($parts['path'] ?? '/');
        if (str_contains($path, '//')) {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
        }
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
            }
        }

        return $parts;
    }

    private function normalizedRepositoryPath(string $path): string
    {
        $path = '/'.trim($path, '/');
        if ($path !== '/' && str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path === '' ? '/' : $path;
    }

    private function pathMatchesRepository(string $path, string $repositoryPath): bool
    {
        $path = '/'.trim($path, '/');
        $repositoryPath = '/'.trim($repositoryPath, '/');

        if ($repositoryPath === '/') {
            return true;
        }

        return $path === $repositoryPath || str_starts_with($path, $repositoryPath.'/');
    }
}
