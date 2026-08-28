<?php

namespace App\Services\GeoFlow;

class ArticleAiQualityEvidenceBuilder
{
    public function __construct(private readonly KnowledgeRetrievalService $knowledgeRetrievalService) {}

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @param  array<string, mixed>  $articleSnapshot
     * @param  list<array<string, mixed>>  $factCandidates
     * @return array{evidence:list<array<string,mixed>>,fact_candidates:list<array<string,mixed>>,knowledge_coverage:string}
     */
    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        int $maxEvidence = 24,
        int $maxCharacters = 12000,
        int $maxFactRetrievals = 60,
    ): array {
        $genericQuery = trim(implode("\n", array_filter([
            (string) ($articleSnapshot['title'] ?? ''),
            (string) ($articleSnapshot['excerpt'] ?? ''),
            mb_substr((string) ($articleSnapshot['content'] ?? ''), 0, 8000, 'UTF-8'),
        ])));
        $queries = [[
            'fact_id' => null,
            'query' => $genericQuery,
        ]];

        $factRetrievalBudget = max(0, $maxFactRetrievals);
        foreach (array_slice($factCandidates, 0, $factRetrievalBudget) as $candidate) {
            $queries[] = [
                'fact_id' => (string) ($candidate['id'] ?? ''),
                'query' => trim((string) ($candidate['quote'] ?? '')),
            ];
        }

        $evidenceByKey = [];
        $factEvidenceKeys = [];
        $attemptedFactIds = [];
        foreach ($queries as $query) {
            if (is_string($query['fact_id']) && $query['fact_id'] !== '') {
                $attemptedFactIds[$query['fact_id']] = true;
            }
            $rows = $this->knowledgeRetrievalService->retrieveEvidenceFromMany(
                $knowledgeBaseIds,
                (string) $query['query'],
                $query['fact_id'] === null ? 12 : 4,
            );

            foreach ($rows as $row) {
                $content = trim((string) ($row['content'] ?? ''));
                if ($content === '') {
                    continue;
                }

                $knowledgeBaseId = (int) ($row['knowledge_base_id'] ?? ($row['metadata']['knowledge_base_id'] ?? 0));
                $contentHash = (string) ($row['content_hash'] ?? hash('sha256', $content));
                $key = $knowledgeBaseId.':'.(int) ($row['chunk_index'] ?? 0).':'.$contentHash;
                $evidenceByKey[$key] ??= $this->boundedEvidence($row, $knowledgeBaseId, $contentHash);

                if (is_string($query['fact_id']) && $query['fact_id'] !== '') {
                    $factEvidenceKeys[$query['fact_id']][$key] = true;
                }
            }
        }

        $evidence = [];
        $keyToReference = [];
        $characterCount = 0;
        foreach ($evidenceByKey as $key => $row) {
            $contentLength = mb_strlen((string) $row['content'], 'UTF-8');
            if (count($evidence) >= max(1, $maxEvidence)
                || ($evidence !== [] && $characterCount + $contentLength > max(1000, $maxCharacters))) {
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
            $candidate['retrieval_status'] = ! isset($attemptedFactIds[$factId])
                ? 'budget_exceeded'
                : ($references === [] ? 'no_evidence' : 'evidence_found');
            $coveredFacts[] = $candidate;
        }

        return [
            'evidence' => $evidence,
            'fact_candidates' => $coveredFacts,
            'knowledge_coverage' => $this->aggregateCoverage($coveredFacts, $evidence !== [], $genericQuery !== ''),
        ];
    }

    /** @param array<string, mixed> $row */
    private function boundedEvidence(array $row, int $knowledgeBaseId, string $contentHash): array
    {
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

        return [
            'knowledge_base_id' => $knowledgeBaseId,
            'chunk_index' => (int) ($row['chunk_index'] ?? 0),
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

    /** @param list<array<string, mixed>> $factCandidates */
    private function aggregateCoverage(array $factCandidates, bool $hasEvidence, bool $hasArticleContent): string
    {
        if ($hasArticleContent && ! $hasEvidence) {
            return 'insufficient';
        }

        $material = array_values(array_filter(
            $factCandidates,
            static fn (array $candidate): bool => in_array((string) ($candidate['materiality'] ?? ''), ['high', 'medium'], true),
        ));
        if ($material === []) {
            return 'sufficient';
        }

        $statuses = array_column($material, 'coverage_status');
        if (in_array('insufficient', $statuses, true)) {
            return 'insufficient';
        }

        return in_array('partial', $statuses, true) ? 'partial' : 'sufficient';
    }
}
