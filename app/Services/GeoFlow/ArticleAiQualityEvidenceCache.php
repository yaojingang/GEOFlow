<?php

namespace App\Services\GeoFlow;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ArticleAiQualityEvidenceCache
{
    private const FORMAT_VERSION = 1;

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure():array<string, mixed>  $resolver
     * @return array{value:array<string, mixed>,hit:bool,key:string}
     */
    public function remember(array $context, Closure $resolver): array
    {
        $contextHash = hash('sha256', json_encode(
            $this->canonicalize($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        $key = 'geoflow:ai-quality:evidence:'.$contextHash;
        if (! (bool) config('geoflow.ai_quality_evidence_cache_enabled', true)) {
            return ['value' => $resolver(), 'hit' => false, 'key' => $key];
        }

        $cachedValue = $this->validatedCachedValue($key, $contextHash);
        if ($cachedValue !== null) {
            return ['value' => $cachedValue, 'hit' => true, 'key' => $key];
        }

        try {
            return Cache::lock($key.':lock', 30)->block(2, function () use ($key, $contextHash, $resolver): array {
                $cachedValue = $this->validatedCachedValue($key, $contextHash);
                if ($cachedValue !== null) {
                    return ['value' => $cachedValue, 'hit' => true, 'key' => $key];
                }

                $value = $resolver();
                $envelope = $this->envelope($contextHash, $value);
                if ($envelope !== null) {
                    Cache::put(
                        $key,
                        $envelope,
                        (int) config('geoflow.ai_quality_evidence_cache_ttl_seconds', 86400),
                    );
                }

                return ['value' => $value, 'hit' => false, 'key' => $key];
            });
        } catch (LockTimeoutException) {
            return ['value' => $resolver(), 'hit' => false, 'key' => $key];
        }
    }

    /** @return array<string,mixed>|null */
    private function validatedCachedValue(string $key, string $contextHash): ?array
    {
        $cached = Cache::get($key);
        if ($cached === null) {
            return null;
        }
        if (! is_array($cached)
            || (int) ($cached['format_version'] ?? 0) !== self::FORMAT_VERSION
            || ! hash_equals($contextHash, (string) ($cached['context_hash'] ?? ''))
            || ! is_array($cached['value'] ?? null)) {
            Cache::forget($key);

            return null;
        }

        $encoded = json_encode(
            $cached['value'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $byteSize = strlen($encoded);
        $maximumBytes = max(1024, (int) config('geoflow.ai_quality_evidence_cache_max_bytes', 2_000_000));
        if ($byteSize > $maximumBytes
            || $byteSize !== (int) ($cached['byte_size'] ?? -1)
            || ! hash_equals(hash('sha256', $encoded), (string) ($cached['value_hash'] ?? ''))
            || ! $this->validValue($cached['value'])) {
            Cache::forget($key);

            return null;
        }

        return $cached['value'];
    }

    /** @param array<string,mixed> $value @return array<string,mixed>|null */
    private function envelope(string $contextHash, array $value): ?array
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $byteSize = strlen($encoded);
        $maximumBytes = max(1024, (int) config('geoflow.ai_quality_evidence_cache_max_bytes', 2_000_000));
        if ($byteSize > $maximumBytes || ! $this->validValue($value)) {
            return null;
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'context_hash' => $contextHash,
            'value_hash' => hash('sha256', $encoded),
            'byte_size' => $byteSize,
            'value' => $value,
        ];
    }

    /** @param array<string,mixed> $value */
    private function validValue(array $value): bool
    {
        if (! is_array($value['evidence'] ?? null)
            || ! is_array($value['fact_candidates'] ?? null)
            || ! is_string($value['knowledge_coverage'] ?? null)) {
            return false;
        }

        foreach ($value['evidence'] as $evidence) {
            if (! is_array($evidence)) {
                return false;
            }
            $sourceHash = (string) ($evidence['source_hash'] ?? '');
            if (trim((string) ($evidence['id'] ?? '')) === ''
                || (int) ($evidence['knowledge_base_id'] ?? 0) < 1
                || trim((string) ($evidence['stable_key'] ?? '')) === ''
                || preg_match('/\A[a-f0-9]{64}\z/', (string) ($evidence['content_hash'] ?? '')) !== 1
                || $sourceHash === ''
                || mb_strlen($sourceHash) > 128
                || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $sourceHash) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
