<?php

namespace App\Services\GeoFlow;

class ArticleAiQualityEvidenceBuilder
{
    public function __construct(
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly KnowledgeEvidenceSecurityInspector $securityInspector = new KnowledgeEvidenceSecurityInspector,
    ) {}

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @param  array<string, mixed>  $articleSnapshot
     * @param  list<array<string, mixed>>  $factCandidates
     * @param  list<array<string,mixed>>  $generationEvidenceSnapshot
     * @return array{evidence:list<array<string,mixed>>,fact_candidates:list<array<string,mixed>>,knowledge_coverage:string,generation_evidence_reused_count:int}
     */
    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        int $maxEvidence = 12,
        int $maxCharacters = 6000,
        int $maxFactRetrievals = 6,
        array $generationEvidenceSnapshot = [],
        array $servingGenerations = [],
    ): array {
        $genericQuery = trim(implode("\n", array_filter([
            (string) ($articleSnapshot['title'] ?? ''),
            (string) ($articleSnapshot['excerpt'] ?? ''),
            mb_substr((string) ($articleSnapshot['content'] ?? ''), 0, 8000, 'UTF-8'),
        ])));

        $generationEvidence = $this->knowledgeRetrievalService->validateEvidenceSnapshot(
            $generationEvidenceSnapshot,
            $knowledgeBaseIds,
            $servingGenerations,
        );
        $sourceKnowledgeBaseIds = collect($generationEvidence)
            ->pluck('knowledge_base_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $evidenceByKey = [];
        foreach ($generationEvidence as $row) {
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $knowledgeBaseId = (int) ($row['knowledge_base_id'] ?? 0);
            $contentHash = (string) ($row['content_hash'] ?? hash('sha256', $content));
            $key = $this->stableEvidenceKey($row, $knowledgeBaseId, $contentHash);
            $evidenceByKey[$key] = $this->boundedEvidence($row, $knowledgeBaseId, $contentHash, $key);
        }
        $generationEvidenceKeys = array_fill_keys(array_keys($evidenceByKey), true);
        $factEvidenceKeys = [];
        $attemptedFactIds = [];

        if ($generationEvidence === [] && $genericQuery !== '') {
            $sourceKnowledgeBaseIds = array_values(array_unique(array_merge(
                $sourceKnowledgeBaseIds,
                array_map('intval', $knowledgeBaseIds),
            )));
            $rows = $this->knowledgeRetrievalService->retrieveEvidenceFromMany(
                $knowledgeBaseIds,
                $genericQuery,
                20,
                false,
                $servingGenerations,
            );
            $this->mergeEvidenceRows($evidenceByKey, $rows);
        }
        $this->mapMatchingEvidence($factCandidates, $evidenceByKey, $factEvidenceKeys);

        $factRetrievals = 0;
        foreach ($factCandidates as $candidate) {
            $factId = trim((string) ($candidate['id'] ?? ''));
            $query = trim((string) ($candidate['normalized_claim'] ?? $candidate['quote'] ?? ''));
            if ($factId === '' || $query === '' || ($factEvidenceKeys[$factId] ?? []) !== []) {
                continue;
            }
            if ($factRetrievals >= max(0, $maxFactRetrievals)) {
                continue;
            }

            $factRetrievals++;
            $attemptedFactIds[$factId] = true;
            $sourceKnowledgeBaseIds = array_values(array_unique(array_merge(
                $sourceKnowledgeBaseIds,
                array_map('intval', $knowledgeBaseIds),
            )));
            $rows = $this->knowledgeRetrievalService->retrieveEvidenceFromMany(
                $knowledgeBaseIds,
                $query,
                4,
                false,
                $servingGenerations,
            );
            $this->mergeEvidenceRows($evidenceByKey, $rows);
            $this->mapMatchingEvidence($factCandidates, $evidenceByKey, $factEvidenceKeys);
        }

        $evidence = [];
        $keyToReference = [];
        $characterCount = 0;
        $characterBudget = max(1000, $maxCharacters);
        $promptInjectionRiskCount = collect($evidenceByKey)
            ->filter(fn (array $row): bool => $this->securityInspector->hasPromptInjectionRisk($row))
            ->count();
        foreach ($evidenceByKey as $key => $row) {
            if ($this->securityInspector->hasPromptInjectionRisk($row)) {
                continue;
            }
            if (count($evidence) >= max(1, $maxEvidence) || $characterCount >= $characterBudget) {
                break;
            }

            $row['content'] = mb_substr(
                (string) $row['content'],
                0,
                $characterBudget - $characterCount,
                'UTF-8',
            );
            $contentLength = mb_strlen((string) $row['content'], 'UTF-8');
            if ($contentLength === 0) {
                continue;
            }
            $row['id'] = 'K'.(count($evidence) + 1);
            $keyToReference[$key] = $row['id'];
            $evidence[] = $row;
            $characterCount += $contentLength;
        }

        $coveredFacts = [];
        foreach ($factCandidates as $candidate) {
            $factId = (string) ($candidate['id'] ?? '');
            $references = [];
            $hasReviewedEvidence = false;
            foreach (array_keys($factEvidenceKeys[$factId] ?? []) as $key) {
                if (! isset($keyToReference[$key])) {
                    continue;
                }

                $references[] = $keyToReference[$key];
                $reviewStatus = strtolower((string) ($evidenceByKey[$key]['metadata']['review_status'] ?? 'unreviewed'));
                $hasReviewedEvidence = $hasReviewedEvidence || in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true);
            }

            $candidate['knowledge_refs'] = array_values(array_unique($references));
            $candidate['coverage_status'] = $hasReviewedEvidence
                ? 'sufficient'
                : ($references === [] ? 'insufficient' : 'partial');
            $candidate['retrieval_status'] = $references !== []
                ? 'evidence_found'
                : (isset($attemptedFactIds[$factId]) ? 'no_evidence' : 'budget_exceeded');
            $coveredFacts[] = $candidate;
        }

        return [
            'evidence' => $evidence,
            'fact_candidates' => $coveredFacts,
            'knowledge_coverage' => $this->aggregateCoverage($coveredFacts, $evidence !== [], $genericQuery !== ''),
            'generation_evidence_reused_count' => count(array_intersect_key($keyToReference, $generationEvidenceKeys)),
            'retrieval_meta' => [
                'prompt_injection_risk_count' => $promptInjectionRiskCount,
                'source_knowledge_base_ids' => ['chunk' => $sourceKnowledgeBaseIds],
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function boundedEvidence(
        array $row,
        int $knowledgeBaseId,
        string $contentHash,
        string $stableKey,
    ): array {
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        return [
            'knowledge_base_id' => $knowledgeBaseId,
            'chunk_id' => (int) ($row['chunk_id'] ?? 0),
            'chunk_index' => (int) ($row['chunk_index'] ?? 0),
            'stable_key' => $stableKey,
            'content' => mb_substr(trim((string) ($row['content'] ?? '')), 0, 4000, 'UTF-8'),
            'content_hash' => $contentHash,
            'source_hash' => (string) ($row['source_hash'] ?? ''),
            'chunk_title' => mb_substr(trim((string) ($row['chunk_title'] ?? '')), 0, 300, 'UTF-8'),
            'section_path' => mb_substr(trim((string) ($row['section_path'] ?? '')), 0, 500, 'UTF-8'),
            'metadata' => array_intersect_key($metadata, array_flip([
                'knowledge_base_id', 'knowledge_base_name', 'source_name', 'source_url', 'source_type',
                'business_line', 'effective_date', 'risk_level', 'review_status',
            ])),
        ];
    }

    /** @param array<string, mixed> $row */
    private function stableEvidenceKey(array $row, int $knowledgeBaseId, string $contentHash): string
    {
        $chunkId = (int) ($row['chunk_id'] ?? 0);
        if ($chunkId <= 0) {
            $chunkId = (int) ($row['chunk_index'] ?? 0);
        }

        return $knowledgeBaseId.':'.$chunkId.':'.$contentHash;
    }

    /** @param array<string,array<string,mixed>> $evidenceByKey @param list<array<string,mixed>> $rows */
    private function mergeEvidenceRows(array &$evidenceByKey, array $rows): void
    {
        foreach ($rows as $row) {
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $knowledgeBaseId = (int) ($row['knowledge_base_id'] ?? ($row['metadata']['knowledge_base_id'] ?? 0));
            $contentHash = (string) ($row['content_hash'] ?? hash('sha256', $content));
            $key = $this->stableEvidenceKey($row, $knowledgeBaseId, $contentHash);
            $evidenceByKey[$key] ??= $this->boundedEvidence($row, $knowledgeBaseId, $contentHash, $key);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $factCandidates
     * @param  array<string,array<string,mixed>>  $evidenceByKey
     * @param  array<string,array<string,bool>>  $factEvidenceKeys
     */
    private function mapMatchingEvidence(array $factCandidates, array $evidenceByKey, array &$factEvidenceKeys): void
    {
        foreach ($factCandidates as $candidate) {
            $factId = trim((string) ($candidate['id'] ?? ''));
            if ($factId === '') {
                continue;
            }
            foreach ($evidenceByKey as $key => $evidence) {
                if ($this->evidenceMatchesClaim($candidate, (string) ($evidence['content'] ?? ''))) {
                    $factEvidenceKeys[$factId][$key] = true;
                }
            }
        }
    }

    /** @param array<string,mixed> $candidate */
    private function evidenceMatchesClaim(array $candidate, string $evidence): bool
    {
        $claim = $this->normalizeForMatching((string) ($candidate['normalized_claim'] ?? $candidate['quote'] ?? ''));
        $evidence = $this->normalizeForMatching($evidence);
        if ($claim === '' || $evidence === '') {
            return false;
        }
        if (str_contains($evidence, $claim)) {
            return true;
        }

        preg_match_all('/\d+(?:[.,]\d+)?/u', $claim, $claimMatches);
        preg_match_all('/\d+(?:[.,]\d+)?/u', $evidence, $evidenceMatches);
        $claimNumbers = array_values(array_unique(array_map(
            static fn (string $value): string => str_replace(',', '', $value),
            $claimMatches[0] ?? [],
        )));
        $evidenceNumbers = array_values(array_unique(array_map(
            static fn (string $value): string => str_replace(',', '', $value),
            $evidenceMatches[0] ?? [],
        )));
        if ($claimNumbers !== [] && array_diff($claimNumbers, $evidenceNumbers) !== []) {
            return false;
        }

        $claimText = preg_replace('/\d+(?:[.,]\d+)?/u', '', $claim) ?? $claim;
        $evidenceText = preg_replace('/\d+(?:[.,]\d+)?/u', '', $evidence) ?? $evidence;
        $claimGrams = $this->bigrams($claimText);
        if ($claimGrams === []) {
            return $claimNumbers !== [];
        }

        $overlap = count(array_intersect($claimGrams, $this->bigrams($evidenceText))) / count($claimGrams);

        return $overlap >= ($claimNumbers === [] ? 0.45 : 0.2);
    }

    private function normalizeForMatching(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? $value;
    }

    /** @return list<string> */
    private function bigrams(string $value): array
    {
        $characters = mb_str_split($value, 1, 'UTF-8');
        if (count($characters) < 2) {
            return $characters;
        }

        $grams = [];
        for ($index = 0; $index < count($characters) - 1; $index++) {
            $grams[] = $characters[$index].$characters[$index + 1];
        }

        return array_values(array_unique($grams));
    }

    /** @param list<array<string, mixed>> $factCandidates */
    private function aggregateCoverage(array $factCandidates, bool $hasEvidence, bool $hasArticleContent): string
    {
        $material = array_values(array_filter(
            $factCandidates,
            static fn (array $candidate): bool => in_array((string) ($candidate['materiality'] ?? ''), ['high', 'medium'], true),
        ));
        if ($material === []) {
            return 'sufficient';
        }
        if ($hasArticleContent && ! $hasEvidence) {
            return 'insufficient';
        }

        $statuses = array_column($material, 'coverage_status');
        if (in_array('insufficient', $statuses, true)) {
            return 'insufficient';
        }

        return in_array('partial', $statuses, true) ? 'partial' : 'sufficient';
    }
}
