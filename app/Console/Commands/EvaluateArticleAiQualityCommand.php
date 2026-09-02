<?php

namespace App\Console\Commands;

use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\PreReservedArticleAiQualityReviewer;
use App\Contracts\ProviderAttemptAwareArticleAiQualityReviewer;
use App\Contracts\VersionAwareArticleAiQualityReviewer;
use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Data\Ai\DirectAdminAiExecutionState;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\AiModelRuntimeEligibilityException;
use App\Exceptions\AiQualityComparisonCheckpointException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Services\GeoFlow\AiQualityComparisonCheckpointStore;
use App\Services\GeoFlow\AiQualityEvaluationDataset;
use App\Services\GeoFlow\ArticleAiQualityEvidenceBuilder;
use App\Services\GeoFlow\ArticleAiQualityPromptRenderer;
use App\Services\GeoFlow\ArticleAiQualityProviderCircuitBreaker;
use App\Services\GeoFlow\ArticleAiQualityProviderUsageSession;
use App\Services\GeoFlow\ArticleAiQualityResultValidator;
use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use App\Services\GeoFlow\ArticleAiQualityScorerV2;
use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\GeoFlow\DirectAdminAiExecutionGuard;
use App\Services\GeoFlow\DirectAdminAiModelInvocationGateway;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

class EvaluateArticleAiQualityCommand extends Command
{
    private const LOCAL_ATOMIC_CASE_IDS = [449, 467, 471, 473, 486];

    private const LOCAL_ATOMIC_CASE_SET_VERSION = 'kb23-five-articles-v1';

    protected $signature = 'geoflow:evaluate-ai-quality
        {--dataset= : Golden dataset JSON path}
        {--output= : Output path without extension}
        {--live : Call a configured model instead of using saved predictions}
        {--admin= : Active administrator ID whose AI configuration is used by live evaluation}
        {--model= : AI model database ID used by live evaluation}
        {--articles= : Comma-separated article IDs for a real comparison run}
        {--knowledge-base= : Knowledge base ID for a real comparison run}
        {--compare= : Comparison modes, supported value: atomic,knowledge}
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
        private readonly ArticleFactCandidateExtractor $factExtractor,
        private readonly ArticleAiQualityEvidenceBuilder $evidenceBuilder,
        private readonly ArticleAtomicFactInspector $atomicFactInspector,
        private readonly DirectAdminAiExecutionGuard $executionGuard,
        private readonly DirectAdminAiModelInvocationGateway $invocationGateway,
        private readonly ArticleAiQualityProviderCircuitBreaker $qualityCircuitBreaker,
        private readonly AiQualityComparisonCheckpointStore $comparisonCheckpoints,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (trim((string) $this->option('articles')) !== '') {
            return $this->handleArticleComparison();
        }

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
        $execution = $live ? $this->liveExecution('ai_quality_evaluation') : null;
        if ($live && $execution === null) {
            return self::FAILURE;
        }
        $model = $execution?->model;
        $context = $execution?->context;
        if ($live) {
            $repeat = max(1, min(5, (int) $this->option('repeat')));
            $this->components->warn("Live evaluation will call {$model->name} for ".(count($cases) * $repeat).' requests and consume provider quota.');
        } else {
            $repeat = 1;
        }

        $evaluated = [];
        $liveCalls = [];
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $repeatPredictions = [];
            if ($live) {
                try {
                    for ($attempt = 0; $attempt < $repeat; $attempt++) {
                        $livePrediction = $this->evaluateLiveCase($case, $execution);
                        $repeatPredictions[] = $livePrediction;
                        $liveCalls[] = [
                            'case_id' => (string) ($case['id'] ?? 'case-'.(count($evaluated) + 1)),
                            'attempt' => $attempt + 1,
                            'resolved_model_id' => $livePrediction['resolved_model_id'],
                            'resolved_model_name' => $livePrediction['resolved_model_name'],
                            'resolved_model_source' => $livePrediction['resolved_model_source'],
                            'latency_ms' => $livePrediction['latency_ms'],
                            'prompt_tokens' => $livePrediction['prompt_tokens'],
                            'completion_tokens' => $livePrediction['completion_tokens'],
                        ];
                    }
                } catch (AiModelAccessException $exception) {
                    return $this->failLiveAccess($exception);
                } catch (\Throwable) {
                    return $this->failLive(AiModelAccessException::AI_MODEL_UNAVAILABLE);
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
                'score' => max(0, min(100, (int) ($prediction['score'] ?? 0))),
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
                'resolved_model_id' => isset($prediction['resolved_model_id'])
                    ? (int) $prediction['resolved_model_id']
                    : null,
                'resolved_model_name' => isset($prediction['resolved_model_name'])
                    ? (string) $prediction['resolved_model_name']
                    : null,
                'resolved_model_source' => isset($prediction['resolved_model_source'])
                    ? (string) $prediction['resolved_model_source']
                    : null,
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
        $publishModel = $execution?->model;
        $context = $execution?->context;
        $modelsUsed = $this->modelsUsed($live ? $liveCalls : []);
        $modelUsageMode = $this->modelUsageMode($modelsUsed);
        $singleModel = $modelUsageMode === 'single' ? $modelsUsed[0] : null;
        $report = [
            'schema_version' => 2,
            'algorithm_version' => (string) ($dataset['algorithm_version'] ?? 'legacy-quality-evaluation-1.0.0'),
            'generated_at' => now()->toIso8601String(),
            'mode' => $live ? 'live' : 'offline',
            'evaluation_scope' => $live ? 'production_components' : 'saved_predictions',
            'model_id' => $singleModel['id'] ?? null,
            'model_usage_mode' => $modelUsageMode,
            'models_used' => $modelsUsed,
            'execution' => $execution === null ? null : array_merge(
                $context->toSafeArray(),
                [
                    'model_id' => $singleModel['id'] ?? null,
                    'model_source' => $singleModel['source'] ?? $modelUsageMode,
                ],
            ),
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
            'calls' => $liveCalls,
            'cases' => $evaluated,
        ];

        $outputBase = $this->outputBasePath();
        if ($live) {
            try {
                $this->publishLiveReport(
                    $context,
                    $publishModel,
                    $outputBase,
                    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                    $this->markdown($report),
                );
            } catch (AiModelAccessException $exception) {
                return $this->failLiveAccess($exception);
            } catch (\Throwable) {
                return $this->failLive(AiModelAccessException::AI_MODEL_UNAVAILABLE);
            }
        } else {
            File::ensureDirectoryExists(dirname($outputBase));
            File::put($outputBase.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            File::put($outputBase.'.md', $this->markdown($report));
        }

        $this->components->info('AI quality evaluation completed.');
        $this->line('JSON: '.$outputBase.'.json');
        $this->line('Markdown: '.$outputBase.'.md');
        if (! $productionGateReady) {
            $this->components->warn('The dataset or quality thresholds are incomplete. Keep scoring v2 in offline or shadow mode.');
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $case @return array<string,mixed> */
    private function evaluateLiveCase(
        array $case,
        DirectAdminAiExecutionState $execution,
    ): array {
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
        $review = $this->reviewLive($execution, $instructions);
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
        $atomicInspection = is_array($case['atomic_inspection'] ?? null) ? $case['atomic_inspection'] : [];
        if ($atomicInspection !== []) {
            $validated['issues'] = array_values(array_merge(
                (array) ($validated['issues'] ?? []),
                (array) ($atomicInspection['issues'] ?? []),
            ));
        }
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
            'score' => max(0, min(100, (int) ($score['score'] ?? 0))),
            'issue_codes' => array_values(array_unique(array_map(
                static fn (array $issue): string => (string) ($issue['code'] ?? ''),
                array_filter($score['issues'] ?? [], 'is_array'),
            ))),
            'issues' => array_values(array_filter($score['issues'] ?? [], 'is_array')),
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? $usage['promptTokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? $usage['completionTokens'] ?? 0),
            'inspection_scope' => $scope,
            'coverage' => $coverage === null ? null : $this->publicCoverage($coverage),
            'atomic_facts' => $atomicInspection,
            'resolved_model_id' => (int) $execution->model->id,
            'resolved_model_name' => (string) $execution->model->name,
            'resolved_model_source' => $execution->source,
        ];
    }

    private function handleArticleComparison(): int
    {
        if (! (bool) $this->option('live')) {
            $this->components->error('Article comparison requires --live.');

            return self::INVALID;
        }
        $articleIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $this->option('articles'))))));
        $knowledgeBaseId = (int) $this->option('knowledge-base');
        $modes = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $this->option('compare'))))));
        if ($articleIds === [] || $knowledgeBaseId <= 0 || $modes !== ['atomic', 'knowledge']) {
            $this->components->error('Use --articles=1,2 --knowledge-base=ID --compare=atomic,knowledge.');

            return self::INVALID;
        }
        $execution = $this->liveExecution('ai_quality_article_comparison');
        if ($execution === null) {
            return self::FAILURE;
        }
        $model = $execution->model;
        $context = $execution->context;
        $repeat = max(1, min(5, (int) $this->option('repeat')));
        $articles = Article::query()->whereIn('id', $articleIds)->get()->keyBy('id');
        if ($articles->count() !== count($articleIds) || ! KnowledgeBase::query()->whereKey($knowledgeBaseId)->exists()) {
            $this->components->error('One or more requested articles or the knowledge base do not exist.');

            return self::FAILURE;
        }

        $gold = [449 => 'blocked', 486 => 'blocked', 467 => 'passed', 471 => 'passed', 473 => 'passed'];
        $outputBase = $this->outputBasePath();
        try {
            $checkpoint = $this->comparisonCheckpoints->claim(
                $outputBase,
                $context->requestId,
                [
                    'article_ids' => $articleIds,
                    'knowledge_base_id' => $knowledgeBaseId,
                    'requested_model_id' => $context->requestedModelId,
                    'admin_id' => $context->adminId,
                    'access_version' => $context->accessVersion,
                    'policy_version' => $context->policyVersion,
                    'repeat' => $repeat,
                    'compare' => $modes,
                ],
            );
        } catch (AiQualityComparisonCheckpointException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $calls = $checkpoint->calls;
            $completedKeys = collect($calls)->mapWithKeys(fn (array $call): array => [$call['attempt'].'|'.$call['article_id'].'|'.$call['mode'] => true]);
            $totalCalls = count($articleIds) * 2 * $repeat;
            $this->components->warn("Live comparison will perform {$totalCalls} provider calls.");
            foreach (range(1, $repeat) as $attempt) {
                $orderedModes = $attempt % 2 === 0 ? ['knowledge', 'atomic'] : ['atomic', 'knowledge'];
                foreach ($articleIds as $articleId) {
                    $article = $articles->get($articleId);
                    $snapshot = [
                        'title' => (string) $article->title,
                        'excerpt' => (string) ($article->excerpt ?? ''),
                        'content' => (string) ($article->content ?? ''),
                        'keywords' => (string) ($article->keywords ?? ''),
                        'meta_description' => (string) ($article->meta_description ?? ''),
                    ];
                    foreach ($orderedModes as $mode) {
                        $callKey = $attempt.'|'.$articleId.'|'.$mode;
                        if ($completedKeys->has($callKey)) {
                            continue;
                        }
                        $prediction = null;
                        foreach ([1, 2] as $providerAttempt) {
                            try {
                                $prediction = $this->evaluateLiveCase(
                                    $this->comparisonCase($snapshot, $knowledgeBaseId, $mode),
                                    $execution,
                                );
                                $model = $execution->model;
                                break;
                            } catch (AiModelAccessException $exception) {
                                return $this->failLiveAccess($exception);
                            } catch (\Throwable) {
                                if ($providerAttempt === 1) {
                                    $this->components->warn("Transient model failure for article {$articleId} ({$mode}); retrying once.");
                                }
                            }
                        }
                        if (! is_array($prediction)) {
                            return $this->failLive(AiModelAccessException::AI_MODEL_UNAVAILABLE);
                        }
                        $calls[] = array_replace($prediction, [
                            'article_id' => $articleId,
                            'article_title' => (string) $article->title,
                            'mode' => $mode,
                            'attempt' => $attempt,
                            'expected_decision' => $gold[$articleId] ?? 'needs_review',
                        ]);
                        $this->comparisonCheckpoints->persist($checkpoint, $calls);
                        $this->line(sprintf('[%d/%d] article=%d mode=%s decision=%s tokens=%d latency=%dms', count($calls), $totalCalls, $articleId, $mode, $prediction['decision'], $prediction['prompt_tokens'] + $prediction['completion_tokens'], $prediction['latency_ms']));
                    }
                }
            }

            $modelsUsed = $this->modelsUsed($calls);
            $modelUsageMode = $this->modelUsageMode($modelsUsed);
            $singleModel = $modelUsageMode === 'single' ? $modelsUsed[0] : null;
            $metrics = $this->comparisonMetrics($calls, $articleIds, $repeat);
            $knowledgeBase = KnowledgeBase::query()->with('factLibrary.activeRevision')->findOrFail($knowledgeBaseId);
            $caseSetMatches = $knowledgeBaseId === 23
                && ($singleModel['id'] ?? null) === 3
                && $repeat === 3
                && $articleIds === self::LOCAL_ATOMIC_CASE_IDS;
            $report = [
                'schema_version' => 2,
                'generated_at' => now()->toIso8601String(),
                'mode' => 'live',
                'evaluation_scope' => 'local_atomic_comparison',
                'model_usage_mode' => $modelUsageMode,
                'model' => $singleModel === null ? null : [
                    'id' => $singleModel['id'],
                    'name' => $singleModel['name'],
                    'source' => $singleModel['source'],
                ],
                'models_used' => $modelsUsed,
                'execution' => array_merge(
                    $context->toSafeArray(),
                    [
                        'model_id' => $singleModel['id'] ?? null,
                        'model_source' => $singleModel['source'] ?? $modelUsageMode,
                    ],
                ),
                'knowledge_base_id' => $knowledgeBaseId,
                'case_set' => [
                    'version' => self::LOCAL_ATOMIC_CASE_SET_VERSION,
                    'article_ids' => $articleIds,
                    'sha256' => hash('sha256', self::LOCAL_ATOMIC_CASE_SET_VERSION.'|'.implode(',', $articleIds)),
                ],
                'atomic_revision' => [
                    'id' => $knowledgeBase->factLibrary?->active_revision_id,
                    'library_hash' => $knowledgeBase->factLibrary?->active_hash,
                    'source_hash' => $knowledgeBase->factLibrary?->source_hash,
                    'serving_status' => $knowledgeBase->factLibrary?->serving_status,
                ],
                'metrics' => $metrics,
                'local_atomic_gate_ready' => $caseSetMatches && (bool) data_get($metrics, 'gate_checks.all_passed', false),
                'calls' => $calls,
            ];
            try {
                $this->publishLiveReport(
                    $context,
                    $model,
                    $outputBase,
                    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
                    $this->comparisonMarkdown($report),
                );
            } catch (AiModelAccessException $exception) {
                return $this->failLiveAccess($exception);
            } catch (\Throwable) {
                return $this->failLive(AiModelAccessException::AI_MODEL_UNAVAILABLE);
            }
            $this->comparisonCheckpoints->complete($checkpoint);
            $this->components->info('Article comparison completed.');
            $this->line('JSON: '.$outputBase.'.json');
            $this->line('Markdown: '.$outputBase.'.md');

            return self::SUCCESS;
        } finally {
            $checkpoint->release();
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function comparisonCase(array $snapshot, int $knowledgeBaseId, string $mode): array
    {
        $facts = $this->factExtractor->extract($snapshot, 1000);
        if ($mode === 'knowledge') {
            $built = $this->evidenceBuilder->build([$knowledgeBaseId], $snapshot, $facts, 12, 6000, 6);

            return ['article' => $snapshot, 'facts' => $built['fact_candidates'], 'evidence' => $built['evidence'], 'inspection_scope' => 'full'];
        }

        $inspection = $this->atomicFactInspector->inspect($snapshot['title']."\n".$snapshot['content'], [$knowledgeBaseId]);
        $fallbackSnapshot = array_replace($snapshot, ['content' => (string) ($inspection['fallback_content'] ?? '')]);
        $fallbackFacts = $this->factExtractor->extract($fallbackSnapshot, 1000);
        $built = $this->evidenceBuilder->build([$knowledgeBaseId], $fallbackSnapshot, $fallbackFacts, 12, 6000, 6);

        return [
            'article' => $fallbackSnapshot,
            'facts' => $built['fact_candidates'],
            'evidence' => $built['evidence'],
            'atomic_inspection' => $inspection,
            'inspection_scope' => 'full',
        ];
    }

    /** @param list<array<string,mixed>> $calls @param list<int> $articleIds @return array<string,mixed> */
    private function comparisonMetrics(array $calls, array $articleIds, int $repeat): array
    {
        $byMode = [];
        foreach (['atomic', 'knowledge'] as $mode) {
            $rows = array_values(array_filter($calls, fn (array $call): bool => $call['mode'] === $mode));
            $prompt = array_map(fn (array $call): int => $call['prompt_tokens'], $rows);
            $completion = array_map(fn (array $call): int => $call['completion_tokens'], $rows);
            $tokens = array_map(fn (array $call): int => $call['prompt_tokens'] + $call['completion_tokens'], $rows);
            $latency = array_map(fn (array $call): int => $call['latency_ms'], $rows);
            $correct = count(array_filter($rows, fn (array $call): bool => $call['decision'] === $call['expected_decision']));
            $byMode[$mode] = [
                'call_count' => count($rows),
                'decision_accuracy' => count($rows) > 0 ? round($correct / count($rows), 4) : 0,
                'prompt_tokens' => $this->distribution($prompt),
                'completion_tokens' => $this->distribution($completion),
                'total_tokens' => $this->distribution($tokens),
                'latency_ms' => $this->distribution($latency),
            ];
        }
        $stable = true;
        foreach ($articleIds as $articleId) {
            foreach (['atomic', 'knowledge'] as $mode) {
                $rows = array_filter($calls, fn (array $call): bool => $call['article_id'] === $articleId && $call['mode'] === $mode);
                $stable = $stable && count(array_unique(array_column($rows, 'decision'))) === 1;
            }
        }
        $atomicRows = array_values(array_filter($calls, fn (array $call): bool => $call['mode'] === 'atomic'));
        $conflictHits = count(array_filter($atomicRows, fn (array $call): bool => in_array($call['article_id'], [449, 486], true) && $call['decision'] === 'blocked'));
        $conflictRecall = $conflictHits / max(1, 2 * $repeat);
        $safeBlocks = count(array_filter($atomicRows, fn (array $call): bool => in_array($call['article_id'], [467, 471, 473], true) && $call['decision'] === 'blocked'));
        $promptReduction = $byMode['knowledge']['prompt_tokens']['total'] > 0 ? 1 - ($byMode['atomic']['prompt_tokens']['total'] / $byMode['knowledge']['prompt_tokens']['total']) : 0.0;
        $checks = [
            'all_decisions_match' => count(array_filter($calls, fn (array $call): bool => $call['decision'] === $call['expected_decision'])) === count($calls),
            'conflict_recall_100' => $conflictRecall >= 0.9999,
            'safe_false_blocks_zero' => $safeBlocks === 0,
            'decision_stability_100' => $stable,
            'atomic_prompt_token_reduction_35' => $promptReduction >= 0.35,
            'atomic_p50_latency_not_slower' => $byMode['atomic']['latency_ms']['p50'] <= $byMode['knowledge']['latency_ms']['p50'],
            'score_issue_decision_consistent' => ! collect($calls)->contains(fn (array $call): bool => $call['decision'] === 'passed' && collect($call['issues'] ?? [])->contains(fn ($issue): bool => in_array($issue['severity'] ?? '', ['critical', 'high'], true))),
        ];
        $checks['all_passed'] = ! in_array(false, $checks, true);

        return [
            'article_count' => count($articleIds), 'call_count' => count($calls), 'repeat' => $repeat,
            'by_mode' => $byMode, 'atomic_prompt_token_reduction' => round($promptReduction, 4),
            'conflict_recall' => round($conflictRecall, 4), 'safe_false_block_count' => $safeBlocks,
            'decision_stability' => $stable ? 1.0 : 0.0, 'gate_checks' => $checks,
        ];
    }

    /** @param list<int> $values @return array<string,int|float> */
    private function distribution(array $values): array
    {
        return ['total' => array_sum($values), 'average' => $values === [] ? 0 : round(array_sum($values) / count($values), 2), 'p50' => $this->percentile($values, 50), 'p95' => $this->percentile($values, 95)];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{id:int,name:string,source:string,call_count:int}>
     */
    private function modelsUsed(array $rows): array
    {
        $models = [];
        foreach ($rows as $row) {
            $modelId = (int) ($row['resolved_model_id'] ?? 0);
            $source = (string) ($row['resolved_model_source'] ?? '');
            if ($modelId <= 0 || ! in_array($source, ['personal', 'shared'], true)) {
                continue;
            }
            $key = $modelId.'|'.$source;
            if (! isset($models[$key])) {
                $name = trim((string) ($row['resolved_model_name'] ?? ''));
                $models[$key] = [
                    'id' => $modelId,
                    'name' => mb_substr($name, 0, 200),
                    'source' => $source,
                    'call_count' => 0,
                ];
            }
            $models[$key]['call_count']++;
        }

        return array_values($models);
    }

    /** @param list<array{id:int,name:string,source:string,call_count:int}> $modelsUsed */
    private function modelUsageMode(array $modelsUsed): string
    {
        return match (count($modelsUsed)) {
            0 => 'none',
            1 => 'single',
            default => 'mixed',
        };
    }

    /** @param array<string,mixed> $report */
    private function comparisonMarkdown(array $report): string
    {
        $metrics = $report['metrics'];
        $rows = ['# KB'.$report['knowledge_base_id'].' 原子事实与完整知识库质检实测', '', '- 运行时间：'.$report['generated_at'], '- 模型使用模式：'.$report['model_usage_mode'], '- 调用数：'.$metrics['call_count'], '- 本地原子通道门禁：'.($report['local_atomic_gate_ready'] ? '通过' : '未通过')];
        foreach ($report['models_used'] as $model) {
            $rows[] = '- 模型：'.$model['name'].'（ID '.$model['id'].'，'.$model['source'].'，'.$model['call_count'].' 次）';
        }
        $rows = array_merge($rows, ['', '## 汇总', '', '| 模式 | 准确率 | 输入 Token 总量 / P50 / P95 | 总 Token 总量 | 延迟 P50 / P95 |', '| --- | ---: | ---: | ---: | ---: |']);
        foreach (['atomic' => '原子混合', 'knowledge' => '完整知识库'] as $mode => $label) {
            $item = $metrics['by_mode'][$mode];
            $rows[] = '| '.$label.' | '.number_format($item['decision_accuracy'] * 100, 2).'% | '.$item['prompt_tokens']['total'].' / '.$item['prompt_tokens']['p50'].' / '.$item['prompt_tokens']['p95'].' | '.$item['total_tokens']['total'].' | '.$item['latency_ms']['p50'].' / '.$item['latency_ms']['p95'].' ms |';
        }
        $rows = array_merge($rows, ['', '## 逐次结果', '', '| 文章 | 模式 | 次数 | 模型 | 期望 | 决策 | 分数 | 输入 / 输出 Token | 耗时 |', '| --- | --- | ---: | --- | --- | --- | ---: | ---: | ---: |']);
        foreach ($report['calls'] as $call) {
            $rows[] = '| '.$call['article_id'].'《'.str_replace('|', '｜', $call['article_title']).'》 | '.$call['mode'].' | '.$call['attempt'].' | '.($call['resolved_model_name'] ?? '').' #'.($call['resolved_model_id'] ?? '').' ('.($call['resolved_model_source'] ?? '').') | '.$call['expected_decision'].' | '.$call['decision'].' | '.$call['score'].' | '.$call['prompt_tokens'].' / '.$call['completion_tokens'].' | '.$call['latency_ms'].' ms |';
        }
        $rows = array_merge($rows, ['', '## 门禁', '']);
        foreach ($metrics['gate_checks'] as $name => $passed) {
            if ($name !== 'all_passed') {
                $rows[] = '- '.($passed ? '通过' : '未通过').'：'.$name;
            }
        }

        return implode("\n", $rows)."\n";
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

    private function liveExecution(string $source): ?DirectAdminAiExecutionState
    {
        $adminId = (int) $this->option('admin');
        if ($adminId <= 0) {
            $this->components->error(AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE);

            return null;
        }
        $admin = Admin::query()->find($adminId);
        if (! $admin instanceof Admin) {
            $this->components->error(AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE);

            return null;
        }

        try {
            $context = $this->executionGuard->freeze(
                $admin,
                $source,
                $adminId,
                requestedModelId: (int) $this->option('model') > 0
                    ? (int) $this->option('model')
                    : null,
            );
            $selection = $this->executionGuard->resolveModel($context);

            return new DirectAdminAiExecutionState(
                $context,
                $selection['model'],
                $selection['source'],
            );
        } catch (AiModelAccessException $exception) {
            $this->components->error($exception->getErrorCode());

            return null;
        } catch (AiModelRuntimeEligibilityException) {
            $this->components->error(AiModelAccessException::AI_MODEL_UNAVAILABLE);

            return null;
        }
    }

    /** @return array<string,mixed> */
    private function reviewLive(
        DirectAdminAiExecutionState $execution,
        string $instructions,
    ): array {
        $timeoutSeconds = max(1, (int) config('geoflow.ai_quality_request_timeout_seconds', 160));
        $invocation = $this->invocationGateway->acquire(
            $execution->context,
            $timeoutSeconds + 60,
            function (AiModel $candidate): void {
                $this->assertQualityCandidateReady($candidate);
            },
        );
        $execution->adopt($invocation);
        $usageSession = null;

        try {
            $currentModel = $invocation->model;
            if ($this->reviewer instanceof ProviderAttemptAwareArticleAiQualityReviewer) {
                $usageSession = new ArticleAiQualityProviderUsageSession(
                    fn (string $mode) => $invocation->newProviderUsageAttempt(
                        requestPayload: $instructions,
                        operation: 'article_quality.review.'.$mode,
                        businessSource: 'ai_quality_live_cli',
                        sourceType: 'ai_quality_evaluation',
                        sourceId: $execution->context->sourceId,
                        callKey: $execution->nextUsageCallKey(),
                    ),
                );
                $review = $this->reviewer->reviewWithinReservedVersionTrackingProviderAttempts(
                    $currentModel,
                    $instructions,
                    $timeoutSeconds,
                    'fast_v2',
                    $invocation->reservation,
                    $usageSession,
                    true,
                );
            } elseif ($this->reviewer instanceof PreReservedArticleAiQualityReviewer) {
                $invocation->beginUsageAttempt(
                    requestPayload: $instructions,
                    operation: 'article_quality.review',
                    businessSource: 'ai_quality_live_cli',
                    sourceType: 'ai_quality_evaluation',
                    sourceId: $execution->context->sourceId,
                    callKey: $execution->nextUsageCallKey(),
                );
                $review = $this->reviewer->reviewWithinReservedVersion(
                    $currentModel,
                    $instructions,
                    $timeoutSeconds,
                    'fast_v2',
                    $invocation->reservation,
                    true,
                );
            } elseif ($this->reviewer instanceof VersionAwareArticleAiQualityReviewer) {
                $invocation->beginUsageAttempt(
                    requestPayload: $instructions,
                    operation: 'article_quality.review',
                    businessSource: 'ai_quality_live_cli',
                    sourceType: 'ai_quality_evaluation',
                    sourceId: $execution->context->sourceId,
                    callKey: $execution->nextUsageCallKey(),
                );
                $review = $this->reviewer->reviewWithinVersion(
                    $currentModel,
                    $instructions,
                    $timeoutSeconds,
                    'fast_v2',
                );
            } else {
                $invocation->beginUsageAttempt(
                    requestPayload: $instructions,
                    operation: 'article_quality.review',
                    businessSource: 'ai_quality_live_cli',
                    sourceType: 'ai_quality_evaluation',
                    sourceId: $execution->context->sourceId,
                    callKey: $execution->nextUsageCallKey(),
                );
                $review = $this->reviewer->review($currentModel, $instructions);
            }
            $this->executionGuard->assertModelCurrent($execution->context, $currentModel);
            $invocation->recordSuccess($review['usage'] ?? null);
            $usageSession?->succeeded();

            return $review;
        } catch (AiModelAccessException $exception) {
            $usageSession?->revoked($exception->getErrorCode());
            $invocation->recordRevoked($exception->getErrorCode());

            throw $exception;
        } catch (\Throwable $exception) {
            $usageSession?->discarded('ai_result_not_committed');
            $invocation->recordProviderFailure();

            throw $exception;
        } finally {
            $invocation->close();
        }
    }

    private function assertQualityCandidateReady(AiModel $model): void
    {
        try {
            $this->qualityCircuitBreaker->beforeRequest($model);
        } catch (ArticleAiQualityRuntimeException $exception) {
            if ($exception->safeCode() === 'provider_circuit_open' && $exception->retryable()) {
                throw AiModelRuntimeEligibilityException::health();
            }

            throw $exception;
        }
    }

    private function publishLiveReport(
        DirectAdminAiExecutionContext $context,
        AiModel $model,
        string $outputBase,
        string $json,
        string $markdown,
    ): void {
        $this->executionGuard->assertModelCurrent($context, $model);

        $parentDirectory = dirname($outputBase);
        $temporaryDirectory = $parentDirectory.'/.'.basename($outputBase).'.'.$context->requestId.'.tmp';
        $temporaryJson = $temporaryDirectory.'/report.json';
        $temporaryMarkdown = $temporaryDirectory.'/report.md';
        $finalJson = $outputBase.'.json';
        $finalMarkdown = $outputBase.'.md';
        File::ensureDirectoryExists($temporaryDirectory);

        try {
            File::put($temporaryJson, $json);
            $this->executionGuard->assertModelCurrent($context, $model);
            File::put($temporaryMarkdown, $markdown);
            $this->executionGuard->assertModelCurrent($context, $model);

            $hadJson = File::isFile($finalJson);
            $hadMarkdown = File::isFile($finalMarkdown);
            $previousJson = $hadJson ? (string) File::get($finalJson) : '';
            $previousMarkdown = $hadMarkdown ? (string) File::get($finalMarkdown) : '';
            $published = false;

            try {
                File::replace($finalJson, (string) File::get($temporaryJson));
                File::replace($finalMarkdown, (string) File::get($temporaryMarkdown));
                $this->executionGuard->assertModelCurrent($context, $model);
                $published = true;
            } finally {
                if (! $published) {
                    $hadJson ? File::replace($finalJson, $previousJson) : File::delete($finalJson);
                    $hadMarkdown ? File::replace($finalMarkdown, $previousMarkdown) : File::delete($finalMarkdown);
                }
            }
        } finally {
            File::deleteDirectory($temporaryDirectory);
        }
    }

    private function failLiveAccess(AiModelAccessException $exception): int
    {
        return $this->failLive($exception->getErrorCode());
    }

    private function failLive(string $errorCode): int
    {
        $this->components->error($errorCode);

        return self::FAILURE;
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
