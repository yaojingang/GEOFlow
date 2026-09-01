<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityEvidenceStrategy;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use InvalidArgumentException;

class ArticleAiQualityEvidenceStrategyResolver
{
    public function __construct(
        private readonly AtomicFirstEvidenceStrategy $atomicFirst,
        private readonly ChunkEvidenceStrategy $chunk,
        private readonly KnowledgeBroadEvidenceStrategy $knowledgeBroad,
    ) {}

    public function resolve(string $mode): ArticleAiQualityEvidenceStrategy
    {
        return match ($mode) {
            AiQualityRetrievalMode::ATOMIC_FIRST => $this->atomicFirst,
            AiQualityRetrievalMode::CHUNK => $this->chunk,
            AiQualityRetrievalMode::KNOWLEDGE_BROAD => $this->knowledgeBroad,
            default => throw new InvalidArgumentException('ai_quality_retrieval_mode_invalid'),
        };
    }
}
