<?php

namespace App\Services\GeoFlow;

use App\Support\GeoFlow\AiQualityRetrievalResult;

class ArticleAiQualityRetrievalCoordinator
{
    public function __construct(private readonly ArticleAiQualityEvidenceStrategyResolver $resolver) {}

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @param  array<string,mixed>  $articleSnapshot
     * @param  list<array<string,mixed>>  $factCandidates
     * @param  array<string,mixed>  $options
     */
    public function retrieve(
        string $mode,
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        array $options = [],
    ): AiQualityRetrievalResult {
        return $this->resolver->resolve($mode)->build(
            $knowledgeBaseIds,
            $articleSnapshot,
            $factCandidates,
            $options,
        );
    }

    public function strategyVersion(string $mode): string
    {
        return $this->resolver->resolve($mode)->version();
    }
}
