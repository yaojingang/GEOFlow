<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiSourceProvider;
use App\Support\GeoFlow\ApiKeyCrypto;
use RuntimeException;

final class DoubaoSearchCustomClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiVisibilityHttpClientFactory $httpClientFactory,
        private readonly AiVisibilityResultNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function search(AiSourceProvider $provider, string $query, array $options = []): AiVisibilityResult
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('豆包 Search Custom 查询词为空');
        }

        $endpoint = $this->endpoint($provider);
        $apiKey = $this->apiKey($provider);
        $payload = $this->buildPayload($query, $options);

        $startedAt = hrtime(true);
        $response = $this->httpClientFactory
            ->jsonRequest($apiKey)
            ->post($endpoint, $payload);
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                '豆包 Search Custom 请求失败：HTTP %d %s',
                $response->status(),
                trim($response->body())
            ));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('豆包 Search Custom 返回了非 JSON 结构');
        }

        return $this->normalizer->normalizeDoubaoSearchCustom($json, [
            'endpoint' => $endpoint,
            'payload' => $payload,
        ], $latencyMs);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function buildPayload(string $query, array $options): array
    {
        $legacy = ! array_key_exists('mode', $options);
        $mode = strtolower((string) ($options['mode'] ?? 'custom')) === 'global' ? 'global' : 'custom';
        $sites = $this->pipeList($options['sites'] ?? null);
        $blockHosts = $this->pipeList($options['block_hosts'] ?? null);
        $contentFormats = strtolower((string) ($options['content_formats'] ?? 'text'));
        $contentFormats = in_array($contentFormats, ['text', 'markdown'], true) ? $contentFormats : 'text';
        if ($legacy) {
            return array_filter([
                'Query' => $query,
                'SearchType' => $options['search_type'] ?? 'web',
                'Count' => max(1, min(20, (int) ($options['count'] ?? config('geoflow.ai_visibility.default_search_count', 10)))),
                'NeedSummary' => $options['need_summary'] ?? true,
                'AuthInfoLevel' => $options['auth_info_level'] ?? null,
                'Filter' => array_filter([
                    'NeedContent' => $options['need_content'] ?? true, 'NeedUrl' => $options['need_url'] ?? true,
                    'Sites' => is_array($options['sites'] ?? null) ? $options['sites'] : null,
                    'BlockHosts' => is_array($options['block_hosts'] ?? null) ? $options['block_hosts'] : null,
                    'TimeRange' => $options['time_range'] ?? null, 'ContentFormats' => $options['content_formats'] ?? 'Markdown',
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        }
        $filter = array_filter([
            'NeedContent' => $options['need_content'] ?? true,
            'NeedUrl' => $options['need_url'] ?? true,
            'Sites' => $sites !== '' ? $sites : null,
            'BlockHosts' => $blockHosts !== '' ? $blockHosts : null,
            'TimeRange' => $options['time_range'] ?? null,
            'AuthInfoLevel' => isset($options['auth_info_level']) && $options['auth_info_level'] !== '' ? (int) $options['auth_info_level'] : null,
            'Industry' => $options['industry'] ?? null,
            'ContentFormats' => $contentFormats,
            'SummaryLength' => isset($options['summary_length']) ? (int) $options['summary_length'] : null,
            'ImageCount' => isset($options['image_count']) ? (int) $options['image_count'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return array_filter([
            'Query' => $query,
            'SearchType' => $options['search_type'] ?? 'web',
            'Count' => max(1, min($mode === 'global' ? 20 : 50, (int) ($options['count'] ?? config('geoflow.ai_visibility.default_search_count', 10)))),
            'NeedSummary' => $options['need_summary'] ?? true,
            'Filter' => $filter,
            'QueryControl' => ['QueryRewrite' => (bool) ($options['query_rewrite'] ?? false)],
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function pipeList(mixed $value): string
    {
        if (is_string($value)) {
            return collect(explode('|', $value))->map(fn (string $item): string => trim($item))->filter()->unique()->implode('|');
        }
        if (! is_array($value)) {
            return '';
        }

        return collect($value)->filter(fn (mixed $item): bool => is_scalar($item))->map(fn (mixed $item): string => trim((string) $item))->filter()->unique()->implode('|');
    }

    private function endpoint(AiSourceProvider $provider): string
    {
        $endpoint = trim((string) ($provider->endpoint_url ?? ''));
        if ($endpoint === '') {
            $endpoint = trim((string) config('geoflow.ai_visibility.doubao_search_endpoint', ''));
        }

        if ($endpoint === '') {
            throw new RuntimeException('豆包 Search Custom Endpoint 为空');
        }

        return $endpoint;
    }

    private function apiKey(AiSourceProvider $provider): string
    {
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($provider->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('豆包 Search Custom API Key 为空');
        }

        return $apiKey;
    }
}
