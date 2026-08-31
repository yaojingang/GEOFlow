<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;
use UnexpectedValueException;

class ArticleAiQualityResultValidator
{
    private const CODES = [
        'knowledge_contradiction', 'data_mismatch', 'unsupported_claim', 'citation_missing',
        'citation_scope_mismatch', 'ad_absolute_claim', 'ad_false_or_misleading',
        'ad_industry_specific', 'ad_identifiability', 'content_integrity',
        'source_declared_unverified',
    ];

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function normalizeLegacyRemovedDisclosureArtifacts(array $result): array
    {
        $issues = array_values(array_filter(
            is_array($result['issues'] ?? null) ? $result['issues'] : [],
            static fn (mixed $issue): bool => ! is_array($issue)
                || (string) ($issue['code'] ?? '') !== 'ai_generated_disclosure',
        ));
        $uncertainties = array_values(array_filter(
            is_array($result['uncertainties'] ?? null) ? $result['uncertainties'] : [],
            fn (mixed $uncertainty): bool => ! is_array($uncertainty)
                || ! $this->isRemovedDisclosureUncertainty($uncertainty),
        ));
        $result['issues'] = $issues;
        $result['uncertainties'] = $uncertainties;
        if (array_key_exists('summary', $result)) {
            $result['summary'] = $this->summary((string) $result['summary'], $issues, $uncertainties);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $article
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, mixed>  $rules
     * @param  array{start_offset?:int,end_offset?:int}|null  $segment
     * @return array<string, mixed>
     */
    public function validate(
        array $result,
        array $article,
        array $facts,
        array $evidence,
        array $rules,
        ?array $segment = null,
    ): array {
        if (array_key_exists('truncated_issue_count', $result)) {
            return $this->validateV2($result, $article, $facts, $evidence, $segment);
        }

        $this->assertExactFields(
            $result,
            ['summary', 'promotion_context', 'knowledge_coverage', 'issues', 'uncertainties'],
            'ai_quality_result',
        );
        $promotion = (string) ($result['promotion_context'] ?? '');
        $coverage = (string) ($result['knowledge_coverage'] ?? '');
        if (! in_array($promotion, ['informational', 'promotional', 'mixed', 'uncertain'], true)
            || ! in_array($coverage, ['sufficient', 'partial', 'insufficient'], true)
            || ! is_array($result['issues'] ?? null)
            || ! is_array($result['uncertainties'] ?? null)) {
            throw new UnexpectedValueException('ai_quality_result_structure_invalid');
        }

        $factIds = array_fill_keys(array_map('strval', array_column($facts, 'id')), true);
        $factsById = [];
        foreach ($facts as $fact) {
            if (is_array($fact) && trim((string) ($fact['id'] ?? '')) !== '') {
                $factsById[(string) $fact['id']] = $fact;
            }
        }
        $evidenceIds = array_fill_keys(array_map('strval', array_column($evidence, 'id')), true);
        $legalRefs = [];
        foreach ((array) ($rules['rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $legalRefs[(string) ($rule['id'] ?? '')] = true;
                $legalRefs[(string) ($rule['source'] ?? '')] = true;
                $legalRefs[(string) ($rule['title'] ?? '')] = true;
            }
        }

        $issues = [];
        foreach ($result['issues'] as $rawIssue) {
            if (! is_array($rawIssue)) {
                throw new UnexpectedValueException('ai_quality_issue_structure_invalid');
            }
            $this->assertExactFields($rawIssue, [
                'code', 'severity', 'field', 'quote', 'paragraph_index', 'heading',
                'fact_candidate_id', 'article_claim', 'evidence_value', 'knowledge_refs',
                'legal_refs', 'reason', 'suggestion',
            ], 'ai_quality_issue');

            $code = (string) ($rawIssue['code'] ?? '');
            $severity = (string) ($rawIssue['severity'] ?? '');
            $field = (string) ($rawIssue['field'] ?? '');
            $quote = Str::limit(trim((string) ($rawIssue['quote'] ?? '')), 200, '');
            if (! in_array($code, self::CODES, true)
                || ! in_array($severity, ['critical', 'high', 'medium', 'low'], true)
                || ! in_array($field, ['title', 'excerpt', 'content', 'keywords', 'meta_description'], true)
                || $quote === '') {
                throw new UnexpectedValueException('ai_quality_issue_value_invalid');
            }

            $factId = trim((string) ($rawIssue['fact_candidate_id'] ?? ''));
            $knowledgeRefs = array_values(array_unique(array_map('strval', is_array($rawIssue['knowledge_refs'] ?? null) ? $rawIssue['knowledge_refs'] : [])));
            $issueLegalRefs = array_values(array_unique(array_map('strval', is_array($rawIssue['legal_refs'] ?? null) ? $rawIssue['legal_refs'] : [])));
            $referencesValid = ($factId === '' || isset($factIds[$factId]))
                && collect($knowledgeRefs)->every(fn (string $ref): bool => isset($evidenceIds[$ref]))
                && collect($issueLegalRefs)->every(fn (string $ref): bool => isset($legalRefs[$ref]));

            $location = $this->locate(
                (string) ($article[$field] ?? ''),
                $quote,
                $field === 'content' ? $segment : null,
            );
            if (in_array($code, ['knowledge_contradiction', 'data_mismatch'], true) && $knowledgeRefs !== []) {
                $severity = $this->atLeast($severity, 'high');
            }
            $fact = $factsById[$factId] ?? null;
            if ($referencesValid
                && in_array($code, ['knowledge_contradiction', 'data_mismatch'], true)
                && is_array($fact)
                && ($fact['materiality'] ?? null) === 'high'
                && in_array((string) ($fact['type'] ?? ''), ['amount', 'percentage', 'guarantee'], true)) {
                $severity = 'critical';
            }
            if ($code === 'ad_false_or_misleading' && $severity === 'low') {
                $severity = 'medium';
            }

            $issues[] = [
                'code' => $code,
                'code_family' => $this->codeFamily($code, $field),
                'severity' => $severity,
                'field' => $field,
                'quote' => $quote,
                'paragraph_index' => $field === 'content' ? $this->paragraphIndex((string) ($article[$field] ?? ''), $location['start_offset']) : 0,
                'heading' => Str::limit(trim((string) ($rawIssue['heading'] ?? '')), 200, ''),
                'fact_candidate_id' => $factId,
                'article_claim' => Str::limit(trim((string) ($rawIssue['article_claim'] ?? '')), 500, ''),
                'evidence_value' => Str::limit(trim((string) ($rawIssue['evidence_value'] ?? '')), 1000, ''),
                'knowledge_refs' => $knowledgeRefs,
                'legal_refs' => $issueLegalRefs,
                'reason' => Str::limit(trim((string) ($rawIssue['reason'] ?? '')), 2000, ''),
                'suggestion' => Str::limit(trim((string) ($rawIssue['suggestion'] ?? '')), 2000, ''),
                'location_status' => $location['status'],
                'start_offset' => $location['start_offset'],
                'end_offset' => $location['end_offset'],
                'references_valid' => $referencesValid,
            ];
        }

        $uncertainties = $this->uncertainties($result['uncertainties']);

        return [
            'summary' => $this->summary((string) ($result['summary'] ?? ''), $issues, $uncertainties),
            'promotion_context' => $promotion,
            'knowledge_coverage' => $coverage,
            'issues' => $issues,
            'uncertainties' => $uncertainties,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $article
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $evidence
     * @param  array{start_offset?:int,end_offset?:int}|null  $segment
     * @return array<string, mixed>
     */
    private function validateV2(
        array $result,
        array $article,
        array $facts,
        array $evidence,
        ?array $segment,
    ): array {
        $this->assertExactFields(
            $result,
            ['summary', 'promotion_context', 'reviewed_claim_hashes', 'issues', 'uncertainties', 'truncated_issue_count'],
            'ai_quality_result',
        );
        $promotion = (string) ($result['promotion_context'] ?? '');
        if (! in_array($promotion, ['informational', 'promotional', 'mixed', 'uncertain'], true)
            || ! is_array($result['issues'] ?? null)
            || ! is_array($result['uncertainties'] ?? null)
            || ! is_array($result['reviewed_claim_hashes'] ?? null)
            || ! is_int($result['truncated_issue_count'] ?? null)
            || (int) $result['truncated_issue_count'] < 0
            || (int) $result['truncated_issue_count'] > 65535) {
            throw new UnexpectedValueException('ai_quality_result_structure_invalid');
        }

        $factsByHash = [];
        foreach ($facts as $fact) {
            $claimHash = trim((string) ($fact['claim_hash'] ?? ''));
            if ($claimHash !== '') {
                $factsByHash[$claimHash] = $fact;
            }
        }
        $evidenceByKey = [];
        $evidenceReferenceMap = [];
        foreach ($evidence as $item) {
            $stableKey = trim((string) ($item['stable_key'] ?? ''));
            if ($stableKey !== '') {
                $evidenceByKey[$stableKey] = $item;
                $evidenceReferenceMap[$stableKey] = $stableKey;
                $evidenceId = trim((string) ($item['id'] ?? ''));
                if ($evidenceId !== '') {
                    $evidenceReferenceMap[$evidenceId] = $stableKey;
                }
            }
        }

        $modelReviewedClaimHashes = array_values(array_map(
            static fn (mixed $hash): string => is_scalar($hash) ? trim((string) $hash) : '',
            $result['reviewed_claim_hashes'],
        ));
        if (in_array('', $modelReviewedClaimHashes, true)
            || count($modelReviewedClaimHashes) !== count(array_unique($modelReviewedClaimHashes))
            || collect($modelReviewedClaimHashes)->contains(
                static fn (string $hash): bool => ! isset($factsByHash[$hash]),
            )) {
            throw new UnexpectedValueException('ai_quality_reviewed_claim_hashes_invalid');
        }
        $reviewedClaimHashes = [];
        foreach ($modelReviewedClaimHashes as $claimHash) {
            $fact = $factsByHash[$claimHash];
            $references = array_values(array_unique(array_map(
                'strval',
                is_array($fact['knowledge_refs'] ?? null) ? $fact['knowledge_refs'] : [],
            )));
            if ($references !== [] && collect($references)->every(
                static fn (string $reference): bool => isset($evidenceReferenceMap[$reference]),
            )) {
                $reviewedClaimHashes[] = $claimHash;
            }
        }
        $reviewedClaimLookup = array_fill_keys($reviewedClaimHashes, true);

        $issues = [];
        $generatedUncertainties = [];
        $unverifiedClaimLookup = [];
        foreach ($result['issues'] as $rawIssue) {
            if (! is_array($rawIssue)) {
                throw new UnexpectedValueException('ai_quality_issue_structure_invalid');
            }
            $this->assertExactFields($rawIssue, [
                'code', 'severity', 'claim_hash', 'field', 'quote', 'evidence_keys',
                'evidence_status', 'reason', 'suggestion', 'confidence',
            ], 'ai_quality_issue');

            $code = (string) ($rawIssue['code'] ?? '');
            $severity = (string) ($rawIssue['severity'] ?? '');
            $claimHash = trim((string) ($rawIssue['claim_hash'] ?? ''));
            $field = (string) ($rawIssue['field'] ?? '');
            $quote = Str::limit(trim((string) ($rawIssue['quote'] ?? '')), 200, '');
            $evidenceStatus = (string) ($rawIssue['evidence_status'] ?? '');
            $confidence = (float) ($rawIssue['confidence'] ?? -1);
            if (! in_array($code, self::CODES, true)
                || ! in_array($severity, ['critical', 'high', 'medium', 'low'], true)
                || ! in_array($field, ['title', 'excerpt', 'content', 'keywords', 'meta_description'], true)
                || ! in_array($evidenceStatus, ['supported', 'contradicted', 'unverified'], true)
                || ! is_array($rawIssue['evidence_keys'] ?? null)
                || $quote === ''
                || $confidence < 0
                || $confidence > 1) {
                throw new UnexpectedValueException('ai_quality_issue_value_invalid');
            }

            $fact = $factsByHash[$claimHash] ?? null;
            if ($evidenceStatus === 'unverified') {
                if ($claimHash !== '') {
                    $unverifiedClaimLookup[$claimHash] = true;
                }
                $generatedUncertainties[] = [
                    'claim' => Str::limit(trim((string) ($fact['normalized_claim'] ?? $quote)), 500, ''),
                    'materiality' => in_array((string) ($fact['materiality'] ?? ''), ['high', 'medium', 'low'], true)
                        ? (string) $fact['materiality']
                        : 'medium',
                    'reason' => Str::limit(trim((string) ($rawIssue['reason'] ?? '')), 1000, ''),
                    'needed_evidence' => Str::limit(trim((string) ($rawIssue['suggestion'] ?? '')), 1000, ''),
                    'gate_reason' => 'unverified_material_claim',
                ];

                continue;
            }

            $evidenceKeys = array_values(array_unique(array_map(
                'strval',
                array_filter($rawIssue['evidence_keys'], static fn (mixed $key): bool => is_scalar($key)),
            )));
            $stableEvidenceKeys = array_values(array_unique(array_filter(array_map(
                static fn (string $key): ?string => $evidenceReferenceMap[$key] ?? null,
                $evidenceKeys,
            ))));
            if ($code === 'citation_scope_mismatch'
                && $evidenceStatus === 'supported'
                && $stableEvidenceKeys === []) {
                continue;
            }
            $requiresEvidence = in_array($code, [
                'knowledge_contradiction', 'data_mismatch', 'unsupported_claim',
                'citation_missing', 'citation_scope_mismatch', 'source_declared_unverified',
            ], true);
            $referencesValid = ! $requiresEvidence || ($stableEvidenceKeys !== []
                && count($stableEvidenceKeys) === count($evidenceKeys)
                && collect($stableEvidenceKeys)->every(static fn (string $key): bool => isset($evidenceByKey[$key])));
            $location = $this->locate(
                (string) ($article[$field] ?? ''),
                $quote,
                $field === 'content' ? $segment : null,
            );

            if ($severity === 'critical'
                && in_array($code, ['data_mismatch', 'knowledge_contradiction'], true)
                && ! $this->confirmedNumericConflict($fact, $stableEvidenceKeys, $evidenceByKey)) {
                $severity = 'high';
            }

            $issues[] = [
                'code' => $code,
                'code_family' => $this->codeFamily($code, $field),
                'severity' => $severity,
                'claim_hash' => $claimHash,
                'field' => $field,
                'quote' => $quote,
                'evidence_keys' => $stableEvidenceKeys,
                'knowledge_refs' => $stableEvidenceKeys,
                'evidence_status' => $evidenceStatus,
                'reason' => Str::limit(trim((string) ($rawIssue['reason'] ?? '')), 2000, ''),
                'suggestion' => Str::limit(trim((string) ($rawIssue['suggestion'] ?? '')), 2000, ''),
                'confidence' => $confidence,
                'location_status' => $location['status'],
                'start_offset' => $location['start_offset'],
                'end_offset' => $location['end_offset'],
                'paragraph_index' => $field === 'content'
                    ? $this->paragraphIndex((string) ($article[$field] ?? ''), $location['start_offset'])
                    : 0,
                'references_valid' => $referencesValid,
            ];
        }

        foreach ($factsByHash as $claimHash => $fact) {
            if (($fact['materiality'] ?? null) !== 'high'
                || isset($reviewedClaimLookup[$claimHash])
                || isset($unverifiedClaimLookup[$claimHash])) {
                continue;
            }

            $generatedUncertainties[] = [
                'claim' => Str::limit(trim((string) ($fact['normalized_claim'] ?? $fact['quote'] ?? '')), 500, ''),
                'materiality' => 'high',
                'reason' => '模型结果未确认该关键事实已经完成核查。',
                'needed_evidence' => '重新质检或由人工核验该关键事实与现有证据。',
                'gate_reason' => 'claim_coverage_incomplete',
            ];
        }

        $uncertainties = array_values(array_merge(
            $this->uncertainties($result['uncertainties']),
            $generatedUncertainties,
        ));

        return [
            'summary' => $this->summary((string) ($result['summary'] ?? ''), $issues, $uncertainties),
            'promotion_context' => $promotion,
            'knowledge_coverage' => $evidence === [] ? 'insufficient' : 'partial',
            'issues' => $issues,
            'uncertainties' => $uncertainties,
            'reviewed_claim_hashes' => $reviewedClaimHashes,
            'truncated_issue_count' => max(0, min(65535, (int) $result['truncated_issue_count'])),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fact
     * @param  list<string>  $evidenceKeys
     * @param  array<string, array<string, mixed>>  $evidenceByKey
     */
    private function confirmedNumericConflict(?array $fact, array $evidenceKeys, array $evidenceByKey): bool
    {
        $claim = (string) ($fact['normalized_claim'] ?? $fact['quote'] ?? '');
        preg_match_all('/\d+(?:[,.]\d+)?/u', $claim, $claimMatches);
        $claimNumbers = array_map(static fn (string $value): string => str_replace(',', '', $value), $claimMatches[0] ?? []);
        if ($claimNumbers === []) {
            return false;
        }

        foreach ($evidenceKeys as $key) {
            preg_match_all('/\d+(?:[,.]\d+)?/u', (string) ($evidenceByKey[$key]['content'] ?? ''), $evidenceMatches);
            $evidenceNumbers = array_map(static fn (string $value): string => str_replace(',', '', $value), $evidenceMatches[0] ?? []);
            if ($evidenceNumbers !== [] && array_intersect($claimNumbers, $evidenceNumbers) === []) {
                return true;
            }
        }

        return false;
    }

    /** @return array{status:string,start_offset:?int,end_offset:?int} */
    private function locate(string $text, string $quote, ?array $segment = null): array
    {
        $textLength = mb_strlen($text, 'UTF-8');
        $rangeStart = max(0, min($textLength, (int) ($segment['start_offset'] ?? 0)));
        $rangeEnd = max($rangeStart, min($textLength, (int) ($segment['end_offset'] ?? $textLength)));
        $searchText = mb_substr($text, $rangeStart, $rangeEnd - $rangeStart, 'UTF-8');
        $relativeOffset = mb_strpos($searchText, $quote, 0, 'UTF-8');
        if ($relativeOffset === false) {
            return ['status' => 'unresolved', 'start_offset' => null, 'end_offset' => null];
        }

        $offset = $rangeStart + $relativeOffset;
        $next = mb_strpos(
            $searchText,
            $quote,
            $relativeOffset + max(1, mb_strlen($quote, 'UTF-8')),
            'UTF-8',
        );
        if ($next !== false) {
            return ['status' => 'unresolved', 'start_offset' => null, 'end_offset' => null];
        }

        return ['status' => 'resolved', 'start_offset' => $offset, 'end_offset' => $offset + mb_strlen($quote, 'UTF-8')];
    }

    private function paragraphIndex(string $text, ?int $offset): int
    {
        if ($offset === null) {
            return 0;
        }

        $prefix = mb_substr($text, 0, $offset, 'UTF-8');
        $paragraphs = preg_split('/\n\s*\n/u', $prefix) ?: [];

        return max(1, count($paragraphs));
    }

    private function atLeast(string $severity, string $minimum): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return ($rank[$severity] ?? 0) >= $rank[$minimum] ? $severity : $minimum;
    }

    private function codeFamily(string $code, string $field): string
    {
        if ($code === 'content_integrity' && in_array($field, ['excerpt', 'meta_description'], true)) {
            return 'seo_truncation';
        }

        return $code;
    }

    /** @param array<int, mixed> $items */
    private function uncertainties(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException('ai_quality_uncertainty_structure_invalid');
            }
            $this->assertExactFields(
                $item,
                ['claim', 'materiality', 'reason', 'needed_evidence'],
                'ai_quality_uncertainty',
            );

            $materiality = (string) ($item['materiality'] ?? '');
            if (! in_array($materiality, ['high', 'medium', 'low'], true)) {
                throw new UnexpectedValueException('ai_quality_uncertainty_materiality_invalid');
            }
            $normalized = [
                'claim' => Str::limit(trim((string) ($item['claim'] ?? $item['subject'] ?? '')), 500, ''),
                'materiality' => $materiality,
                'reason' => Str::limit(trim((string) ($item['reason'] ?? '')), 1000, ''),
                'needed_evidence' => Str::limit(trim((string) ($item['needed_evidence'] ?? '')), 1000, ''),
            ];
            if ($this->isRemovedDisclosureUncertainty($normalized)) {
                continue;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $uncertainties */
    private function summary(string $summary, array $issues, array $uncertainties): string
    {
        $summary = Str::limit(trim($summary), 2000, '');
        if (! $this->summaryMentionsRemovedDisclosureRule($summary)) {
            return $summary;
        }
        if ($issues === [] && $uncertainties === []) {
            return '已完成当前启用规则的质检。';
        }

        return sprintf('发现 %d 项问题和 %d 项需要核验的事项。', count($issues), count($uncertainties));
    }

    /** @param array<string,mixed> $uncertainty */
    private function isRemovedDisclosureUncertainty(array $uncertainty): bool
    {
        $claim = preg_replace('/\s+/u', '', (string) ($uncertainty['claim'] ?? '')) ?? '';

        return preg_match(
            '/^(?:(?:当前文章|本文|文章|发布渠道|发布元数据)(?:的|中|中的)?){0,1}'
                .'(?:AI|人工智能)(?:生成|合成)(?:内容)?(?:发布)?(?:标识|声明|披露|标注)(?:状态|是否已(?:声明|标识|披露|标注)|待确认|未确认)?$/u',
            $claim,
        ) === 1;
    }

    private function summaryMentionsRemovedDisclosureRule(string $text): bool
    {
        return preg_match(
            '/(?:当前文章|本文|文章|发布元数据|发布渠道).{0,16}(?:缺少|未提供|未声明|未标识|未披露).{0,16}(?:AI|人工智能).{0,12}(?:生成|合成).{0,16}(?:标识|声明|披露|标注)'
                .'|(?:当前文章|本文|文章|发布元数据|发布渠道).{0,16}(?:AI|人工智能).{0,12}(?:生成|合成).{0,16}(?:标识|声明|披露|标注).{0,8}(?:状态|缺失|待确认|未确认)'
                .'|(?:发布元数据|发布渠道).{0,20}(?:AI|人工智能).{0,12}(?:生成|合成).{0,16}(?:标识|声明|披露|标注)'
                .'|补充.{0,12}(?:发布元数据|发布渠道).{0,20}(?:AI|人工智能).{0,12}(?:生成|合成).{0,16}(?:标识|声明|披露|标注)/iu',
            $text,
        ) === 1;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function assertExactFields(array $value, array $allowed, string $prefix): void
    {
        $keys = array_keys($value);
        if (array_diff($keys, $allowed) !== []) {
            throw new UnexpectedValueException($prefix.'_unknown_field');
        }
        if (array_diff($allowed, $keys) !== []) {
            throw new UnexpectedValueException($prefix.'_missing_field');
        }
    }
}
