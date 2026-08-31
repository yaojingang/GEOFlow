<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityEvidenceStrategy;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use App\Support\GeoFlow\AiQualityRetrievalResult;

class ChunkEvidenceStrategy implements ArticleAiQualityEvidenceStrategy
{
    public function __construct(private readonly ArticleAiQualityEvidenceBuilder $evidenceBuilder) {}

    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        array $options = [],
    ): AiQualityRetrievalResult {
        $result = $this->evidenceBuilder->build(
            $knowledgeBaseIds,
            $articleSnapshot,
            $factCandidates,
            (int) ($options['max_evidence'] ?? config('geoflow.ai_quality_max_evidence', 12)),
            (int) ($options['max_characters'] ?? config('geoflow.ai_quality_max_evidence_characters', 6000)),
            (int) ($options['max_fact_retrievals'] ?? config('geoflow.ai_quality_max_fact_retrievals', 6)),
            is_array($options['generation_evidence'] ?? null) ? $options['generation_evidence'] : [],
            is_array($options['serving_generations'] ?? null) ? $options['serving_generations'] : [],
        );
        $evidence = collect((array) ($result['evidence'] ?? []))
            ->map(fn (array $item): array => AiQualityRetrievalResult::normalizeEvidence(
                $item,
                AiQualityRetrievalMode::CHUNK,
                $this->version(),
                ['provider' => 'chunk'],
            ))
            ->values()
            ->all();

        return new AiQualityRetrievalResult([...$result,
            'evidence' => $evidence,
            'effective_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
            'retrieval_strategy_version' => $this->version(),
            'retrieval_meta' => array_replace(
                is_array($result['retrieval_meta'] ?? null) ? $result['retrieval_meta'] : [],
                ['path' => ['chunk']],
            ),
        ]);
    }

    public function version(): string
    {
        return 'chunk-evidence-1.1.0';
    }
}
