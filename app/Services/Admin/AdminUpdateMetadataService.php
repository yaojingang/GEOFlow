<?php

namespace App\Services\Admin;

use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;

/**
 * Checks upstream GEOFlow release metadata for admin update notifications.
 */
class AdminUpdateMetadataService
{
    private const GITHUB_REPOSITORY_URL = 'https://github.com/yaojingang/GEOFlow';

    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @return array{
     *   current_version: string,
     *   latest_version: string,
     *   latest_commit?: string,
     *   tag?: string,
     *   archive_url?: string,
     *   archive_sha256?: string,
     *   release_date?: string,
     *   release_type?: string,
     *   payload: array<string, mixed>,
     *   is_update_available: bool,
     *   is_ignored: bool,
     *   status: string,
     *   source_url: string,
     *   checked_at: string
     * }
     */
    public function fetchState(?string $currentVersion = null): array
    {
        $currentVersion = $currentVersion !== null && trim($currentVersion) !== ''
            ? trim($currentVersion)
            : $this->currentVersion();

        $defaults = [
            'current_version' => $currentVersion,
            'latest_version' => '',
            'payload' => [],
            'is_update_available' => false,
            'is_ignored' => true,
            'status' => $this->isEnabled() ? 'unavailable' : 'disabled',
            'source_url' => $this->metadataUrl(),
            'checked_at' => '',
        ];

        if (! $this->isEnabled()) {
            return $defaults;
        }

        $url = $this->metadataUrl();
        if ($url === '') {
            return $defaults;
        }

        $remote = $this->fetchRemoteMetadata($url);
        if (($remote['status'] ?? 'error') !== 'ok') {
            return array_merge($defaults, [
                'status' => 'error',
                'checked_at' => (string) ($remote['checked_at'] ?? ''),
            ]);
        }

        $json = is_array($remote['json'] ?? null) ? $remote['json'] : [];
        $latest = trim((string) ($json['version'] ?? ''));
        if ($latest === '') {
            return array_merge($defaults, [
                'status' => 'error',
                'checked_at' => (string) ($remote['checked_at'] ?? ''),
            ]);
        }

        $payload = is_array($json['payload'] ?? null) ? $json['payload'] : [];
        $isUpdateAvailable = false;
        try {
            $isUpdateAvailable = version_compare($latest, $currentVersion, '>');
        } catch (\Throwable) {
            $isUpdateAvailable = false;
        }

        return [
            'current_version' => $currentVersion,
            'latest_version' => $latest,
            'latest_commit' => trim((string) ($json['commit'] ?? '')),
            'tag' => trim((string) ($json['tag'] ?? '')),
            'archive_url' => trim((string) ($json['archive_url'] ?? '')),
            'archive_sha256' => trim((string) ($json['archive_sha256'] ?? '')),
            'release_date' => trim((string) ($json['release_date'] ?? '')),
            'release_type' => trim((string) ($json['release_type'] ?? '')),
            'payload' => $payload,
            'is_update_available' => $isUpdateAvailable,
            'is_ignored' => ! $isUpdateAvailable,
            'status' => $isUpdateAvailable ? 'available' : 'current',
            'source_url' => $url,
            'checked_at' => (string) ($remote['checked_at'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildNotificationPayload(): array
    {
        $state = $this->fetchState();
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];

        return [
            'state' => $state,
            'links' => [
                'github' => self::GITHUB_REPOSITORY_URL,
                'changelog' => [
                    'zh-CN' => $this->changelogUrl($state, 'docs/CHANGELOG.md'),
                    'en' => $this->changelogUrl($state, 'docs/CHANGELOG_en.md'),
                ],
                'release' => $this->releaseUrl($state),
            ],
            'release_notice' => $this->releaseNotice($state, $payload),
        ];
    }

    public function currentVersion(): string
    {
        return trim((string) config('geoflow.app_version', '0.0.0-dev'));
    }

    public function metadataUrl(): string
    {
        return trim((string) config('geoflow.update_metadata_url', ''));
    }

    public function isEnabled(): bool
    {
        return (bool) config('geoflow.update_check_enabled', true) && $this->metadataUrl() !== '';
    }

    public function forgetCachedMetadata(): void
    {
        $url = $this->metadataUrl();
        if ($url === '') {
            return;
        }

        Cache::forget($this->cacheKey($url));
    }

    /**
     * @return array{status: string, json?: array<string, mixed>, checked_at: string}
     */
    private function fetchRemoteMetadata(string $url): array
    {
        $ttl = max(60, (int) config('geoflow.update_metadata_cache_ttl_seconds', 86400));

        return Cache::remember($this->cacheKey($url), $ttl, function () use ($url): array {
            $checkedAt = now()->toDateTimeString();

            try {
                $request = $this->http->timeout(5)->connectTimeout(3)->acceptJson();
                $response = $this->safeHttp->get(
                    $request,
                    $url,
                    (int) config('geoflow.outbound_metadata_max_bytes', 1024 * 1024),
                );
            } catch (\Throwable) {
                return [
                    'status' => 'error',
                    'checked_at' => $checkedAt,
                ];
            }

            if (! $response->successful()) {
                return [
                    'status' => 'error',
                    'checked_at' => $checkedAt,
                ];
            }

            $json = $response->json();
            if (! is_array($json)) {
                return [
                    'status' => 'error',
                    'checked_at' => $checkedAt,
                ];
            }

            return [
                'status' => 'ok',
                'json' => $json,
                'checked_at' => $checkedAt,
            ];
        });
    }

    private function cacheKey(string $url): string
    {
        return 'geoflow:update_metadata:'.sha1($url);
    }

    /** @param array<string, mixed> $state */
    private function releaseUrl(array $state): string
    {
        $tag = $this->validatedTag($state);
        if ($tag !== null) {
            return self::GITHUB_REPOSITORY_URL.'/releases/tag/'.rawurlencode($tag);
        }

        return self::GITHUB_REPOSITORY_URL.'/releases';
    }

    /** @param array<string, mixed> $state */
    private function changelogUrl(array $state, string $path): string
    {
        $reference = $this->validatedTag($state) ?? 'main';

        return self::GITHUB_REPOSITORY_URL.'/blob/'.rawurlencode($reference).'/'.$path;
    }

    /** @param array<string, mixed> $state */
    private function validatedTag(array $state): ?string
    {
        $tag = trim((string) ($state['tag'] ?? ''));

        return preg_match('/\Av?[0-9A-Za-z][0-9A-Za-z._-]{0,127}\z/D', $tag) === 1
            ? $tag
            : null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $payload
     * @return array{
     *   available: bool,
     *   current_version: string,
     *   latest_version: string,
     *   release_date: string,
     *   release_type: string,
     *   summary: string,
     *   url: string
     * }
     */
    private function releaseNotice(array $state, array $payload): array
    {
        $locale = app()->getLocale();
        $summaryKeys = array_values(array_unique(array_filter([
            'summary_'.$locale,
            $locale === 'zh_CN' ? 'summary_zh' : null,
            'summary_en',
        ])));
        $summary = '';

        foreach ($summaryKeys as $summaryKey) {
            $candidate = trim((string) ($payload[$summaryKey] ?? ''));
            if ($candidate !== '') {
                $summary = $candidate;
                break;
            }
        }

        $releaseType = trim((string) ($state['release_type'] ?? ''));
        if (! in_array($releaseType, ['major', 'minor', 'patch', 'feature', 'fix', 'security'], true)) {
            $releaseType = '';
        }

        return [
            'available' => ! empty($state['is_update_available']),
            'current_version' => ltrim(trim((string) ($state['current_version'] ?? '')), 'vV'),
            'latest_version' => ltrim(trim((string) ($state['latest_version'] ?? '')), 'vV'),
            'release_date' => trim((string) ($state['release_date'] ?? '')),
            'release_type' => $releaseType,
            'summary' => $summary,
            'url' => $this->releaseUrl($state),
        ];
    }
}
