<?php

namespace App\Services\GeoFlow;

use App\Models\ManualPublication;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ManualPublicationDuplicateDetector
{
    public const SIMILARITY_THRESHOLD = 85.0;

    public const LOOKBACK_DAYS = 90;

    public const MAX_CANDIDATES = 50;

    public function fingerprint(string $content): string
    {
        return hash('sha256', $this->normalizeContent($content));
    }

    public function targetUrlHash(?string $targetUrl): ?string
    {
        $targetUrl = trim((string) $targetUrl);

        return $targetUrl === '' ? null : hash('sha256', $targetUrl);
    }

    /**
     * @param  array{platform:string,article_id:int|null,target_url_hash:string|null,content:string,content_fingerprint:string}  $attributes
     * @return Collection<int, ManualPublication>
     */
    public function find(array $attributes, ?int $excludeId = null): Collection
    {
        $query = ManualPublication::query()
            ->where('platform', $attributes['platform'])
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->when($excludeId !== null, fn (Builder $builder) => $builder->whereKeyNot($excludeId))
            ->latest('id')
            ->limit(self::MAX_CANDIDATES)
            ->get([
                'id',
                'article_id',
                'target_url_hash',
                'content',
                'content_fingerprint',
                'status',
                'created_at',
            ]);

        $normalizedContent = $this->normalizeContent($attributes['content']);

        return $query->filter(function (ManualPublication $candidate) use ($attributes, $normalizedContent): bool {
            if (hash_equals((string) $candidate->content_fingerprint, $attributes['content_fingerprint'])) {
                return true;
            }
            if ($attributes['target_url_hash'] !== null && hash_equals((string) $candidate->target_url_hash, $attributes['target_url_hash'])) {
                return true;
            }
            if ($attributes['article_id'] !== null && (int) $candidate->article_id === $attributes['article_id']) {
                return true;
            }

            similar_text($normalizedContent, $this->normalizeContent((string) $candidate->content), $similarity);

            return $similarity >= self::SIMILARITY_THRESHOLD;
        })->values();
    }

    private function normalizeContent(string $content): string
    {
        return Str::of(strip_tags($content))
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->lower()
            ->limit(ManualPublication::MAX_CONTENT_CHARACTERS, '')
            ->toString();
    }
}
