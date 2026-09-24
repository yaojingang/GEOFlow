<?php

namespace App\Services\GeoFlow;

final class ArticleAiQualityScorePolicy
{
    public const VERSION = 'score-release-1';

    /** Ordinary evidence weaknesses contribute to the score; only explicit blockers veto it.
     * @return array<string, mixed>
     */
    public function finalize(array $input, array $issues, array $uncertainties, array $dimensions, int $passScore, int $overrideMinimum): array
    {
        $adjustments = [];
        $uncertainClaims = [];
        $issueClaims = [];
        foreach ($issues as $issue) {
            if (! in_array($issue['code'] ?? '', [
                'knowledge_contradiction', 'unsupported_claim', 'data_mismatch', 'citation_missing',
                'citation_scope_mismatch', 'source_declared_unverified',
            ], true)) {
                continue;
            }
            $claims = [];
            foreach (['article_claim', 'quote'] as $key) {
                $claim = $this->normalize((string) ($issue[$key] ?? ''));
                if ($claim !== '') {
                    $claims[$claim] = true;
                }
            }
            foreach (array_keys($claims) as $claim) {
                $issueClaims[$claim] = ($issueClaims[$claim] ?? 0) + max(0, (int) ($issue['deduction'] ?? 0));
            }
        }
        foreach ($uncertainties as $uncertainty) {
            $claim = $this->normalize((string) ($uncertainty['claim'] ?? ''));
            if ($claim === '') {
                continue;
            }
            $deduction = ['high' => 6, 'medium' => 3, 'low' => 1][(string) ($uncertainty['materiality'] ?? 'medium')] ?? 3;
            $remaining = max(0, $deduction - ($issueClaims[$claim] ?? 0));
            $uncertainClaims[$claim] = max($uncertainClaims[$claim] ?? 0, $remaining);
        }
        $uncertaintyDeduction = min((int) $dimensions['knowledge_consistency'], array_sum($uncertainClaims));
        if ($uncertaintyDeduction > 0) {
            $dimensions['knowledge_consistency'] -= $uncertaintyDeduction;
            $adjustments[] = ['reason' => 'material_uncertainty', 'dimension' => 'knowledge_consistency', 'deduction' => $uncertaintyDeduction];
        }
        $coverage = (string) ($input['knowledge_coverage'] ?? 'insufficient');
        $coveragePenalty = ['partial' => 5, 'insufficient' => 10][$coverage] ?? 0;
        // Coverage summarizes the same evidence weaknesses already charged above.
        $evidenceDeducted = 35 - (int) $dimensions['knowledge_consistency'] + 25 - (int) $dimensions['data_traceability'];
        $coverageDeduction = min((int) $dimensions['data_traceability'], max(0, $coveragePenalty - $evidenceDeducted));
        if ($coverageDeduction > 0) {
            $dimensions['data_traceability'] -= $coverageDeduction;
            $adjustments[] = ['reason' => 'evidence_coverage_'.$coverage, 'dimension' => 'data_traceability', 'deduction' => $coverageDeduction];
        }
        $hardBlocker = false;
        foreach ($issues as $issue) {
            $hardBlocker = $hardBlocker || ($issue['severity'] ?? '') === 'critical' || ($issue['hard_blocker'] ?? false) === true;
        }
        $score = array_sum($dimensions);
        $reasons = [];
        foreach ($uncertainties as $uncertainty) {
            if (($uncertainty['gate_reason'] ?? null) === 'claim_coverage_incomplete') {
                $reasons[] = 'claim_coverage_incomplete';
            }
        }
        if ($hardBlocker) {
            $reasons[] = 'confirmed_hard_blocker';
        }
        if ((int) ($input['truncated_issue_count'] ?? 0) > 0) {
            $reasons[] = 'model_output_truncated';
        }
        if ($score < $passScore) {
            $reasons[] = 'score_below_threshold';
        }

        return [
            'score' => $score, 'dimension_scores' => $dimensions,
            'decision' => match (true) {
                $hardBlocker, $score < $overrideMinimum => 'blocked',
                $reasons !== [] => 'needs_review',
                default => 'passed',
            },
            'issues' => $issues, 'uncertainties' => $uncertainties,
            'gate_reasons' => array_values(array_unique($reasons)), 'score_adjustments' => $adjustments,
            'decision_policy_version' => self::VERSION,
        ];
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $value = preg_replace('/[。！？!?；;，,：:]+$/u', '', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }
}
