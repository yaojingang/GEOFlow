<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityEvidenceStrategy;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use App\Support\GeoFlow\AiQualityRetrievalResult;

class AtomicFirstEvidenceStrategy implements ArticleAiQualityEvidenceStrategy
{
    public function __construct(
        private readonly ArticleAtomicFactInspector $atomicFactInspector,
        private readonly ChunkEvidenceStrategy $chunkStrategy,
        private readonly KnowledgeEvidenceSecurityInspector $securityInspector = new KnowledgeEvidenceSecurityInspector,
    ) {}

    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        array $options = [],
    ): AiQualityRetrievalResult {
        $content = $this->factualContent($articleSnapshot);
        $atomic = $this->atomicFactInspector->inspect($content, $knowledgeBaseIds);
        $fallbackContent = trim((string) ($atomic['fallback_content'] ?? ''));
        $fallbackSnapshot = array_replace($articleSnapshot, [
            'title' => '',
            'excerpt' => '',
            'content' => $fallbackContent,
            'keywords' => '',
            'meta_description' => '',
        ]);
        $supported = $this->supportedCandidates($factCandidates, (array) ($atomic['results'] ?? []));
        $atomicPromptInjectionRiskCount = collect((array) ($atomic['results'] ?? []))
            ->filter(fn (mixed $result): bool => is_array($result)
                && ($result['status'] ?? null) === 'supported'
                && $this->atomicResultHasPromptInjectionRisk($result))
            ->count();
        $fallbackFacts = collect($factCandidates)
            ->reject(static fn (array $candidate): bool => isset($supported[(string) ($candidate['id'] ?? '')]))
            ->values()
            ->all();
        $usesChunkFallback = $fallbackContent !== '' || $fallbackFacts !== [];
        $chunk = $usesChunkFallback
            ? $this->chunkStrategy->build(
                $knowledgeBaseIds,
                $fallbackSnapshot,
                $fallbackFacts,
                $options,
            )->toArray()
            : [
                'evidence' => [],
                'fact_candidates' => [],
                'knowledge_coverage' => 'sufficient',
                'generation_evidence_reused_count' => 0,
                'retrieval_meta' => [],
            ];
        $atomicEvidence = [];
        $atomicCandidates = [];
        foreach ($supported as $candidateId => $match) {
            $reference = 'A'.(count($atomicEvidence) + 1);
            $result = $match['result'];
            $atomicEvidence[] = AiQualityRetrievalResult::normalizeEvidence([
                'id' => $reference,
                'knowledge_base_id' => (int) ($result['knowledge_base_id'] ?? 0),
                'chunk_id' => 0,
                'chunk_index' => 0,
                'stable_key' => 'atomic:'.(string) ($result['stable_key'] ?? $candidateId),
                'content' => trim((string) ($result['standard_answer'] ?? $result['article_claim'] ?? '')),
                'content_hash' => hash('sha256', trim((string) ($result['standard_answer'] ?? $result['article_claim'] ?? ''))),
                'source_hash' => (string) ($result['source_hash'] ?? ''),
                'chunk_title' => (string) ($result['label'] ?? '原子事实'),
                'section_path' => 'atomic_facts',
                'metadata' => [
                    'source_type' => 'atomic_fact',
                    'review_status' => 'reviewed',
                    'fact_stable_key' => (string) ($result['stable_key'] ?? ''),
                    'revision_id' => (int) ($result['revision_id'] ?? 0),
                    'revision_version' => (int) ($result['revision_version'] ?? 0),
                    'result_hash' => hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ],
            ], AiQualityRetrievalMode::ATOMIC_FIRST, $this->version(), [
                'provider' => 'atomic',
                'fact_stable_key' => (string) ($result['stable_key'] ?? ''),
            ]);
            $atomicCandidates[$candidateId] = array_replace($match['candidate'], [
                'knowledge_refs' => [$reference],
                'coverage_status' => 'sufficient',
                'retrieval_status' => 'evidence_found',
            ]);
        }
        $chunkCandidates = collect((array) ($chunk['fact_candidates'] ?? []))
            ->keyBy(static fn (array $candidate): string => (string) ($candidate['id'] ?? ''));
        $mergedCandidates = collect($factCandidates)->map(static function (array $candidate) use ($atomicCandidates, $chunkCandidates): array {
            $id = (string) ($candidate['id'] ?? '');

            return $atomicCandidates[$id] ?? $chunkCandidates->get($id, $candidate);
        })->values()->all();
        $materialCandidates = collect($mergedCandidates)->filter(static fn (array $candidate): bool => in_array(
            (string) ($candidate['materiality'] ?? 'high'),
            ['high', 'medium'],
            true,
        ));
        $coverage = $materialCandidates->isEmpty() || $materialCandidates->every(
            static fn (array $candidate): bool => (string) ($candidate['coverage_status'] ?? '') === 'sufficient',
        ) ? 'sufficient' : 'insufficient';

        return new AiQualityRetrievalResult([...$chunk,
            'evidence' => [...$atomicEvidence, ...(array) ($chunk['evidence'] ?? [])],
            'fact_candidates' => $mergedCandidates,
            'knowledge_coverage' => $coverage,
            'effective_retrieval_mode' => AiQualityRetrievalMode::ATOMIC_FIRST,
            'retrieval_strategy_version' => $this->version(),
            'retrieval_meta' => array_replace(is_array($chunk['retrieval_meta'] ?? null) ? $chunk['retrieval_meta'] : [], [
                'path' => $usesChunkFallback ? ['atomic', 'chunk_fallback'] : ['atomic'],
                'atomic_facts' => $atomic,
                'fallback_claim_count' => count($fallbackFacts),
                'source_knowledge_base_ids' => [
                    'atomic' => array_values(array_unique(array_map(
                        'intval',
                        (array) ($atomic['knowledge_base_ids'] ?? []),
                    ))),
                    'chunk' => array_values(array_unique(array_map(
                        'intval',
                        (array) data_get($chunk, 'retrieval_meta.source_knowledge_base_ids.chunk', []),
                    ))),
                ],
                'prompt_injection_risk_count' => (int) data_get($chunk, 'retrieval_meta.prompt_injection_risk_count', 0)
                    + $atomicPromptInjectionRiskCount,
            ]),
        ]);
    }

    public function version(): string
    {
        return 'atomic-first-1.3.0';
    }

    /** @param array<string,mixed> $articleSnapshot */
    private function factualContent(array $articleSnapshot): string
    {
        return collect(['title', 'excerpt', 'content', 'keywords', 'meta_description'])
            ->map(static fn (string $field): string => trim((string) ($articleSnapshot[$field] ?? '')))
            ->filter()
            ->implode("\n");
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @param  list<array<string,mixed>>  $results
     * @return array<string,array{candidate:array<string,mixed>,result:array<string,mixed>}>
     */
    private function supportedCandidates(array $candidates, array $results): array
    {
        $atomicResults = collect($results)
            ->filter(static fn (mixed $result): bool => is_array($result))
            ->values();
        $matched = [];
        foreach ($candidates as $candidate) {
            $candidateId = trim((string) ($candidate['id'] ?? ''));
            $claim = $this->normalizeClaim((string) ($candidate['normalized_claim'] ?? $candidate['quote'] ?? ''));
            if ($candidateId === '' || $claim === '') {
                continue;
            }
            $claimResults = $atomicResults->filter(function (array $result) use ($claim): bool {
                $rawAtomicClaim = (string) ($result['article_claim'] ?? '');
                $atomicClaim = $this->normalizeClaim($rawAtomicClaim);

                return $atomicClaim !== ''
                    && ! $this->isCompositeClaim($rawAtomicClaim)
                    && ($atomicClaim === $claim || str_contains($atomicClaim, $claim));
            });
            if ($claimResults->count() !== 1
                || $claimResults->contains(static fn (array $result): bool => ($result['status'] ?? null) !== 'supported')) {
                continue;
            }
            $result = $claimResults->first();
            if (is_array($result) && $this->atomicResultHasPromptInjectionRisk($result)) {
                continue;
            }
            if (is_array($result)) {
                $matched[$candidateId] = ['candidate' => $candidate, 'result' => $result];
            }
        }

        return $matched;
    }

    private function normalizeClaim(string $claim): string
    {
        return mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]+/u', '', $claim), 'UTF-8');
    }

    private function isCompositeClaim(string $claim): bool
    {
        preg_match_all('/-?\d+(?:\.\d+)?/u', $claim, $numbers);

        return count($numbers[0] ?? []) > 1
            || preg_match('/(?:；|;|同时|并且|以及|此外|且|，.+，)/u', $claim) === 1;
    }

    /** @param array<string,mixed> $result */
    private function atomicResultHasPromptInjectionRisk(array $result): bool
    {
        return $this->securityInspector->hasPromptInjectionRisk([
            'content' => (string) ($result['standard_answer'] ?? ''),
            'chunk_title' => (string) ($result['label'] ?? ''),
            'section_path' => 'atomic_facts',
            'metadata' => [
                'fact_stable_key' => (string) ($result['stable_key'] ?? ''),
            ],
        ]);
    }
}
