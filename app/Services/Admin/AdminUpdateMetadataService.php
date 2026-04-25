<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Checks upstream GEOFlow release metadata for admin update notifications.
 */
class AdminUpdateMetadataService
{
    /**
     * @return array{
     *   current_version: string,
     *   latest_version: string,
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
                'github' => 'https://github.com/yaojingang/GEOFlow',
                'changelog' => [
                    'zh-CN' => (string) ($payload['changelog_url_zh'] ?? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG.md'),
                    'en' => (string) ($payload['changelog_url_en'] ?? 'https://github.com/yaojingang/GEOFlow/blob/main/docs/CHANGELOG_en.md'),
                ],
                'release' => (string) ($payload['release_url'] ?? 'https://github.com/yaojingang/GEOFlow'),
            ],
        ];
    }

    public function currentVersion(): string
    {
        return trim((string) config('geoflow.app_version', '1.2.0'));
    }

    public function metadataUrl(): string
    {
        return trim((string) config('geoflow.update_metadata_url', ''));
    }

    public function isEnabled(): bool
    {
        return (bool) config('geoflow.update_check_enabled', true) && $this->metadataUrl() !== '';
    }

    /**
     * @return array{status: string, json?: array<string, mixed>, checked_at: string}
     */
    private function fetchRemoteMetadata(string $url): array
    {
        $cacheKey = 'geoflow:update_metadata:'.sha1($url);
        $ttl = max(60, (int) config('geoflow.update_metadata_cache_ttl_seconds', 86400));

        return Cache::remember($cacheKey, $ttl, function () use ($url): array {
            $checkedAt = now()->toDateTimeString();

            try {
                $response = Http::timeout(5)->acceptJson()->get($url);
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
}
