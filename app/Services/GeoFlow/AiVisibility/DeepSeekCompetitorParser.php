<?php

namespace App\Services\GeoFlow\AiVisibility;

final class DeepSeekCompetitorParser
{
    /** @param list<AiVisibilitySourceData> $sources @return array<string,mixed> */
    public function parse(string $text, array $sources): array
    {
        $json = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $json, $match)) {
            $json = trim($match[1]);
        }
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return ['status' => 'review_required', 'competitors' => []];
        }
        $exactSources = collect($sources)->filter(fn (AiVisibilitySourceData $source): bool => $source->url !== null)->keyBy(fn (AiVisibilitySourceData $source): string => $source->url);
        $normalizedSources = [];
        foreach ($sources as $source) {
            if ($source->url === null) {
                continue;
            }
            $key = $this->normalizeUrl($source->url);
            if ($key === null || isset($normalizedSources[$key])) {
                continue;
            }
            $normalizedSources[$key] = $source;
        }
        $competitors = [];
        foreach (($decoded['competitors'] ?? []) as $competitor) {
            if (! is_array($competitor) || trim((string) ($competitor['name'] ?? '')) === '') {
                continue;
            }
            $evidence = [];
            foreach (($competitor['evidence'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $url = trim((string) ($item['url'] ?? ''));
                $source = $exactSources->get($url) ?? $normalizedSources[$this->normalizeUrl($url)] ?? null;
                if (! $source) {
                    continue;
                }
                $evidence[] = ['source_id' => $source->citationKey, 'site_name' => $source->siteName, 'domain' => $source->domain, 'title' => $source->title, 'url' => $source->url, 'rank' => $source->rank, 'authority_level' => $source->authorityLevel, 'authority_label' => $source->metadata['authority_label'] ?? null, 'reason' => trim((string) ($item['reason'] ?? ''))];
            }
            $competitors[] = ['name' => trim((string) $competitor['name']), 'aliases' => array_values(array_filter(array_map('strval', (array) ($competitor['aliases'] ?? [])))), 'mention_count' => count($evidence), 'confidence' => is_numeric($competitor['confidence'] ?? null) ? (float) $competitor['confidence'] : null, 'verification' => $evidence === [] ? 'unverified' : 'verified', 'evidence' => $evidence];
        }

        return ['status' => 'parsed', 'competitors' => $competitors];
    }

    /** 容忍模型对 URL 的轻微改写：忽略协议、大小写、www 前缀、末尾斜杠、query 和 fragment。 */
    private function normalizeUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host.$path;
    }
}
