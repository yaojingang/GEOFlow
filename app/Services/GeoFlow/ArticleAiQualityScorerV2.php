<?php

namespace App\Services\GeoFlow;

use InvalidArgumentException;

final class ArticleAiQualityScorerV2
{
    private const DIMENSION_MAXIMUMS = [
        'knowledge_consistency' => 35,
        'data_traceability' => 25,
        'advertising_compliance' => 30,
        'content_integrity' => 10,
    ];

    private const CODE_DIMENSIONS = [
        'knowledge_contradiction' => 'knowledge_consistency',
        'unsupported_claim' => 'knowledge_consistency',
        'data_mismatch' => 'data_traceability',
        'citation_missing' => 'data_traceability',
        'citation_scope_mismatch' => 'data_traceability',
        'source_declared_unverified' => 'data_traceability',
        'ad_absolute_claim' => 'advertising_compliance',
        'ad_false_or_misleading' => 'advertising_compliance',
        'ad_industry_specific' => 'advertising_compliance',
        'ad_identifiability' => 'advertising_compliance',
        'content_integrity' => 'content_integrity',
    ];

    private const DEDUCTIONS = [
        'knowledge_consistency' => ['critical' => 20, 'high' => 10, 'medium' => 5, 'low' => 2],
        'data_traceability' => ['critical' => 15, 'high' => 8, 'medium' => 4, 'low' => 1],
        'advertising_compliance' => ['critical' => 20, 'high' => 12, 'medium' => 6, 'low' => 2],
        'content_integrity' => ['critical' => 10, 'high' => 5, 'medium' => 3, 'low' => 1],
    ];

    /**
     * @param  array<string, mixed>  $modelResult
     * @return array<string, mixed>
     */
    public function score(array $modelResult, int $passScore = 85, int $manualOverrideMinScore = 70): array
    {
        if ($manualOverrideMinScore < 0 || $manualOverrideMinScore >= $passScore || $passScore > 100) {
            throw new InvalidArgumentException('AI quality thresholds are invalid.');
        }

        $issues = $this->groupIssues(is_array($modelResult['issues'] ?? null) ? $modelResult['issues'] : []);
        $uncertainties = array_values(array_filter(
            is_array($modelResult['uncertainties'] ?? null) ? $modelResult['uncertainties'] : [],
            'is_array',
        ));
        $dimensionScores = self::DIMENSION_MAXIMUMS;
        $categoryDeductions = [];
        $knowledgeClaimDeductions = [];
        $hardBlocker = false;
        $hasHighSeverityIssue = false;
        $gateReasons = [];

        foreach ($issues as &$issue) {
            $code = (string) ($issue['code'] ?? 'content_integrity');
            $severity = (string) ($issue['severity'] ?? 'medium');
            $dimension = self::CODE_DIMENSIONS[$code] ?? 'content_integrity';
            $hardBlocker = $hardBlocker || $severity === 'critical' || ($issue['hard_blocker'] ?? false) === true;
            $hasHighSeverityIssue = $hasHighSeverityIssue || $severity === 'high';

            if (($issue['evidence_status'] ?? null) === 'unverified') {
                $issue['deduction'] = 0;
                $gateReasons[] = 'unverified_material_claim';

                continue;
            }

            $deduction = self::DEDUCTIONS[$dimension][$severity] ?? self::DEDUCTIONS[$dimension]['medium'];
            $category = $this->cappedCategory($issue, $dimension);
            if ($category !== null) {
                $cap = $category === 'citation_format_or_source' ? 4 : 3;
                $used = (int) ($categoryDeductions[$category] ?? 0);
                $deduction = min($deduction, max(0, $cap - $used));
                $categoryDeductions[$category] = $used + $deduction;
            }
            if ($dimension === 'knowledge_consistency') {
                $claimKey = (string) ($issue['claim_hash'] ?? $issue['root_cause_key'] ?? 'unknown');
                $used = (int) ($knowledgeClaimDeductions[$claimKey] ?? 0);
                $deduction = min($deduction, max(0, 10 - $used));
                $knowledgeClaimDeductions[$claimKey] = $used + $deduction;
            }

            $issue['deduction'] = $deduction;
            $dimensionScores[$dimension] = max(0, $dimensionScores[$dimension] - $deduction);
        }
        unset($issue);

        $score = array_sum($dimensionScores);
        $coverage = (string) ($modelResult['knowledge_coverage'] ?? 'insufficient');
        if (in_array($coverage, ['partial', 'insufficient'], true)) {
            $gateReasons[] = 'evidence_coverage_'.$coverage;
        }
        if ($this->hasHighMaterialityUncertainty($uncertainties)) {
            $gateReasons[] = 'high_materiality_uncertainty';
        }
        foreach ($uncertainties as $uncertainty) {
            $gateReason = (string) ($uncertainty['gate_reason'] ?? '');
            if (in_array($gateReason, ['unverified_material_claim', 'claim_coverage_incomplete'], true)) {
                $gateReasons[] = $gateReason;
            }
        }
        if ((int) ($modelResult['truncated_issue_count'] ?? 0) > 0) {
            $gateReasons[] = 'model_output_truncated';
        }
        if ($this->hasUnresolvedReference($issues)) {
            $gateReasons[] = 'unresolved_reference';
        }
        if ($hardBlocker) {
            $gateReasons[] = 'confirmed_hard_blocker';
        }
        if ($hasHighSeverityIssue) {
            $gateReasons[] = 'confirmed_high_severity_issue';
        }

        $gateReasons = array_values(array_unique($gateReasons));
        $decision = match (true) {
            $hardBlocker, $score < $manualOverrideMinScore => 'blocked',
            $score < $passScore, $gateReasons !== [] => 'needs_review',
            default => 'passed',
        };

        return [
            'score' => $score,
            'dimension_scores' => $dimensionScores,
            'decision' => $decision,
            'issues' => $issues,
            'uncertainties' => $uncertainties,
            'confidence' => $this->confidence($issues),
            'evidence_coverage' => $coverage,
            'gate_reasons' => $gateReasons,
            'scoring_version' => 'v2',
        ];
    }

    /** @param array<int, mixed> $issues @return list<array<string, mixed>> */
    private function groupIssues(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            if (! array_key_exists((string) ($issue['code'] ?? ''), self::CODE_DIMENSIONS)) {
                throw new InvalidArgumentException('AI quality issue code is invalid.');
            }
            $rootKey = $this->rootCauseKey($issue);
            $occurrence = [
                'field' => (string) ($issue['field'] ?? ''),
                'quote' => (string) ($issue['quote'] ?? ''),
                'start_offset' => $issue['start_offset'] ?? null,
                'end_offset' => $issue['end_offset'] ?? null,
            ];
            if (! isset($groups[$rootKey])) {
                $issue['root_cause_key'] = $rootKey;
                $issue['occurrences'] = is_array($issue['occurrences'] ?? null)
                    ? array_values($issue['occurrences'])
                    : [$occurrence];
                $groups[$rootKey] = $issue;

                continue;
            }

            $groups[$rootKey]['occurrences'][] = $occurrence;
            if ($this->severityRank((string) ($issue['severity'] ?? 'medium'))
                > $this->severityRank((string) ($groups[$rootKey]['severity'] ?? 'medium'))) {
                $occurrences = $groups[$rootKey]['occurrences'];
                $groups[$rootKey] = array_replace($groups[$rootKey], $issue, [
                    'root_cause_key' => $rootKey,
                    'occurrences' => $occurrences,
                ]);
            }
        }

        foreach ($groups as &$group) {
            $group['occurrences'] = array_values(array_unique($group['occurrences'], SORT_REGULAR));
        }

        return array_values($groups);
    }

    /** @param array<string, mixed> $issue */
    private function rootCauseKey(array $issue): string
    {
        $code = (string) ($issue['code'] ?? 'content_integrity');
        $family = match (true) {
            str_starts_with($code, 'citation_'), $code === 'source_declared_unverified' => 'citation',
            str_starts_with($code, 'ad_') => (string) ($issue['code_family'] ?? $code),
            default => (string) ($issue['code_family'] ?? $code),
        };
        $claim = trim((string) ($issue['claim_hash'] ?? $issue['fact_candidate_id'] ?? ''));
        if ($claim === '') {
            $claim = $this->normalize((string) ($issue['article_claim'] ?? $issue['quote'] ?? ''));
        }
        $sources = is_array($issue['evidence_keys'] ?? null)
            ? array_map('strval', $issue['evidence_keys'])
            : array_map('strval', is_array($issue['knowledge_refs'] ?? null) ? $issue['knowledge_refs'] : []);
        $sources = array_values(array_filter($sources, static fn (string $source): bool => preg_match('/^K\d+$/i', $source) !== 1));
        sort($sources);

        $keyParts = [$family, $claim];
        if (in_array($family, ['citation', 'knowledge_contradiction', 'data_mismatch'], true)) {
            $keyParts[] = implode('|', $sources);
        }
        if ($family === 'content_integrity' || $family === 'seo_truncation') {
            $keyParts[] = $this->normalize((string) ($issue['quote'] ?? ''));
        }
        if (str_starts_with($family, 'ad_')) {
            $legalRefs = array_map('strval', is_array($issue['legal_refs'] ?? null) ? $issue['legal_refs'] : []);
            sort($legalRefs);
            $keyParts[] = implode('|', $legalRefs);
        }

        return hash('sha256', implode("\0", $keyParts));
    }

    /** @param array<string, mixed> $issue */
    private function cappedCategory(array $issue, string $dimension): ?string
    {
        $code = (string) ($issue['code'] ?? '');
        $family = (string) ($issue['code_family'] ?? '');
        if ($dimension === 'data_traceability'
            && (str_starts_with($code, 'citation_') || $code === 'source_declared_unverified')) {
            return 'citation_format_or_source';
        }
        if ($dimension === 'content_integrity'
            && in_array($family, ['seo_truncation', 'summary_truncation', 'meta_description_truncation'], true)) {
            return 'seo_truncation';
        }

        return null;
    }

    /** @param list<array<string, mixed>> $uncertainties */
    private function hasHighMaterialityUncertainty(array $uncertainties): bool
    {
        return collect($uncertainties)->contains(
            static fn (array $uncertainty): bool => ($uncertainty['materiality'] ?? null) === 'high',
        );
    }

    /** @param list<array<string, mixed>> $issues */
    private function hasUnresolvedReference(array $issues): bool
    {
        return collect($issues)->contains(static fn (array $issue): bool => ($issue['location_status'] ?? 'resolved') === 'unresolved'
            || ($issue['references_valid'] ?? true) !== true
        );
    }

    /** @param list<array<string, mixed>> $issues */
    private function confidence(array $issues): float
    {
        if ($issues === []) {
            return 1.0;
        }

        $values = array_map(
            static fn (array $issue): float => max(0.0, min(1.0, (float) ($issue['confidence'] ?? 1.0))),
            $issues,
        );

        return round(array_sum($values) / count($values), 4);
    }

    private function severityRank(string $severity): int
    {
        return ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4][$severity] ?? 2;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $value) ?? $value;
    }
}
