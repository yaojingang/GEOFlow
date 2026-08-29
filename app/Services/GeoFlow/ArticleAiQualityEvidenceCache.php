<?php

namespace App\Services\GeoFlow;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ArticleAiQualityEvidenceCache
{
    /**
     * @param  array<string, mixed>  $context
     * @param  Closure():array<string, mixed>  $resolver
     * @return array{value:array<string, mixed>,hit:bool,key:string}
     */
    public function remember(array $context, Closure $resolver): array
    {
        $key = 'geoflow:ai-quality:evidence:'.hash('sha256', json_encode(
            $this->canonicalize($context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
        if (! (bool) config('geoflow.ai_quality_evidence_cache_enabled', true)) {
            return ['value' => $resolver(), 'hit' => false, 'key' => $key];
        }

        $cached = Cache::get($key);
        if (is_array($cached) && is_array($cached['value'] ?? null)) {
            return ['value' => $cached['value'], 'hit' => true, 'key' => $key];
        }

        try {
            return Cache::lock($key.':lock', 30)->block(2, function () use ($key, $resolver): array {
                $cached = Cache::get($key);
                if (is_array($cached) && is_array($cached['value'] ?? null)) {
                    return ['value' => $cached['value'], 'hit' => true, 'key' => $key];
                }

                $value = $resolver();
                Cache::put(
                    $key,
                    ['value' => $value],
                    (int) config('geoflow.ai_quality_evidence_cache_ttl_seconds', 86400),
                );

                return ['value' => $value, 'hit' => false, 'key' => $key];
            });
        } catch (LockTimeoutException) {
            return ['value' => $resolver(), 'hit' => false, 'key' => $key];
        }
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
