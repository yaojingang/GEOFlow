<?php

namespace App\Services\Admin;

use RuntimeException;

class SystemUpdateArchiveValidator
{
    public function assertAllowedArchiveUrl(string $archiveUrl): void
    {
        $archive = $this->parseUrl($archiveUrl);
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

        $allowed = $this->parseUrl($allowedRepository);
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
    private function parseUrl(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new RuntimeException(__('admin.system_updates.error.archive_repository_mismatch'));
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
