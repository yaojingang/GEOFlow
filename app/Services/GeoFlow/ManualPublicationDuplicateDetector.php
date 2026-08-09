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

    public const MAX_SIMILARITY_CANDIDATES = 50;

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
        $baseQuery = ManualPublication::query()
            ->where('platform', $attributes['platform'])
            ->where('created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->when($excludeId !== null, fn (Builder $builder) => $builder->whereKeyNot($excludeId));
        $columns = [
            'id',
            'article_id',
            'target_url_hash',
            'content',
            'content_fingerprint',
            'status',
            'created_at',
        ];
        $exactMatches = (clone $baseQuery)
            ->where(function (Builder $query) use ($attributes): void {
                $query->where('content_fingerprint', $attributes['content_fingerprint'])
                    ->when($attributes['target_url_hash'] !== null, fn (Builder $builder) => $builder
                        ->orWhere('target_url_hash', $attributes['target_url_hash']))
                    ->when($attributes['article_id'] !== null, fn (Builder $builder) => $builder
                        ->orWhere('article_id', $attributes['article_id']));
            })
            ->latest('id')
            ->get($columns);
        $similarityCandidates = (clone $baseQuery)
            ->when($exactMatches->isNotEmpty(), fn (Builder $query) => $query->whereKeyNot($exactMatches->modelKeys()))
            ->latest('id')
            ->limit(self::MAX_SIMILARITY_CANDIDATES)
            ->get($columns);

        $normalizedContent = $this->normalizeContent($attributes['content']);
        $similarMatches = $similarityCandidates->filter(function (ManualPublication $candidate) use ($normalizedContent): bool {
            similar_text($normalizedContent, $this->normalizeContent((string) $candidate->content), $similarity);

            return $similarity >= self::SIMILARITY_THRESHOLD;
        });

        return $exactMatches->concat($similarMatches)->unique('id')->values();
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
