<?php

namespace App\Services\GeoFlow;

class ArticleAiQualityScorer
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
        'ad_absolute_claim' => 'advertising_compliance',
        'ad_false_or_misleading' => 'advertising_compliance',
        'ad_industry_specific' => 'advertising_compliance',
        'ad_identifiability' => 'advertising_compliance',
        'content_integrity' => 'content_integrity',
    ];

    private const SEVERITY_DEDUCTIONS = [
        'critical' => 20,
        'high' => 12,
        'medium' => 6,
        'low' => 2,
    ];

    /**
     * @param  array<string, mixed>  $modelResult
     * @return array{score:int,dimension_scores:array<string,int>,decision:string,issues:list<array<string,mixed>>,uncertainties:list<array<string,mixed>>}
     */
    public function score(array $modelResult, int $passScore = 85, int $manualOverrideMinScore = 70): array
    {
        if ($manualOverrideMinScore < 0 || $manualOverrideMinScore >= $passScore || $passScore > 100) {
            throw new \InvalidArgumentException('AI quality thresholds are invalid.');
        }

        $issues = $this->uniqueIssues(is_array($modelResult['issues'] ?? null) ? $modelResult['issues'] : []);
        $uncertainties = array_values(is_array($modelResult['uncertainties'] ?? null) ? $modelResult['uncertainties'] : []);
        $dimensionScores = self::DIMENSION_MAXIMUMS;

        foreach ($issues as &$issue) {
            $code = (string) ($issue['code'] ?? '');
            $severity = (string) ($issue['severity'] ?? 'medium');
            $dimension = self::CODE_DIMENSIONS[$code] ?? 'content_integrity';
            $deduction = self::SEVERITY_DEDUCTIONS[$severity] ?? self::SEVERITY_DEDUCTIONS['medium'];
            $dimensionScores[$dimension] = max(0, $dimensionScores[$dimension] - $deduction);
            $issue['deduction'] = $deduction;
        }

        unset($issue);

        return (new ArticleAiQualityScorePolicy)->finalize(
            $modelResult, $issues, $uncertainties, $dimensionScores, $passScore, $manualOverrideMinScore,
        );
    }

    /**
     * @param  array<int, mixed>  $issues
     * @return list<array<string, mixed>>
     */
    private function uniqueIssues(array $issues): array
    {
        $unique = [];

        foreach ($issues as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            if (! array_key_exists((string) ($issue['code'] ?? ''), self::CODE_DIMENSIONS)) {
                throw new \InvalidArgumentException('AI quality issue code is invalid.');
            }

            $knowledgeRefs = is_array($issue['knowledge_refs'] ?? null) ? $issue['knowledge_refs'] : [];
            sort($knowledgeRefs);
            $key = hash('sha256', json_encode([
                (string) ($issue['code'] ?? ''),
                (string) ($issue['field'] ?? ''),
                (string) ($issue['quote'] ?? ''),
                $knowledgeRefs,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (! isset($unique[$key])) {
                $unique[$key] = $issue;

                continue;
            }
            $hardBlocker = ($unique[$key]['hard_blocker'] ?? false) === true || ($issue['hard_blocker'] ?? false) === true;
            if ((self::SEVERITY_DEDUCTIONS[$issue['severity'] ?? 'medium'] ?? 6)
                > (self::SEVERITY_DEDUCTIONS[$unique[$key]['severity'] ?? 'medium'] ?? 6)) {
                $unique[$key] = $issue;
            }
            $unique[$key]['hard_blocker'] = $hardBlocker;
        }

        return array_values($unique);
    }
}
