<?php

namespace App\Console\Commands;

use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\VersionAwareArticleAiQualityReviewer;
use App\Models\AiModel;
use App\Services\GeoFlow\AiQualityEvaluationDataset;
use App\Services\GeoFlow\ArticleAiQualityPromptRenderer;
use App\Services\GeoFlow\ArticleAiQualityResultValidator;
use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use App\Services\GeoFlow\ArticleAiQualityScorerV2;
use App\Services\GeoFlow\ArticleRiskScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class EvaluateArticleAiQualityCommand extends Command
{
    protected $signature = 'geoflow:evaluate-ai-quality
        {--dataset= : Golden dataset JSON path}
        {--output= : Output path without extension}
        {--live : Call a configured model instead of using saved predictions}
        {--model= : AI model database ID used by live evaluation}
        {--repeat=1 : Repeat each live case up to five times for decision stability}';

    protected $description = 'Evaluate AI quality decisions against a desensitized golden dataset';

    public function __construct(
        private readonly ArticleAiQualityReviewer $reviewer,
        private readonly ArticleAiQualityPromptRenderer $promptRenderer,
        private readonly ArticleAiQualityResultValidator $validator,
        private readonly ArticleAiQualityScorerV2 $scorer,
        private readonly ArticleAiQualitySampleBuilder $sampleBuilder,
        private readonly ArticleRiskScanner $riskScanner,
        private readonly AiQualityEvaluationDataset $datasetLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $datasetPath = $this->absolutePath((string) ($this->option('dataset') ?: 'tests/Fixtures/ai-quality/golden-v1.json'));
        try {
            $dataset = $this->datasetLoader->load($datasetPath);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
        $cases = is_array($dataset['cases'] ?? null) ? array_values($dataset['cases']) : [];
        if ($cases === []) {
            $this->components->error('The dataset contains no evaluation cases.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $model = $live ? $this->liveModel() : null;
        if ($live && ! $model instanceof AiModel) {
            return self::FAILURE;
        }
        if ($live) {
            $repeat = max(1, min(5, (int) $this->option('repeat')));
            $this->components->warn("Live evaluation will call {$model->name} for ".(count($cases) * $repeat).' requests and consume provider quota.');
        } else {
            $repeat = 1;
        }

        $evaluated = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $repeatPredictions = [];
            if ($live) {
                for ($attempt = 0; $attempt < $repeat; $attempt++) {
                    $repeatPredictions[] = $this->evaluateLiveCase($case, $model);
                }
                $prediction = $repeatPredictions[0] ?? [];
            } else {
                $prediction = $case['prediction'] ?? [];
            }
            if (! is_array($prediction)) {
                throw new RuntimeException('Evaluation prediction must be an object.');
            }
            $repeatDecisions = $live
                ? array_column($repeatPredictions, 'decision')
                : (is_array($prediction['repeat_decisions'] ?? null) ? $prediction['repeat_decisions'] : []);
            $baseline = is_array($case['baseline'] ?? null) ? $case['baseline'] : [];
            $evaluated[] = [
                'id' => (string) ($case['id'] ?? 'case-'.(count($evaluated) + 1)),
                'split' => (string) ($case['split'] ?? 'unknown'),
                'inspection_scope' => (string) ($case['inspection_scope'] ?? 'full') === 'fallback_sampled'
                    ? 'fallback_sampled'
                    : 'full',
                'expected' => $this->normalizeOutcome(is_array($case['expected'] ?? null) ? $case['expected'] : []),
                'prediction' => $this->normalizeOutcome($prediction),
                'coverage' => is_array($prediction['coverage'] ?? null) ? $prediction['coverage'] : null,
                'repeat_decisions' => array_values(array_filter(array_map(
                    'strval',
                    $repeatDecisions,
                ), static fn (string $decision): bool => in_array($decision, ['passed', 'needs_review', 'blocked'], true))),
                'latency_ms' => max(0, (int) ($prediction['latency_ms'] ?? 0)),
                'prompt_tokens' => max(0, (int) ($prediction['prompt_tokens'] ?? 0)),
                'completion_tokens' => max(0, (int) ($prediction['completion_tokens'] ?? 0)),
                'baseline_prompt_tokens' => max(0, (int) ($baseline['prompt_tokens'] ?? 0)),
                'baseline_completion_tokens' => max(0, (int) ($baseline['completion_tokens'] ?? 0)),
                'category' => (string) ($case['category'] ?? 'general_quality'),
                'atomic_fact' => is_array($case['atomic_fact'] ?? null) ? $case['atomic_fact'] : null,
            ];
        }

        $requirements = is_array($dataset['requirements'] ?? null) ? $dataset['requirements'] : [];
        $metrics = $this->metrics($evaluated);
        $splitCounts = array_count_values(array_column($evaluated, 'split'));
        $gateChecks = [
            'live_run' => $live,
            'dataset_size' => count($evaluated) >= (int) ($requirements['total_cases'] ?? 240)
            && ($splitCounts['calibration'] ?? 0) >= (int) ($requirements['calibration'] ?? 120)
            && ($splitCounts['regression'] ?? 0) >= (int) ($requirements['regression'] ?? 60)
            && ($splitCounts['blind'] ?? 0) >= (int) ($requirements['blind'] ?? 60),
            'quality_thresholds' => $metrics['safe_false_block_rate'] <= 0.03
            && $metrics['major_risk_recall'] >= 0.97
            && $metrics['issue_macro_f1'] >= 0.85
            && $metrics['cohens_kappa'] >= 0.75,
            'model_latency' => $metrics['latency_ms']['p95'] <= 55_000,
            'token_budget' => $metrics['prompt_tokens']['p95'] <= 6000
                && $metrics['completion_tokens']['p95'] <= 1500,
            'token_reduction_targets' => $metrics['token_reduction_vs_baseline']['case_count'] === count($evaluated)
                && $metrics['token_reduction_vs_baseline']['prompt_p50_ratio'] >= 0.25
                && $metrics['token_reduction_vs_baseline']['completion_p50_ratio'] >= 0.40,
            'end_to_end_latency' => $live
                && (int) data_get($metrics, 'by_inspection_scope.full.case_count', 0) > 0
                && (int) data_get($metrics, 'by_inspection_scope.fallback_sampled.case_count', 0) > 0
                && (int) data_get($metrics, 'by_inspection_scope.full.metrics.latency_ms.p95', PHP_INT_MAX) <= 235_000
                && (int) data_get($metrics, 'by_inspection_scope.fallback_sampled.metrics.latency_ms.p95', PHP_INT_MAX) <= 55_000,
            'repeat_stability' => $live
                && $repeat === 5
                && $metrics['repeat_stability']['case_count'] === count($evaluated)
                && $metrics['repeat_stability']['decision_consistency'] >= 0.95,
        ];
        $productionGateReady = ! in_array(false, $gateChecks, true);
        $report = [
            'schema_version' => 2,
            'algorithm_version' => (string) ($dataset['algorithm_version'] ?? 'legacy-quality-evaluation-1.0.0'),
            'generated_at' => now()->toIso8601String(),
            'mode' => $live ? 'live' : 'offline',
            'evaluation_scope' => $live ? 'production_components' : 'saved_predictions',
            'model_id' => $model?->id,
            'dataset' => [
                'path' => $this->portablePath($datasetPath),
                'version' => (string) ($dataset['version'] ?? 'unknown'),
                'case_count' => count($evaluated),
                'split_counts' => $splitCounts,
                'requirements' => $requirements,
                'sha256' => hash_file('sha256', $datasetPath),
            ],
            'metrics' => $metrics,
            'gate_checks' => $gateChecks,
            'production_gate_ready' => $productionGateReady,
            'cases' => $evaluated,
        ];

        $outputBase = $this->outputBasePath();
        File::ensureDirectoryExists(dirname($outputBase));
        File::put($outputBase.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        File::put($outputBase.'.md', $this->markdown($report));

        $this->components->info('AI quality evaluation completed.');
        $this->line('JSON: '.$outputBase.'.json');
        $this->line('Markdown: '.$outputBase.'.md');
        if (! $productionGateReady) {
            $this->components->warn('The dataset or quality thresholds are incomplete. Keep scoring v2 in offline or shadow mode.');
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $case @return array<string,mixed> */
    private function evaluateLiveCase(array $case, AiModel $model): array
    {
        $article = is_array($case['article'] ?? null) ? $case['article'] : [];
        $facts = is_array($case['facts'] ?? null) ? array_values($case['facts']) : [];
        $evidence = is_array($case['evidence'] ?? null) ? array_values($case['evidence']) : [];
        $scope = (string) ($case['inspection_scope'] ?? 'full') === 'fallback_sampled'
            ? 'fallback_sampled'
            : 'full';
        $rules = json_decode((string) File::get(resource_path('rules/advertising-cn-v1.json')), true);
        $rules = is_array($rules) ? $rules : [];
        $riskScan = $this->riskScanner->scan($article);
        $sample = $scope === 'fallback_sampled'
            ? $this->sampleBuilder->build($article, $facts, is_array($riskScan['matches'] ?? null) ? $riskScan['matches'] : [])
            : null;
        $promptFacts = $sample === null ? $facts : $this->factsForSample($facts, (array) ($sample['sampled_ranges'] ?? []));
        $promptEvidence = $this->evidenceForFacts($evidence, $promptFacts);
        $template = (string) File::get(resource_path('prompts/article-quality-cn-v1.txt'));
        $instructions = $this->promptRenderer->render($template, [
            'article_title' => (string) ($article['title'] ?? ''),
            'article_excerpt' => (string) ($article['excerpt'] ?? ''),
            'article_outline' => '',
            'article_content' => $sample === null
                ? (string) ($article['content'] ?? '')
                : (string) ($sample['sampled_content'] ?? ''),
            'keywords' => (string) ($article['keywords'] ?? ''),
            'meta_description' => (string) ($article['meta_description'] ?? ''),
            'fact_candidates' => $promptFacts,
            'knowledge' => $promptEvidence,
            'advertising_rules' => $rules,
            'publication_context' => [
                'is_ai_generated' => true,
                'inspection_scope' => $scope,
                'coverage' => $sample === null ? null : $this->publicCoverage($sample),
            ],
            'inspection_date' => now()->toDateString(),
            'segment_index' => 1,
            'segment_count' => 1,
            'segment_start_offset' => 0,
        ]);

        $startedAt = hrtime(true);
        $review = $this->reviewer instanceof VersionAwareArticleAiQualityReviewer
            ? $this->reviewer->reviewWithinVersion(
                $model,
                $instructions,
                (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
                'fast_v2',
            )
            : $this->reviewer->review($model, $instructions);
        $validated = $this->validator->validate(
            is_array($review['result'] ?? null) ? $review['result'] : [],
            $article,
            $promptFacts,
            $promptEvidence,
            $rules,
        );
        $hasMaterialFacts = collect($promptFacts)->contains(
            static fn (mixed $fact): bool => is_array($fact)
                && in_array((string) ($fact['materiality'] ?? ''), ['high', 'medium'], true),
        );
        $validated['knowledge_coverage'] = ! $hasMaterialFacts
            ? 'sufficient'
            : ($promptEvidence === [] ? 'insufficient' : 'sufficient');
        if ((string) ($riskScan['status'] ?? 'clean') === 'blocked') {
            foreach ((array) ($riskScan['matches'] ?? []) as $match) {
                if ((string) ($match['severity'] ?? '') !== 'blocked') {
                    continue;
                }
                $validated['issues'][] = [
                    'code' => 'ad_absolute_claim',
                    'code_family' => 'advertising_compliance',
                    'severity' => 'critical',
                    'field' => (string) ($match['field'] ?? 'content'),
                    'quote' => (string) ($match['word'] ?? ''),
                    'reason' => '确定性风险扫描命中阻断规则。',
                    'suggestion' => (string) ($match['suggestion'] ?? '修改后重新质检。'),
                    'location_status' => 'resolved',
                    'references_valid' => true,
                ];
            }
        }
        $score = $this->scorer->score($validated);
        $coverage = null;
        if ($sample !== null) {
            $coverage = array_replace($sample, [
                'deterministic_risk_status' => (string) ($riskScan['status'] ?? 'clean'),
                'deterministic_risk_match_count' => (int) ($riskScan['match_count'] ?? 0),
                'knowledge_coverage' => (string) $validated['knowledge_coverage'],
            ]);
            $gateReasons = $this->sampledGateReasons($coverage, $validated, $score);
            if (($score['decision'] ?? null) !== 'blocked' && $gateReasons !== []) {
                $score['decision'] = 'needs_review';
            }
            $coverage['safe_for_auto_release'] = ($score['decision'] ?? null) === 'passed' && $gateReasons === [];
            $coverage['gate_reasons'] = $gateReasons;
        }
        $usage = is_array($review['usage'] ?? null) ? $review['usage'] : [];

        return [
            'decision' => (string) $score['decision'],
            'issue_codes' => array_values(array_unique(array_map(
                static fn (array $issue): string => (string) ($issue['code'] ?? ''),
                array_filter($score['issues'] ?? [], 'is_array'),
            ))),
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['promptTokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? $usage['completionTokens'] ?? 0),
            'inspection_scope' => $scope,
            'coverage' => $coverage === null ? null : $this->publicCoverage($coverage),
        ];
    }

    /** @param list<array<string,mixed>> $facts @param list<array<string,mixed>> $ranges @return list<array<string,mixed>> */
    private function factsForSample(array $facts, array $ranges): array
    {
        return array_values(array_filter($facts, static function (array $fact) use ($ranges): bool {
            foreach ((array) ($fact['occurrences'] ?? [$fact]) as $occurrence) {
                if (! is_array($occurrence)) {
                    continue;
                }
                if ((string) ($occurrence['field'] ?? $fact['field'] ?? '') !== 'content') {
                    return true;
                }
                $start = (int) ($occurrence['start_offset'] ?? $fact['start_offset'] ?? 0);
                $end = (int) ($occurrence['end_offset'] ?? $fact['end_offset'] ?? $start);
                foreach ($ranges as $range) {
                    if ($start < (int) ($range['end_offset'] ?? 0) && $end > (int) ($range['start_offset'] ?? 0)) {
                        return true;
                    }
                }
            }

            return false;
        }));
    }

    /** @param list<array<string,mixed>> $evidence @param list<array<string,mixed>> $facts @return list<array<string,mixed>> */
    private function evidenceForFacts(array $evidence, array $facts): array
    {
        $references = [];
        $hasReferenceMetadata = false;
        foreach ($facts as $fact) {
            $hasReferenceMetadata = $hasReferenceMetadata || array_key_exists('knowledge_refs', $fact);
            foreach ((array) ($fact['knowledge_refs'] ?? []) as $reference) {
                $references[(string) $reference] = true;
            }
        }

        if ($facts !== [] && ! $hasReferenceMetadata) {
            return $evidence;
        }
        if ($references === []) {
            return [];
        }

        return array_values(array_filter(
            $evidence,
            static fn (array $item): bool => isset($references[(string) ($item['id'] ?? '')]),
        ));
    }

    /** @param array<string,mixed> $coverage @param array<string,mixed> $validated @param array<string,mixed> $score @return list<string> */
    private function sampledGateReasons(array $coverage, array $validated, array $score): array
    {
        $coverageSafe = ! (bool) ($coverage['mandatory_overflow'] ?? true)
            && (int) ($coverage['mandatory_claims_covered'] ?? -1) === (int) ($coverage['mandatory_claims_total'] ?? 0)
            && array_values($coverage['regions_covered'] ?? []) === ['front', 'middle', 'back'];
        $hasHighUncertainty = collect($validated['uncertainties'] ?? [])->contains(
            static fn (mixed $uncertainty): bool => is_array($uncertainty)
                && (string) ($uncertainty['materiality'] ?? '') === 'high',
        );
        $hasHighIssue = collect($score['issues'] ?? [])->contains(
            static fn (mixed $issue): bool => is_array($issue)
                && in_array((string) ($issue['severity'] ?? ''), ['critical', 'high'], true),
        );

        return array_keys(array_filter([
            'sample_coverage_incomplete' => ! $coverageSafe,
            'sample_knowledge_insufficient' => (string) ($coverage['knowledge_coverage'] ?? 'insufficient') !== 'sufficient',
            'sample_high_uncertainty' => $hasHighUncertainty,
            'sample_output_truncated' => (int) ($validated['truncated_issue_count'] ?? 0) > 0,
            'sample_high_risk_issue' => $hasHighIssue && ($score['decision'] ?? null) !== 'blocked',
        ]));
    }

    /** @param array<string,mixed> $coverage @return array<string,mixed> */
    private function publicCoverage(array $coverage): array
    {
        unset($coverage['sampled_content']);
        if (is_array($coverage['sampled_ranges'] ?? null)) {
            $coverage['sampled_ranges'] = array_map(static function (array $range): array {
                unset($range['content']);

                return $range;
            }, $coverage['sampled_ranges']);
        }

        return $coverage;
    }

    private function liveModel(): ?AiModel
    {
        $query = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')->orWhereIn('model_type', ['', 'chat']);
            });
        if ((int) $this->option('model') > 0) {
            $query->whereKey((int) $this->option('model'));
        }
        $model = $query->orderBy('failover_priority')->orderBy('id')->first();
        if (! $model) {
            $this->components->error('No active chat model is available for live evaluation.');
        }

        return $model;
    }

    /** @param array<string,mixed> $outcome @return array{decision:string,issue_codes:list<string>} */
    private function normalizeOutcome(array $outcome): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            'strval',
            is_array($outcome['issue_codes'] ?? null) ? $outcome['issue_codes'] : [],
        ))));
        sort($codes);

        return [
            'decision' => in_array((string) ($outcome['decision'] ?? ''), ['passed', 'needs_review', 'blocked'], true)
                ? (string) $outcome['decision']
                : 'needs_review',
            'issue_codes' => $codes,
        ];
    }

    /** @param list<array<string,mixed>> $cases @return array<string,mixed> */
    private function metrics(array $cases, bool $includeScopes = true): array
    {
        $decisions = ['passed', 'needs_review', 'blocked'];
        $matrix = array_fill_keys($decisions, array_fill_keys($decisions, 0));
        $correct = 0;
        $safeCount = 0;
        $falseBlocks = 0;
        $majorCount = 0;
        $majorDetected = 0;
        $expectedCounts = array_fill_keys($decisions, 0);
        $predictedCounts = array_fill_keys($decisions, 0);
        $issueCodes = [];
        foreach ($cases as $case) {
            $expected = (string) $case['expected']['decision'];
            $predicted = (string) $case['prediction']['decision'];
            $matrix[$expected][$predicted]++;
            $expectedCounts[$expected]++;
            $predictedCounts[$predicted]++;
            $correct += $expected === $predicted ? 1 : 0;
            if ($expected === 'passed') {
                $safeCount++;
                $falseBlocks += $predicted !== 'passed' ? 1 : 0;
            }
            if ($expected === 'blocked') {
                $majorCount++;
                $majorDetected += $predicted === 'blocked' ? 1 : 0;
            }
            $issueCodes = array_merge($issueCodes, $case['expected']['issue_codes'], $case['prediction']['issue_codes']);
        }

        $f1 = [];
        foreach (array_values(array_unique($issueCodes)) as $code) {
            $tp = $fp = $fn = 0;
            foreach ($cases as $case) {
                $expected = in_array($code, $case['expected']['issue_codes'], true);
                $predicted = in_array($code, $case['prediction']['issue_codes'], true);
                $tp += $expected && $predicted ? 1 : 0;
                $fp += ! $expected && $predicted ? 1 : 0;
                $fn += $expected && ! $predicted ? 1 : 0;
            }
            $f1[] = (2 * $tp + $fp + $fn) > 0 ? (2 * $tp) / (2 * $tp + $fp + $fn) : 1.0;
        }

        $count = max(1, count($cases));
        $observed = $correct / $count;
        $chance = 0.0;
        foreach ($decisions as $decision) {
            $chance += ($expectedCounts[$decision] / $count) * ($predictedCounts[$decision] / $count);
        }
        $latencies = array_map('intval', array_column($cases, 'latency_ms'));
        $tokens = array_map(
            static fn (array $case): int => (int) $case['prompt_tokens'] + (int) $case['completion_tokens'],
            $cases,
        );
        $promptTokens = array_map('intval', array_column($cases, 'prompt_tokens'));
        $completionTokens = array_map('intval', array_column($cases, 'completion_tokens'));
        $baselinePromptTokens = array_values(array_filter(
            array_map('intval', array_column($cases, 'baseline_prompt_tokens')),
            static fn (int $tokens): bool => $tokens > 0,
        ));
        $baselineCompletionTokens = array_values(array_filter(
            array_map('intval', array_column($cases, 'baseline_completion_tokens')),
            static fn (int $tokens): bool => $tokens > 0,
        ));
        $repeatRatios = [];
        foreach ($cases as $case) {
            $decisions = is_array($case['repeat_decisions'] ?? null) ? $case['repeat_decisions'] : [];
            if (count($decisions) < 5) {
                continue;
            }
            $counts = array_count_values($decisions);
            $repeatRatios[] = max($counts) / count($decisions);
        }

        $scopeMetrics = [];
        if ($includeScopes) {
            foreach (['full', 'fallback_sampled'] as $scope) {
                $scopeCases = array_values(array_filter(
                    $cases,
                    static fn (array $case): bool => (string) ($case['inspection_scope'] ?? 'full') === $scope,
                ));
                $scopeMetrics[$scope] = [
                    'case_count' => count($scopeCases),
                    'metrics' => $scopeCases === [] ? null : $this->metrics($scopeCases, false),
                ];
            }
        }

        $promptBaselineP50 = $this->percentile($baselinePromptTokens, 50);
        $completionBaselineP50 = $this->percentile($baselineCompletionTokens, 50);
        $promptP50 = $this->percentile($promptTokens, 50);
        $completionP50 = $this->percentile($completionTokens, 50);
        $atomicCases = array_values(array_filter($cases, static fn (array $case): bool => is_array($case['atomic_fact'] ?? null)));
        $atomicCorrect = count(array_filter($atomicCases, static fn (array $case): bool => $case['expected']['decision'] === $case['prediction']['decision']));
        $atomicPredictedPositive = count(array_filter($atomicCases, static fn (array $case): bool => $case['prediction']['decision'] !== 'passed'));
        $atomicExpectedPositive = count(array_filter($atomicCases, static fn (array $case): bool => $case['expected']['decision'] !== 'passed'));
        $atomicTruePositive = count(array_filter($atomicCases, static fn (array $case): bool => $case['expected']['decision'] !== 'passed' && $case['prediction']['decision'] !== 'passed'));
        $atomicSafeCount = count(array_filter($atomicCases, static fn (array $case): bool => $case['expected']['decision'] === 'passed'));
        $atomicFalseBlocks = count(array_filter($atomicCases, static fn (array $case): bool => $case['expected']['decision'] === 'passed' && $case['prediction']['decision'] !== 'passed'));
        $fallbackCount = count(array_filter($atomicCases, static fn (array $case): bool => (bool) data_get($case, 'atomic_fact.expected_fallback', false)));
        $atomicPrecision = $atomicPredictedPositive > 0 ? $atomicTruePositive / $atomicPredictedPositive : 1.0;
        $atomicRecall = $atomicExpectedPositive > 0 ? $atomicTruePositive / $atomicExpectedPositive : 1.0;

        return [
            'decision_accuracy' => round($observed, 4),
            'decision_confusion_matrix' => $matrix,
            'safe_false_block_rate' => round($safeCount > 0 ? $falseBlocks / $safeCount : 0.0, 4),
            'major_risk_recall' => round($majorCount > 0 ? $majorDetected / $majorCount : 0.0, 4),
            'issue_macro_f1' => round($f1 !== [] ? array_sum($f1) / count($f1) : 1.0, 4),
            'cohens_kappa' => round((1 - $chance) > 0 ? ($observed - $chance) / (1 - $chance) : 1.0, 4),
            'latency_ms' => ['p50' => $this->percentile($latencies, 50), 'p95' => $this->percentile($latencies, 95)],
            'prompt_tokens' => ['p50' => $this->percentile($promptTokens, 50), 'p95' => $this->percentile($promptTokens, 95)],
            'completion_tokens' => ['p50' => $this->percentile($completionTokens, 50), 'p95' => $this->percentile($completionTokens, 95)],
            'tokens' => ['p50' => $this->percentile($tokens, 50), 'p95' => $this->percentile($tokens, 95)],
            'token_reduction_vs_baseline' => [
                'case_count' => min(count($baselinePromptTokens), count($baselineCompletionTokens)),
                'baseline_prompt_p50' => $promptBaselineP50,
                'baseline_completion_p50' => $completionBaselineP50,
                'prompt_p50_ratio' => $promptBaselineP50 > 0
                    ? round(1 - ($promptP50 / $promptBaselineP50), 4)
                    : 0.0,
                'completion_p50_ratio' => $completionBaselineP50 > 0
                    ? round(1 - ($completionP50 / $completionBaselineP50), 4)
                    : 0.0,
            ],
            'repeat_stability' => [
                'case_count' => count($repeatRatios),
                'decision_consistency' => $repeatRatios === []
                    ? 0.0
                    : round(array_sum($repeatRatios) / count($repeatRatios), 4),
            ],
            'atomic_facts' => [
                'case_count' => count($atomicCases),
                'accuracy' => round(count($atomicCases) > 0 ? $atomicCorrect / count($atomicCases) : 1.0, 4),
                'precision' => ['value' => round($atomicPrecision, 4), 'wilson_95' => $this->wilsonInterval($atomicTruePositive, max(1, $atomicPredictedPositive))],
                'recall' => ['value' => round($atomicRecall, 4), 'wilson_95' => $this->wilsonInterval($atomicTruePositive, max(1, $atomicExpectedPositive))],
                'false_block_rate' => round($atomicSafeCount > 0 ? $atomicFalseBlocks / $atomicSafeCount : 0.0, 4),
                'fallback_rate' => round(count($atomicCases) > 0 ? $fallbackCount / count($atomicCases) : 0.0, 4),
            ],
            'by_inspection_scope' => $scopeMetrics,
        ];
    }

    /** @return array{lower:float,upper:float} */
    private function wilsonInterval(int $successes, int $total): array
    {
        $z = 1.96;
        $proportion = $successes / max(1, $total);
        $denominator = 1 + ($z ** 2 / $total);
        $centre = ($proportion + ($z ** 2 / (2 * $total))) / $denominator;
        $margin = ($z * sqrt(($proportion * (1 - $proportion) / $total) + ($z ** 2 / (4 * ($total ** 2))))) / $denominator;

        return ['lower' => round(max(0, $centre - $margin), 4), 'upper' => round(min(1, $centre + $margin), 4)];
    }

    /** @param list<int> $values */
    private function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (int) $values[max(0, min(count($values) - 1, $index))];
    }

    /** @param array<string,mixed> $report */
    private function markdown(array $report): string
    {
        $metrics = $report['metrics'];
        $splits = $report['dataset']['split_counts'];

        return implode("\n", [
            '# AI 质检黄金集评测报告',
            '',
            '- 生成时间：'.$report['generated_at'],
            '- 运行模式：'.$report['mode'],
            '- 评测范围：'.($report['evaluation_scope'] === 'production_components'
                ? '生产组件端到端裁决（使用黄金集固化证据）'
                : '已保存预测离线复算'),
            '- 数据集版本：'.$report['dataset']['version'],
            '- 样本数：'.$report['dataset']['case_count'],
            '- 分组：calibration '.($splits['calibration'] ?? 0).' / regression '.($splits['regression'] ?? 0).' / blind '.($splits['blind'] ?? 0),
            '- 生产门禁就绪：'.($report['production_gate_ready'] ? '是' : '否'),
            '',
            '## 核心指标',
            '',
            '| 指标 | 结果 | 目标 |',
            '| --- | ---: | ---: |',
            '| 安全样本误拦截率 | '.number_format((float) $metrics['safe_false_block_rate'] * 100, 2).'% | ≤ 3% |',
            '| 重大风险召回率 | '.number_format((float) $metrics['major_risk_recall'] * 100, 2).'% | ≥ 97% |',
            '| 问题级 Macro F1 | '.number_format((float) $metrics['issue_macro_f1'], 4).' | ≥ 0.85 |',
            '| Cohen Kappa | '.number_format((float) $metrics['cohens_kappa'], 4).' | ≥ 0.75 |',
            '| 延迟 P50 / P95 | '.$metrics['latency_ms']['p50'].' / '.$metrics['latency_ms']['p95'].' ms | 25s / 55s |',
            '| 输入 Token P50 / P95 | '.$metrics['prompt_tokens']['p50'].' / '.$metrics['prompt_tokens']['p95'].' | P95 ≤ 6000 |',
            '| 输出 Token P50 / P95 | '.$metrics['completion_tokens']['p50'].' / '.$metrics['completion_tokens']['p95'].' | P95 ≤ 1500 |',
            '| 输入 Token P50 降幅 | '.number_format((float) $metrics['token_reduction_vs_baseline']['prompt_p50_ratio'] * 100, 2).'% | ≥ 25% |',
            '| 输出 Token P50 降幅 | '.number_format((float) $metrics['token_reduction_vs_baseline']['completion_p50_ratio'] * 100, 2).'% | ≥ 40% |',
            '| 同输入 decision 一致率 | '.number_format((float) $metrics['repeat_stability']['decision_consistency'] * 100, 2).'% | ≥ 95% |',
            '',
            '## 结论',
            '',
            $report['production_gate_ready']
                ? '数据规模与核心质量阈值均已达到，可进入受控金丝雀评审。'
                : '当前报告用于框架验证和影子评测。生产门禁还需要 240 篇裁决样本、端到端延迟和同输入 5 次稳定性数据。',
            '',
        ]);
    }

    private function outputBasePath(): string
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return storage_path('app/ai-quality-evaluations/'.now()->format('Ymd-His'));
        }

        return $this->absolutePath(preg_replace('/\.(json|md)$/i', '', $output) ?: $output);
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function portablePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
