<?php

namespace App\Contracts;

use App\Support\GeoFlow\AiQualityRetrievalResult;

interface ArticleAiQualityEvidenceStrategy
{
    /**
     * @param  list<int>  $knowledgeBaseIds
     * @param  array<string,mixed>  $articleSnapshot
     * @param  list<array<string,mixed>>  $factCandidates
     * @param  array<string,mixed>  $options
     */
    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        array $options = [],
    ): AiQualityRetrievalResult;

    public function version(): string;
}
