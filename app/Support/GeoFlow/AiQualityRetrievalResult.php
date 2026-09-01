<?php

namespace App\Support\GeoFlow;

use InvalidArgumentException;

final class AiQualityRetrievalResult
{
    private const REQUIRED_PAYLOAD_KEYS = [
        'evidence',
        'fact_candidates',
        'knowledge_coverage',
        'effective_retrieval_mode',
        'retrieval_strategy_version',
        'retrieval_meta',
    ];

    private const REQUIRED_EVIDENCE_KEYS = [
        'id',
        'evidence_id',
        'knowledge_base_id',
        'content',
        'content_hash',
        'source_hash',
        'section_path',
        'source_offset_start',
        'source_offset_end',
        'retrieval_strategy',
        'retrieval_strategy_version',
        'governance_status',
        'coverage_meta',
    ];

    /** @param  array<string,mixed>  $payload */
    public function __construct(private readonly array $payload)
    {
        foreach (self::REQUIRED_PAYLOAD_KEYS as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new InvalidArgumentException('ai_quality_retrieval_result_contract_invalid');
            }
        }
        if (! is_array($payload['evidence'])
            || ! is_array($payload['fact_candidates'])
            || ! is_array($payload['retrieval_meta'])
            || ! AiQualityRetrievalMode::isValid((string) $payload['effective_retrieval_mode'])
            || trim((string) $payload['retrieval_strategy_version']) === '') {
            throw new InvalidArgumentException('ai_quality_retrieval_result_contract_invalid');
        }
        $evidenceIds = [];
        foreach ($payload['evidence'] as $evidence) {
            if (! is_array($evidence) || array_diff(self::REQUIRED_EVIDENCE_KEYS, array_keys($evidence)) !== []) {
                throw new InvalidArgumentException('ai_quality_retrieval_evidence_contract_invalid');
            }
            $evidenceId = trim((string) $evidence['id']);
            $content = (string) $evidence['content'];
            $sourceHash = (string) $evidence['source_hash'];
            $offsetStart = $evidence['source_offset_start'];
            $offsetEnd = $evidence['source_offset_end'];
            if ($evidenceId === ''
                || (string) $evidence['evidence_id'] !== (string) $evidence['id']
                || isset($evidenceIds[$evidenceId])
                || (int) $evidence['knowledge_base_id'] < 1
                || $content === ''
                || ! hash_equals(hash('sha256', $content), (string) $evidence['content_hash'])
                || $sourceHash === ''
                || mb_strlen($sourceHash, 'UTF-8') > 128
                || preg_match('/\A[A-Za-z0-9_.:-]+\z/', $sourceHash) !== 1
                || ! AiQualityRetrievalMode::isValid((string) $evidence['retrieval_strategy'])
                || trim((string) $evidence['retrieval_strategy_version']) === ''
                || trim((string) $evidence['governance_status']) === ''
                || (($offsetStart === null) !== ($offsetEnd === null))
                || ($offsetStart !== null && ((int) $offsetStart < 0 || (int) $offsetEnd <= (int) $offsetStart))
                || ! is_array($evidence['coverage_meta'])) {
                throw new InvalidArgumentException('ai_quality_retrieval_evidence_contract_invalid');
            }
            $evidenceIds[$evidenceId] = true;
        }
    }

    /**
     * @param  array<string,mixed>  $evidence
     * @param  array<string,mixed>  $coverageMeta
     * @return array<string,mixed>
     */
    public static function normalizeEvidence(
        array $evidence,
        string $strategy,
        string $strategyVersion,
        array $coverageMeta = [],
    ): array {
        $id = trim((string) ($evidence['evidence_id'] ?? $evidence['id'] ?? ''));
        $content = (string) ($evidence['content'] ?? '');
        $metadata = is_array($evidence['metadata'] ?? null) ? $evidence['metadata'] : [];
        $existingCoverage = is_array($evidence['coverage_meta'] ?? null) ? $evidence['coverage_meta'] : [];
        $sourceContentHash = trim((string) ($evidence['content_hash'] ?? ''));
        if ($sourceContentHash !== '' && ! hash_equals(hash('sha256', $content), $sourceContentHash)) {
            $existingCoverage['source_content_hash'] = $sourceContentHash;
        }

        return array_replace($evidence, [
            'id' => $id,
            'evidence_id' => $id,
            'knowledge_base_id' => (int) ($evidence['knowledge_base_id'] ?? 0),
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source_hash' => (string) ($evidence['source_hash'] ?? ''),
            'section_path' => (string) ($evidence['section_path'] ?? ''),
            'source_offset_start' => isset($evidence['source_offset_start']) ? (int) $evidence['source_offset_start'] : null,
            'source_offset_end' => isset($evidence['source_offset_end']) ? (int) $evidence['source_offset_end'] : null,
            'retrieval_strategy' => $strategy,
            'retrieval_strategy_version' => $strategyVersion,
            'governance_status' => (string) ($evidence['governance_status'] ?? $metadata['review_status'] ?? 'unreviewed'),
            'coverage_meta' => array_replace($coverageMeta, $existingCoverage),
        ]);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }
}
