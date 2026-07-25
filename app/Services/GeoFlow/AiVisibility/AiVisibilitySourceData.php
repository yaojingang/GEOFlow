<?php

namespace App\Services\GeoFlow\AiVisibility;

use Carbon\CarbonInterface;

final class AiVisibilitySourceData
{
    /**
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $citationKey = null,
        public readonly ?string $title = null,
        public readonly ?string $url = null,
        public readonly ?string $domain = null,
        public readonly ?string $siteName = null,
        public readonly ?string $snippet = null,
        public readonly ?string $summary = null,
        public readonly ?string $contentExcerpt = null,
        public readonly ?CarbonInterface $publishedAt = null,
        public readonly ?int $rank = null,
        public readonly ?float $rankScore = null,
        public readonly ?string $authorityLevel = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toDatabaseAttributes(): array
    {
        return [
            'source_type' => $this->sourceType,
            'citation_key' => $this->citationKey,
            'title' => $this->title,
            'url' => $this->url,
            'domain' => $this->domain,
            'site_name' => $this->siteName,
            'snippet' => $this->snippet,
            'summary' => $this->summary,
            'content_excerpt' => $this->contentExcerpt,
            'published_at' => $this->publishedAt?->toDateTimeString(),
            'rank' => $this->rank,
            'rank_score' => $this->rankScore,
            'authority_level' => $this->authorityLevel,
            'metadata_json' => $this->metadata !== [] ? $this->metadata : null,
        ];
    }
}
