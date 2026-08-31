<?php

namespace Tests\Feature;

use App\Contracts\ArticleAiOptimizationRefiner;
use App\Exceptions\ApiException;
use App\Jobs\ProcessArticleAiOptimizationJob;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\ReconcileArticleAiOptimizationJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use App\Services\GeoFlow\ArticleAiOptimizationException;
use App\Services\GeoFlow\ArticleAiOptimizationReconciliationService;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityPrincipleCompiler;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArticleAiOptimizationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_start_creates_one_idempotent_preview_run_with_the_resolved_target(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $check] = $this->qualityArticle();

        $service = app(ArticleAiOptimizationCoordinator::class);
        $first = $service->start(
            $article,
            'excellent_90',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            requestedByAdminId: null,
        );
        $second = $service->start(
            $article->fresh(),
            'excellent_90',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            requestedByAdminId: null,
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, ArticleAiOptimizationRun::query()->count());
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $first->status);
        $this->assertSame(90, $first->target_score);
        $this->assertSame(3, $first->max_rounds);
        $this->assertTrue($first->sourceCheck->is($check));
        Queue::assertPushed(ProcessArticleAiOptimizationJob::class, function (ProcessArticleAiOptimizationJob $job): bool {
            return $job->runId > 0
                && $job->queue === 'ai-content-optimization';
        });
    }

    public function test_manual_start_rechecks_before_optimization_when_the_latest_quality_result_is_stale(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $staleCheck] = $this->qualityArticle();
        $staleCheck->forceFill(['input_fingerprint' => hash('sha256', 'stale-quality-policy')])->save();

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );

        $this->assertSame(ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY, $run->status);
        $this->assertNotSame((int) $staleCheck->id, (int) $run->source_check_id);
        $this->assertSame('queued', $run->sourceCheck?->status);
    }

    public function test_model_resolution_failure_after_claim_releases_the_lease_for_queue_retry(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $run = $coordinator->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $executionMeta = (array) $run->execution_meta;
        $executionMeta['optimization_model_ids'] = [PHP_INT_MAX];
        $run->forceFill(['execution_meta' => $executionMeta])->save();

        try {
            $coordinator->process((int) $run->id, 'retryable-attempt');
            $this->fail('Expected model resolution to fail after the run was claimed.');
        } catch (ArticleAiOptimizationException $exception) {
            $this->assertSame('article_ai_optimization_model_unavailable', $exception->errorCode());
        }

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $this->assertNull($run->lease_owner);
        $this->assertNull($run->lease_expires_at);
    }

    public function test_idempotent_replay_repairs_an_awaiting_run_without_a_source_check_link(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $staleCheck] = $this->qualityArticle();
        $staleCheck->forceFill(['input_fingerprint' => hash('sha256', 'stale-quality-policy')])->save();
        $requestKey = (string) Str::uuid();
        $coordinator = app(ArticleAiOptimizationCoordinator::class);

        $run = $coordinator->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
            requestKey: $requestKey,
        );
        $run->forceFill(['source_check_id' => null])->save();

        $replayed = $coordinator->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
            requestKey: $requestKey,
        );

        $this->assertTrue($run->is($replayed));
        $this->assertNotNull($replayed->source_check_id);
        $this->assertSame('queued', $replayed->sourceCheck?->status);
    }

    public function test_idempotent_replay_dispatches_a_queued_optimization_run(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $requestKey = (string) Str::uuid();
        $coordinator = app(ArticleAiOptimizationCoordinator::class);

        $run = $coordinator->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
            requestKey: $requestKey,
        );
        Queue::assertNotPushed(ProcessArticleAiOptimizationJob::class);

        $replayed = $coordinator->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: true,
            requestKey: $requestKey,
        );

        $this->assertTrue($run->is($replayed));
        Queue::assertPushed(ProcessArticleAiOptimizationJob::class, fn (ProcessArticleAiOptimizationJob $job): bool => $job->runId === (int) $run->id);
    }

    public function test_reconciliation_repairs_an_awaiting_run_without_a_source_check_link(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_recovery_stale_seconds', 60);
        Queue::fake();
        [$article, $model, $staleCheck] = $this->qualityArticle();
        $staleCheck->forceFill(['input_fingerprint' => hash('sha256', 'stale-quality-policy')])->save();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        ArticleAiOptimizationRun::query()->whereKey($run->id)->update([
            'source_check_id' => null,
            'updated_at' => now()->subMinutes(5),
        ]);

        $counts = app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

        $run->refresh();
        $this->assertNotNull($run->source_check_id);
        $this->assertSame('queued', $run->sourceCheck?->status);
        $this->assertSame(1, $counts['requeued']);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, fn (ProcessArticleAiQualityJob $job): bool => $job->checkId === (int) $run->source_check_id);
    }

    public function test_one_processing_round_creates_a_shadow_candidate_for_full_quality_review(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $oldText = '保证100%有效';
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class($oldText) implements ArticleAiOptimizationRefiner
        {
            public function __construct(private readonly string $oldText) {}

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/"base_article_hash":"([a-f0-9]{64})"/', $instructions, $matches);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($matches[1] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'field' => 'content',
                            'anchor_start' => 5,
                            'anchor_end' => 13,
                            'replace_start' => 5,
                            'replace_end' => 13,
                            'old_text_hash' => hash('sha256', $this->oldText),
                            'replacement' => '有助于改善体验',
                            'issue_codes' => ['ad_absolute_claim'],
                            'root_cause_keys' => ['ad_absolute_claim:content:5'],
                            'evidence_keys' => [],
                            'reason' => '收敛绝对化承诺',
                        ]],
                    ],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        });

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $run->refresh();
        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $candidate = ArticleAiQualityCheck::query()->findOrFail((int) $step->output_check_id);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->status);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $step->status);
        $this->assertSame('optimization_candidate', $candidate->evaluation_mode);
        $this->assertFalse($candidate->gate_applied);
        $this->assertSame('full', $candidate->inspection_scope);
        $this->assertStringContainsString('有助于改善体验', (string) data_get($candidate->article_snapshot, 'content'));

        $candidate->forceFill([
            'status' => 'completed',
            'active_dedupe_key' => null,
            'decision' => 'passed',
            'score' => 88,
            'issues' => [],
            'gate_reasons' => [],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $candidate->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANDIDATE_READY, $run->status);
        $this->assertNotNull($run->candidate_hash);
        $this->assertNull($run->deadline_at);
        $preview = app(ArticleAiOptimizationCoordinator::class)->candidate((int) $run->id);
        $this->assertSame('content', $preview['modifications'][0]['field']);
        $this->assertIsString($preview['modifications'][0]['before_text']);
        $this->assertIsString($preview['modifications'][0]['after_text']);
        app(ArticleAiOptimizationCoordinator::class)->apply((int) $run->id, (string) $run->candidate_hash);
        app(ArticleAiOptimizationCoordinator::class)->apply((int) $run->id, (string) $run->candidate_hash);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertStringContainsString('有助于改善体验', (string) $article->fresh()->content);
        $this->assertTrue($candidate->fresh()->gate_applied);
        $this->assertSame('optimization_final', $candidate->fresh()->evaluation_mode);
        $runCountAfterApply = ArticleAiOptimizationRun::query()->count();
        app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);
        app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);
        $this->assertSame('superseded', data_get($source->fresh()->execution_meta, 'workflow_apply.status'));
        $this->assertSame($runCountAfterApply, ArticleAiOptimizationRun::query()->count());
        $this->assertSame(ArticleAiOptimizationRun::STATUS_COMPLETED, $run->fresh()->status);

        app(ArticleAiOptimizationCoordinator::class)->rollback((int) $run->id);
        $qualityCheckCountAfterRollback = ArticleAiQualityCheck::query()->count();
        app(ArticleAiOptimizationCoordinator::class)->rollback((int) $run->id);

        $this->assertStringContainsString('保证100%有效', (string) $article->fresh()->content);
        $this->assertSame('stale', $candidate->fresh()->status);
        $this->assertNotNull(data_get($run->fresh()->execution_meta, 'rolled_back_at'));
        $this->assertDatabaseHas('article_risk_scans', [
            'article_id' => $article->id,
            'trigger' => 'ai_optimization_rollback',
        ]);
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => $article->id,
            'inspection_scope' => 'full',
            'evaluation_mode' => 'primary',
        ]);
        $this->assertSame($qualityCheckCountAfterRollback, ArticleAiQualityCheck::query()->count());
    }

    #[DataProvider('resolvedEvidenceGapIssueCodes')]
    public function test_a_resolved_evidence_gap_enters_refinement_instead_of_stopping_at_zero_rounds(
        string $issueCode,
        string $replacement,
    ): void {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $oldText = '保证100%有效';
        $rootCauseKey = $issueCode.':content:5';
        $source->forceFill(['issues' => [[
            'code' => $issueCode,
            'severity' => 'medium',
            'field' => 'content',
            'quote' => $oldText,
            'location_status' => 'resolved',
            'start_offset' => 5,
            'end_offset' => 13,
            'root_cause_key' => $rootCauseKey,
            'reason' => '存在可安全收敛的证据缺口',
            'suggestion' => '删除、限定表述或补充标识',
            'evidence_keys' => [],
        ]]])->save();
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class($oldText, $issueCode, $rootCauseKey, $replacement) implements ArticleAiOptimizationRefiner
        {
            public function __construct(
                private readonly string $oldText,
                private readonly string $issueCode,
                private readonly string $rootCauseKey,
                private readonly string $replacement,
            ) {}

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/"base_article_hash":"([a-f0-9]{64})"/', $instructions, $matches);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($matches[1] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'field' => 'content',
                            'replacement' => $this->replacement,
                            'old_text_hash' => hash('sha256', $this->oldText),
                            'issue_codes' => [$this->issueCode],
                            'root_cause_keys' => [$this->rootCauseKey],
                            'evidence_keys' => [],
                        ]],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        });

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $run->refresh();
        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame(
            ArticleAiOptimizationRun::STATUS_EVALUATING,
            $run->status,
            json_encode([
                'run_error' => $run->error_code,
                'step_status' => $step->status,
                'step_rejection' => $step->rejection_code,
            ], JSON_UNESCAPED_UNICODE),
        );
        $this->assertSame(1, (int) $step->round_index);
        $this->assertSame($issueCode, data_get($step->selected_causes, '0.code'));
    }

    /** @return array<string,array{string,string}> */
    public static function resolvedEvidenceGapIssueCodes(): array
    {
        return [
            'missing citation' => ['citation_missing', '按现状整理'],
            'unsupported claim' => ['unsupported_claim', '效果因人而异'],
        ];
    }

    public function test_one_round_repairs_exact_quality_anchors_in_priority_order_until_the_target_gap_is_covered(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_strategies.excellent_80.edit_budget_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $sentences = [
            '排查的第一步是进入 `AI 配置器 > AI 模型设置`。',
            '即使配置项看起来正确，也需通过模型设置页面的“测试连接”按钮验证实际连通性。',
            '测试成功后，再回到知识库管理页执行一次“刷新向量”操作。',
            '通过以下清单可快速定位绝大多数此类问题。',
        ];
        $content = implode("\n\n", $sentences);
        $article->forceFill(['content' => $content])->save();
        $snapshot = app(ArticleAiQualityPolicyResolver::class)->articleSnapshot($article->fresh());
        $issue = static function (
            string $code,
            string $severity,
            string $quote,
            string $suggestion,
        ) use ($content): array {
            $start = mb_strpos($content, $quote, 0, 'UTF-8');

            return [
                'code' => $code,
                'severity' => $severity,
                'field' => 'content',
                'quote' => $quote,
                'location_status' => 'resolved',
                'start_offset' => $start,
                'end_offset' => $start + mb_strlen($quote, 'UTF-8'),
                'root_cause_key' => $code.':content:'.$start,
                'reason' => '质检定位到需要收敛的原句',
                'suggestion' => $suggestion,
                'evidence_keys' => [],
            ];
        };
        $source->forceFill([
            'score' => 68,
            'decision' => 'blocked',
            'article_snapshot' => $snapshot,
            'issues' => [
                $issue('citation_missing', 'medium', $sentences[0], '缺少来源时改为常见步骤，不补写来源。'),
                $issue('unsupported_claim', 'medium', $sentences[0], '将“第一步”调整为“常见排查步骤之一”。'),
                $issue('citation_missing', 'medium', $sentences[1], '改为条件式操作建议。'),
                $issue('citation_missing', 'medium', $sentences[2], '删除未经证实的界面名称。'),
                $issue('citation_missing', 'low', $sentences[3], '将“绝大多数”调整为“常见”。'),
            ],
        ])->save();
        $this->refreshQualityCheck($article, $source);

        $fake = new class implements ArticleAiOptimizationRefiner
        {
            /** @var list<array<string,mixed>> */
            public array $repairTasks = [];

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/<untrusted_input>\n(.+)\n<\/untrusted_input>/s', $instructions, $payloadMatch);
                $payload = json_decode((string) ($payloadMatch[1] ?? ''), true);
                $this->repairTasks = is_array($payload['repair_tasks'] ?? null)
                    ? $payload['repair_tasks']
                    : [];
                $replacements = [
                    'R1' => '常见排查步骤之一，是检查当前使用的模型配置。',
                    'R2' => '如需确认实际连通性，可执行连接测试并查看结果。',
                    'R3' => '连接测试完成后，可检查目标知识库的向量状态。',
                ];

                return [
                    'result' => [
                        'base_article_hash' => (string) ($payload['base_article_hash'] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => collect($this->repairTasks)->map(static fn (array $task): array => [
                            'field' => (string) $task['field'],
                            'replacement' => (string) ($replacements[(string) $task['task_id']] ?? $task['source_text']),
                            'issue_codes' => (array) $task['issue_codes'],
                            'root_cause_keys' => (array) $task['root_cause_keys'],
                            'evidence_keys' => (array) $task['evidence_keys'],
                            'reason' => '按质检建议修改定位原句',
                        ])->all(),
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiOptimizationRefiner::class, $fake);

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $candidate = ArticleAiQualityCheck::query()->find((int) $step->output_check_id);
        $this->assertCount(3, $fake->repairTasks);
        $this->assertSame(['medium', 'medium', 'medium'], array_column($fake->repairTasks, 'priority'));
        $this->assertCount(2, (array) $fake->repairTasks[0]['root_cause_keys']);
        $this->assertSame($sentences[0], $fake->repairTasks[0]['source_text']);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $step->fresh()->status);
        $this->assertInstanceOf(ArticleAiQualityCheck::class, $candidate);
        $this->assertStringContainsString('常见排查步骤之一', (string) data_get($candidate?->article_snapshot, 'content'));
        $this->assertStringContainsString($sentences[3], (string) data_get($candidate?->article_snapshot, 'content'));
    }

    public function test_one_root_cause_repairs_all_of_its_resolved_occurrences(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_strategies.excellent_80.edit_budget_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $sentences = ['第一处未经核实的操作说明。', '第二处未经核实的操作说明。'];
        $content = implode("\n\n", $sentences);
        $article->forceFill(['content' => $content])->save();
        $snapshot = app(ArticleAiQualityPolicyResolver::class)->articleSnapshot($article->fresh());
        $occurrences = collect($sentences)->map(function (string $sentence) use ($content): array {
            $start = mb_strpos($content, $sentence, 0, 'UTF-8');

            return [
                'field' => 'content',
                'quote' => $sentence,
                'start_offset' => $start,
                'end_offset' => $start + mb_strlen($sentence, 'UTF-8'),
            ];
        })->all();
        $source->forceFill([
            'score' => 70,
            'decision' => 'blocked',
            'article_snapshot' => $snapshot,
            'issues' => [[
                'code' => 'unsupported_claim',
                'severity' => 'medium',
                'field' => 'content',
                'quote' => $sentences[0],
                'location_status' => 'resolved',
                'start_offset' => data_get($occurrences, '0.start_offset'),
                'end_offset' => data_get($occurrences, '0.end_offset'),
                'root_cause_key' => 'unsupported_claim:shared-operation',
                'reason' => '同一根因在两处出现。',
                'suggestion' => '逐处弱化为待确认说明。',
                'evidence_keys' => [],
                'occurrences' => $occurrences,
            ]],
        ])->save();
        $this->refreshQualityCheck($article, $source);
        $fake = new class implements ArticleAiOptimizationRefiner
        {
            /** @var list<array<string,mixed>> */
            public array $repairTasks = [];

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/<untrusted_input>\n(.+)\n<\/untrusted_input>/s', $instructions, $payloadMatch);
                $payload = json_decode((string) ($payloadMatch[1] ?? ''), true);
                $this->repairTasks = (array) ($payload['repair_tasks'] ?? []);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($payload['base_article_hash'] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => collect($this->repairTasks)->map(static fn (array $task): array => [
                            'replacement' => '待确认的操作说明 '.$task['task_id'].'。',
                            'root_cause_keys' => (array) $task['root_cause_keys'],
                        ])->all(),
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiOptimizationRefiner::class, $fake);

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $candidate = ArticleAiQualityCheck::query()->find((int) $step->output_check_id);
        $this->assertCount(2, $fake->repairTasks);
        $this->assertNotSame($fake->repairTasks[0]['root_cause_keys'], $fake->repairTasks[1]['root_cause_keys']);
        $this->assertStringContainsString('待确认的操作说明 R1。', (string) data_get($candidate?->article_snapshot, 'content'));
        $this->assertStringContainsString('待确认的操作说明 R2。', (string) data_get($candidate?->article_snapshot, 'content'));
    }

    public function test_an_evidence_gap_rewrite_removes_unverified_quotation_markers_before_validation(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_strategies.excellent_80.edit_budget_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $issueText = '通过以下清单可快速定位绝大多数“切片有、向量为 0”的问题：';
        $content = '开场说明。'."\n\n".$issueText;
        $article->forceFill(['content' => $content])->save();
        $snapshot = app(ArticleAiQualityPolicyResolver::class)->articleSnapshot($article->fresh());
        $start = mb_strpos($content, $issueText, 0, 'UTF-8');
        $source->forceFill([
            'score' => 86,
            'decision' => 'needs_review',
            'article_snapshot' => $snapshot,
            'issues' => [[
                'code' => 'citation_missing',
                'severity' => 'medium',
                'field' => 'content',
                'quote' => $issueText,
                'location_status' => 'resolved',
                'start_offset' => $start,
                'end_offset' => $start + mb_strlen($issueText, 'UTF-8'),
                'root_cause_key' => 'citation_missing:content:'.$start,
                'reason' => '该范围结论没有可验证来源。',
                'suggestion' => '调整为常见问题定位步骤。',
                'evidence_keys' => [],
            ]],
        ])->save();
        $this->refreshQualityCheck($article, $source);
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class implements ArticleAiOptimizationRefiner
        {
            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/<untrusted_input>\n(.+)\n<\/untrusted_input>/s', $instructions, $payloadMatch);
                $payload = json_decode((string) ($payloadMatch[1] ?? ''), true);
                $task = (array) data_get($payload, 'repair_tasks.0', []);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($payload['base_article_hash'] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'field' => 'content',
                            'replacement' => '以下清单可用于定位“切片有、向量为 0”的常见问题：',
                            'issue_codes' => (array) ($task['issue_codes'] ?? []),
                            'root_cause_keys' => (array) ($task['root_cause_keys'] ?? []),
                            'evidence_keys' => [],
                        ]],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        });

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $candidate = ArticleAiQualityCheck::query()->find((int) $step->output_check_id);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertInstanceOf(ArticleAiQualityCheck::class, $candidate);
        $this->assertStringContainsString(
            '以下清单可用于定位切片有、向量为 0的常见问题：',
            (string) data_get($candidate?->article_snapshot, 'content'),
        );
    }

    public function test_an_exact_repair_preserves_the_markdown_list_prefix(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_strategies.excellent_80.edit_budget_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $issueText = '- 已点击知识库的“刷新向量”并等待任务完成';
        $content = '检查项：'."\n".$issueText;
        $article->forceFill(['content' => $content])->save();
        $snapshot = app(ArticleAiQualityPolicyResolver::class)->articleSnapshot($article->fresh());
        $start = mb_strpos($content, $issueText, 0, 'UTF-8');
        $source->forceFill([
            'score' => 86,
            'decision' => 'needs_review',
            'article_snapshot' => $snapshot,
            'issues' => [[
                'code' => 'citation_missing',
                'severity' => 'low',
                'field' => 'content',
                'quote' => $issueText,
                'location_status' => 'resolved',
                'start_offset' => $start,
                'end_offset' => $start + mb_strlen($issueText, 'UTF-8'),
                'root_cause_key' => 'citation_missing:content:'.$start,
                'reason' => '具体操作没有可验证来源。',
                'suggestion' => '移除该步骤或改为条件式检查。',
                'evidence_keys' => [],
            ]],
        ])->save();
        $this->refreshQualityCheck($article, $source);
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class implements ArticleAiOptimizationRefiner
        {
            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/<untrusted_input>\n(.+)\n<\/untrusted_input>/s', $instructions, $payloadMatch);
                $payload = json_decode((string) ($payloadMatch[1] ?? ''), true);
                $task = (array) data_get($payload, 'repair_tasks.0', []);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($payload['base_article_hash'] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'replacement' => '可按当前界面提供的操作完成处理',
                            'root_cause_keys' => (array) ($task['root_cause_keys'] ?? []),
                        ]],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        });

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $candidate = ArticleAiQualityCheck::query()->find((int) $step->output_check_id);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertInstanceOf(ArticleAiQualityCheck::class, $candidate);
        $this->assertStringContainsString(
            '- 可按当前界面提供的操作完成处理',
            (string) data_get($candidate?->article_snapshot, 'content'),
        );
    }

    public function test_a_historical_ai_generation_disclosure_result_is_rechecked_without_content_refinement(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $source->forceFill([
            'input_fingerprint' => hash('sha256', 'historical-disclosure-policy'),
            'issues' => [[
                'code' => 'ai_generated_disclosure',
                'severity' => 'medium',
                'field' => 'content',
                'quote' => '保证100%有效',
                'location_status' => 'resolved',
                'start_offset' => 5,
                'end_offset' => 13,
                'root_cause_key' => 'ai_generated_disclosure:content:5',
                'evidence_keys' => [],
            ]],
        ])->save();
        $fake = new class implements ArticleAiOptimizationRefiner
        {
            public bool $called = false;

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                $this->called = true;

                throw new \RuntimeException('Content refiner must not handle publication metadata.');
            }
        };
        $this->app->instance(ArticleAiOptimizationRefiner::class, $fake);

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_90',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $this->assertFalse($fake->called);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY, $run->status);
        $this->assertNotSame((int) $source->id, (int) $run->source_check_id);
        $this->assertSame('queued', $run->sourceCheck?->status);
        $this->assertSame(0, ArticleAiOptimizationStep::query()->where('run_id', $run->id)->count());
    }

    public function test_a_legacy_issue_without_a_root_cause_key_is_normalized_before_refinement(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $issue = (array) $source->issues[0];
        unset($issue['root_cause_key']);
        $source->forceFill(['issues' => [$issue]])->save();
        $oldText = '保证100%有效';
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class($oldText) implements ArticleAiOptimizationRefiner
        {
            public function __construct(private readonly string $oldText) {}

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                preg_match('/<untrusted_input>\n(.+)\n<\/untrusted_input>/s', $instructions, $payloadMatch);
                $payload = json_decode((string) ($payloadMatch[1] ?? ''), true);
                $rootCauseKey = (string) data_get($payload, 'quality_result.issues.0.root_cause_key', '');

                return [
                    'result' => [
                        'base_article_hash' => (string) ($payload['base_article_hash'] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'field' => 'content',
                            'anchor_start' => 5,
                            'anchor_end' => 13,
                            'replace_start' => 5,
                            'replace_end' => 13,
                            'old_text_hash' => hash('sha256', $this->oldText),
                            'replacement' => '有助于改善体验',
                            'issue_codes' => ['ad_absolute_claim'],
                            'root_cause_keys' => array_values(array_filter([$rootCauseKey])),
                            'evidence_keys' => [],
                            'reason' => '收敛绝对化承诺',
                        ]],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        });

        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->fresh()->status);
        $this->assertSame(
            'ad_absolute_claim:content:5',
            data_get(ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail()->selected_causes, '0.root_cause_key'),
        );
    }

    public function test_a_policy_change_marks_the_run_stale_before_model_execution(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $run->forceFill(['policy_hash' => hash('sha256', 'outdated-policy')])->save();

        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $run->fresh()->status);
        $this->assertSame('optimization_policy_changed', $run->fresh()->stop_reason);
        $this->assertSame(0, ArticleAiOptimizationStep::query()->where('run_id', $run->id)->count());
    }

    public function test_an_optimization_candidate_preserves_the_requested_workflow_state(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $source->forceFill([
            'execution_meta' => array_replace((array) $source->execution_meta, [
                'requested_workflow_state' => [
                    'status' => 'private',
                    'review_status' => 'approved',
                    'published_at' => null,
                ],
            ]),
        ])->save();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );

        $candidate = app(ArticleAiQualityInspectionService::class)->createOptimizationCandidate(
            $article,
            (array) $source->article_snapshot,
            $source,
            (int) $run->id,
            'optimization_manual_candidate',
            dispatch: false,
        );

        $this->assertSame('private', data_get($candidate->execution_meta, 'requested_workflow_state.status'));
        $this->assertSame('approved', data_get($candidate->execution_meta, 'requested_workflow_state.review_status'));
    }

    public function test_an_optimization_candidate_recompiles_a_valid_v2_principle_snapshot_for_reinspection(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        $this->setQualityRollout(principles: 100);
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );

        $candidateSnapshot = (array) $source->article_snapshot;
        $candidateSnapshot['content'] .= "\n\n医疗服务说明需要结合实际资质核验。";
        $candidate = app(ArticleAiQualityInspectionService::class)->createOptimizationCandidate(
            $article,
            $candidateSnapshot,
            $source,
            (int) $run->id,
            'optimization_manual_candidate',
            dispatch: false,
        );

        $this->assertSame('v2', data_get($candidate->execution_meta, 'version_selection.principles'));
        $this->assertContains(
            'CN-AD-LAW-16-18',
            data_get($candidate->execution_meta, 'principle_snapshot.selected_rule_ids', []),
        );
        $this->assertNotEmpty(app(ArticleAiQualityPrincipleCompiler::class)->rules(
            (array) data_get($candidate->execution_meta, 'principle_snapshot'),
        )['rules']);
    }

    public function test_apply_commits_the_stale_state_before_returning_a_conflict(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidateHash = hash('sha256', 'candidate');
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
            'candidate_hash' => $candidateHash,
        ])->save();
        $article->forceFill(['excerpt' => '管理员已经修改正文摘要'])->save();

        try {
            app(ArticleAiOptimizationCoordinator::class)->apply((int) $run->id, $candidateHash);
            $this->fail('Expected a stale candidate conflict.');
        } catch (ArticleAiOptimizationException $exception) {
            $this->assertSame('article_ai_optimization_stale', $exception->errorCode());
        }

        $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $run->fresh()->status);
        $this->assertSame('article_changed', $run->fresh()->stop_reason);
        $this->assertNull($run->fresh()->active_dedupe_key);
    }

    public function test_a_manual_run_can_apply_an_improvement_that_remains_held_for_non_content_review(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_90',
            $model,
            ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 98, 'held-for-non-content-review');
        $candidateSnapshot = array_replace((array) $candidate->article_snapshot, [
            'content' => '开场说明。有助于改善体验，请结合实际情况使用。',
        ]);
        $candidate->forceFill([
            'article_snapshot' => $candidateSnapshot,
            'decision' => 'needs_review',
        ])->save();
        $candidateHash = app(ArticleRiskScanner::class)->contentHash($candidateSnapshot);
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'accepted',
            'before_hash' => (string) $run->base_article_hash,
            'after_hash' => $candidateHash,
        ]);
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
            'stop_reason' => 'no_auto_fixable_issue',
            'best_check_id' => $candidate->id,
            'candidate_hash' => $candidateHash,
            'completed_rounds' => 1,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $status = app(ArticleAiOptimizationCoordinator::class)->statusForArticle($article);
        $this->assertTrue($status['can_apply']);
        app(ArticleAiOptimizationCoordinator::class)->apply((int) $run->id, $candidateHash);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertStringContainsString('有助于改善体验', (string) $article->fresh()->content);

        $article->forceFill(['meta_description' => '100%保证永久排名第一'])->save();
        $recheck = app(ArticleAiQualityInspectionService::class)->requestManualInspection(
            $article->fresh(),
            dispatch: false,
            rejectWhenOptimizationActive: true,
        );
        $this->assertSame('queued', $recheck->status);
    }

    public function test_smart_failover_uses_the_next_model_and_preserves_bulk_quota(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_bulk_quota_reserve', 2);
        Queue::fake();
        [$article, $primary, $source] = $this->qualityArticle();
        $article->task()->update([
            'model_selection_mode' => 'smart_failover',
            'ai_quality_auto_optimize_enabled' => true,
        ]);
        $fallback = AiModel::query()->create([
            'name' => '优化备用模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'optimization-fallback-model',
            'model_type' => 'chat',
            'api_url' => 'https://fallback.example.test',
            'status' => 'active',
            'failover_priority' => 1,
        ]);
        $this->refreshQualityCheck($article, $source);
        $fake = new class('保证100%有效', (int) $primary->id) implements ArticleAiOptimizationRefiner
        {
            /** @var list<array{model_id:int,quota_reserve:int}> */
            public array $calls = [];

            public function __construct(
                private readonly string $oldText,
                private readonly int $primaryId,
            ) {}

            public function refine(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                int $quotaReserve = 0,
            ): array {
                $this->calls[] = [
                    'model_id' => (int) $model->id,
                    'quota_reserve' => $quotaReserve,
                ];
                if ((int) $model->id === $this->primaryId) {
                    throw new ArticleAiOptimizationException('article_ai_optimization_provider_error');
                }
                preg_match('/"base_article_hash":"([a-f0-9]{64})"/', $instructions, $matches);

                return [
                    'result' => [
                        'base_article_hash' => (string) ($matches[1] ?? ''),
                        'strategy' => 'excellent_80',
                        'operations' => [[
                            'field' => 'content',
                            'anchor_start' => 5,
                            'anchor_end' => 13,
                            'replace_start' => 5,
                            'replace_end' => 13,
                            'old_text_hash' => hash('sha256', $this->oldText),
                            'replacement' => '有助于改善体验',
                            'issue_codes' => ['ad_absolute_claim'],
                            'root_cause_keys' => ['ad_absolute_claim:content:5'],
                            'evidence_keys' => [],
                        ]],
                    ],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 40],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiOptimizationRefiner::class, $fake);
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $primary,
            ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            dispatch: false,
        );

        app(ArticleAiOptimizationCoordinator::class)->process((int) $run->id);

        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertSame((int) $fallback->id, (int) $step->ai_model_id);
        $this->assertSame([$primary->id, $fallback->id], array_column($fake->calls, 'model_id'));
        $this->assertSame([2, 2], array_column($fake->calls, 'quota_reserve'));
        $this->assertSame(['failed', 'success'], array_column((array) data_get($step->execution_meta, 'model_attempts'), 'status'));
    }

    public function test_a_late_duplicate_candidate_completion_cannot_replace_the_current_round(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_90',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidateOne = $this->completedCandidate($source, (int) $run->id, 80, 'round-one');
        $candidateTwo = $this->completedCandidate($source, (int) $run->id, 92, 'round-two');
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidateOne->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'round-one-before'),
        ]);
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $coordinator->candidateCompleted((int) $candidateOne->id);
        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $this->assertSame((int) $candidateOne->id, (int) $run->best_check_id);

        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 2,
            'input_check_id' => $candidateOne->id,
            'output_check_id' => $candidateTwo->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'round-two-before'),
        ]);
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();

        $coordinator->candidateCompleted((int) $candidateOne->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->status);
        $this->assertSame(1, (int) $run->completed_rounds);
        $this->assertSame((int) $candidateOne->id, (int) $run->best_check_id);

        $coordinator->candidateCompleted((int) $candidateTwo->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANDIDATE_READY, $run->status);
        $this->assertSame(2, (int) $run->completed_rounds);
        $this->assertSame((int) $candidateTwo->id, (int) $run->best_check_id);
    }

    public function test_an_expired_candidate_completion_is_held_for_review(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 88, 'expired');
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'deadline_at' => now()->subSecond(),
        ])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'expired-before'),
        ]);

        app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $candidate->id);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, $run->fresh()->status);
        $this->assertSame('deadline_exceeded', $run->fresh()->stop_reason);
        $this->assertNull($run->fresh()->best_check_id);
    }

    public function test_a_candidate_with_more_low_risk_deductions_is_rejected(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 88, 'low-risk');
        $candidate->forceFill(['issues' => [[
            'code' => 'minor_style_issue',
            'severity' => 'low',
            'deduction' => 1,
            'root_cause_key' => 'minor_style_issue:content:1',
        ]]])->save();
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'low-risk-before'),
            'before_score' => $source->score,
            'before_decision' => $source->decision,
        ]);

        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $coordinator->candidateCompleted((int) $candidate->id);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, $run->fresh()->status);
        $this->assertSame('candidate_not_improved', $run->fresh()->stop_reason);
        $this->assertSame([
            'before_score' => 62,
            'after_score' => 88,
            'rejection_code' => 'candidate_not_improved',
        ], $coordinator->statusForArticle($article)['last_attempt']);
    }

    public function test_a_targeted_repair_can_continue_when_reinspection_reclassifies_other_fixable_issues(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 60, 'targeted-progress');
        $candidateSnapshot = (array) $candidate->article_snapshot;
        $candidateSnapshot['content'] = str_replace(
            '保证100%有效',
            '有助于改善体验',
            (string) $candidateSnapshot['content'],
        );
        $candidate->forceFill([
            'decision' => 'blocked',
            'article_snapshot' => $candidateSnapshot,
            'issues' => [
                [
                    'code' => 'unsupported_claim',
                    'severity' => 'medium',
                    'deduction' => 3,
                    'field' => 'content',
                    'quote' => '其他待核实描述',
                    'location_status' => 'resolved',
                    'start_offset' => 0,
                    'end_offset' => 7,
                    'root_cause_key' => 'unsupported_claim:content:0',
                ],
            ],
        ])->save();
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'targeted-progress-before'),
            'before_score' => $source->score,
            'before_decision' => $source->decision,
            'selected_causes' => $source->issues,
        ]);

        app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $candidate->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $this->assertSame(1, (int) $run->completed_rounds);
        $this->assertSame((int) $candidate->id, (int) $run->best_check_id);
        Queue::assertPushed(ProcessArticleAiOptimizationJob::class);
    }

    public function test_replacing_one_high_risk_with_a_different_high_risk_is_rejected(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 88, 'new-high-risk');
        $candidateSnapshot = (array) $candidate->article_snapshot;
        $candidateSnapshot['content'] = str_replace('保证100%有效', '有助于改善体验', (string) $candidateSnapshot['content']);
        $candidate->forceFill([
            'article_snapshot' => $candidateSnapshot,
            'issues' => [[
                'code' => 'content_integrity',
                'severity' => 'high',
                'deduction' => 6,
                'field' => 'content',
                'quote' => '有助于改善体验',
                'location_status' => 'resolved',
                'start_offset' => 5,
                'end_offset' => 12,
                'root_cause_key' => 'content_integrity:content:5',
            ]],
        ])->save();
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'new-high-risk-before'),
            'selected_causes' => $source->issues,
        ]);

        app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $candidate->id);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, $run->fresh()->status);
        $this->assertSame('candidate_not_improved', $run->fresh()->stop_reason);
        $this->assertNull($run->fresh()->best_check_id);
    }

    public function test_progress_only_candidate_cannot_enter_task_auto_apply(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_auto_apply_enabled', true);
        config()->set('geoflow.ai_quality_optimization_auto_apply_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $article->task()->update(['ai_quality_auto_optimize_enabled' => true]);
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 88, 'progress-only');
        $candidateSnapshot = (array) $candidate->article_snapshot;
        $candidateSnapshot['content'] = str_replace('保证100%有效', '有助于改善体验', (string) $candidateSnapshot['content']);
        $candidate->forceFill([
            'article_snapshot' => $candidateSnapshot,
            'issues' => [[
                'code' => 'unsupported_claim',
                'severity' => 'medium',
                'deduction' => 3,
                'field' => 'content',
                'quote' => '有助于改善体验',
                'location_status' => 'resolved',
                'start_offset' => 5,
                'end_offset' => 12,
                'root_cause_key' => 'unsupported_claim:content:5',
            ]],
        ])->save();
        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_EVALUATING])->save();
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'before_hash' => hash('sha256', 'progress-only-before'),
            'selected_causes' => $source->issues,
        ]);

        app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $candidate->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $this->assertSame((int) $candidate->id, (int) $run->best_check_id);
        $this->assertSame('开场说明。保证100%有效，请结合实际情况使用。', $article->fresh()->content);
    }

    public function test_an_old_job_failure_cannot_overwrite_a_new_attempt(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
            'lease_owner' => null,
            'execution_meta' => array_replace((array) $run->execution_meta, [
                'attempt_owner' => 'new-attempt',
            ]),
        ])->save();
        $coordinator = app(ArticleAiOptimizationCoordinator::class);

        $coordinator->markFailed((int) $run->id, new \RuntimeException('late failure'), 'old-attempt');
        $this->assertSame(ArticleAiOptimizationRun::STATUS_EVALUATING, $run->fresh()->status);

        $coordinator->markFailed((int) $run->id, new \RuntimeException('current failure'), 'new-attempt');
        $this->assertSame(ArticleAiOptimizationRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_reconciliation_retries_an_interrupted_automatic_apply(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_auto_apply_enabled', true);
        config()->set('geoflow.ai_quality_optimization_auto_apply_percent', 100);
        Queue::fake();
        [$article, $model, $source] = $this->qualityArticle();
        $article->task()->update(['ai_quality_auto_optimize_enabled' => true]);
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            dispatch: false,
        );
        $candidate = $this->completedCandidate($source, (int) $run->id, 88, 'auto-apply');
        $candidateSnapshot = array_replace((array) $candidate->article_snapshot, [
            'content' => '开场说明。有助于改善体验，请结合实际情况使用。',
        ]);
        $candidate->forceFill(['article_snapshot' => $candidateSnapshot])->save();
        $candidateHash = app(ArticleRiskScanner::class)->contentHash($candidateSnapshot);
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $candidate->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'accepted',
            'before_hash' => (string) $run->base_article_hash,
            'after_hash' => $candidateHash,
        ]);
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
            'best_check_id' => $candidate->id,
            'candidate_hash' => $candidateHash,
            'completed_rounds' => 1,
            'deadline_at' => now()->subSecond(),
        ])->save();

        $this->assertTrue(app(ArticleAiOptimizationCoordinator::class)->statusForArticle($article)['should_poll']);

        $counts = app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertStringContainsString('有助于改善体验', (string) $article->fresh()->content);
        $this->assertGreaterThanOrEqual(1, $counts['continued']);

        $admin = Admin::query()->create([
            'username' => 'auto-optimization-recheck-admin',
            'password' => 'secret-password',
            'email' => 'auto-optimization-recheck@example.test',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $token = $admin->createToken('auto-optimization-recheck', ['articles:publish'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Idempotency-Key', 'auto-optimization-recheck-after-apply')
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck", [
                'config_version' => (int) $article->fresh()->ai_quality_policy_version,
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.status', 'queued');
    }

    public function test_api_start_requires_publish_scope_and_replays_an_idempotent_request(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $admin = Admin::query()->create([
            'username' => 'optimization-api-admin',
            'password' => 'secret-password',
            'email' => 'optimization-api@example.test',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $readToken = $admin->createToken('optimization-read', ['articles:read'])->plainTextToken;
        $publishToken = $admin->createToken('optimization-publish', ['articles:publish'])->plainTextToken;
        $endpoint = "/api/v1/articles/{$article->id}/ai-quality/optimization";
        $payload = ['strategy' => 'excellent_80', 'optimization_model_id' => $model->id];

        $this->withHeader('Authorization', 'Bearer '.$readToken)
            ->postJson($endpoint, $payload)
            ->assertForbidden();

        $headers = [
            'Authorization' => 'Bearer '.$publishToken,
            'X-Idempotency-Key' => 'optimization-start-'.$article->id,
        ];
        $this->withHeaders($headers)
            ->postJson($endpoint, $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.strategy', 'excellent_80');
        $this->withHeaders($headers)
            ->postJson($endpoint, $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.strategy', 'excellent_80');

        $this->assertSame(1, ArticleAiOptimizationRun::query()->count());
    }

    public function test_candidate_preview_uses_accepted_round_snapshots_and_excludes_rejected_attempts(): void
    {
        [$article, $model, $source] = $this->qualityArticle();
        $sourceSnapshot = array_replace((array) $source->article_snapshot, [
            'content' => '第一段原文。第二段原文。',
        ]);
        $roundOneSnapshot = array_replace($sourceSnapshot, [
            'content' => '第一段候选。第二段原文。',
        ]);
        $roundTwoSnapshot = array_replace($roundOneSnapshot, [
            'content' => '第一段候选。第二段候选。',
        ]);
        $rejectedSnapshot = array_replace($roundTwoSnapshot, [
            'content' => '被拒绝候选。第二段候选。',
        ]);
        $source->forceFill(['article_snapshot' => $sourceSnapshot])->save();
        $roundOne = $source->replicate(['request_key', 'active_dedupe_key']);
        $roundOne->forceFill([
            'request_key' => (string) Str::uuid(),
            'article_snapshot' => $roundOneSnapshot,
            'evaluation_mode' => 'optimization_candidate',
            'gate_applied' => false,
        ])->save();
        $roundTwo = $source->replicate(['request_key', 'active_dedupe_key']);
        $roundTwo->forceFill([
            'request_key' => (string) Str::uuid(),
            'article_snapshot' => $roundTwoSnapshot,
            'evaluation_mode' => 'optimization_candidate',
            'gate_applied' => false,
        ])->save();
        $rejected = $source->replicate(['request_key', 'active_dedupe_key']);
        $rejected->forceFill([
            'request_key' => (string) Str::uuid(),
            'article_snapshot' => $rejectedSnapshot,
            'evaluation_mode' => 'optimization_candidate',
            'gate_applied' => false,
        ])->save();
        $run = ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'task_id' => $article->task_id,
            'source_check_id' => $source->id,
            'best_check_id' => $roundTwo->id,
            'request_key' => (string) Str::uuid(),
            'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            'strategy' => 'excellent_80',
            'target_score' => 85,
            'max_rounds' => 3,
            'completed_rounds' => 3,
            'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
            'base_article_hash' => hash('sha256', 'candidate-preview-source'),
            'candidate_hash' => hash('sha256', 'candidate-preview-final'),
            'policy_hash' => hash('sha256', 'candidate-preview-policy'),
        ]);
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 1,
            'input_check_id' => $source->id,
            'output_check_id' => $roundOne->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'accepted',
            'before_hash' => hash('sha256', 'round-one-before'),
            'after_hash' => hash('sha256', 'round-one-after'),
            'applied_patch' => [[
                'field' => 'content',
                'replace_start' => 0,
                'replace_end' => 5,
                'replacement' => '第一段候选',
            ]],
        ]);
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 3,
            'input_check_id' => $roundTwo->id,
            'output_check_id' => $rejected->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'rejected',
            'rejection_code' => 'candidate_not_improved',
            'before_hash' => hash('sha256', 'round-three-before'),
            'after_hash' => hash('sha256', 'round-three-after'),
            'applied_patch' => [[
                'field' => 'content',
                'replace_start' => 0,
                'replace_end' => 5,
                'replacement' => '被拒绝候选',
            ]],
        ]);
        ArticleAiOptimizationStep::query()->create([
            'run_id' => $run->id,
            'round_index' => 2,
            'input_check_id' => $roundOne->id,
            'output_check_id' => $roundTwo->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'accepted',
            'before_hash' => hash('sha256', 'round-two-before'),
            'after_hash' => hash('sha256', 'round-two-after'),
            'applied_patch' => [[
                'field' => 'content',
                'replace_start' => 6,
                'replace_end' => 11,
                'replacement' => '第二段候选',
            ]],
        ]);

        $candidate = app(ArticleAiOptimizationCoordinator::class)->candidate((int) $run->id);

        $this->assertCount(2, $candidate['modifications']);
        $this->assertStringContainsString('第一段候选。第二段原文。', $candidate['modifications'][1]['before_text']);
        $this->assertStringContainsString('第一段候选。第二段候选。', $candidate['modifications'][1]['after_text']);
    }

    public function test_reconciliation_requeues_an_expired_worker_lease(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_REWRITING,
            'lease_owner' => 'expired-worker',
            'lease_expires_at' => now()->subMinute(),
        ])->save();

        $counts = app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

        $this->assertSame(1, $counts['requeued']);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->fresh()->status);
        Queue::assertPushed(ProcessArticleAiOptimizationJob::class, fn (ProcessArticleAiOptimizationJob $job): bool => $job->runId === $run->id);
    }

    public function test_reconciliation_keeps_a_manual_candidate_ready_for_review_after_compute_deadline(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $check] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
            'best_check_id' => $check->id,
            'candidate_hash' => app(ArticleRiskScanner::class)->contentHash((array) $check->article_snapshot),
            'deadline_at' => now()->subMinute(),
            'updated_at' => now()->subHour(),
        ])->save();

        $this->assertFalse(app(ArticleAiOptimizationCoordinator::class)->statusForArticle($article)['should_poll']);

        $counts = app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

        $this->assertSame(0, $counts['examined']);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANDIDATE_READY, $run->fresh()->status);
    }

    public function test_task_quality_workflow_is_held_while_an_automatic_optimization_is_queued(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_percent', 100);
        Queue::fake();
        [$article, , $check] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        $intercepted = app(ArticleAiOptimizationCoordinator::class)->interceptCompletedWorkflow((int) $check->id);

        $run = ArticleAiOptimizationRun::query()->where('article_id', $article->id)->firstOrFail();
        $this->assertTrue($intercepted);
        $this->assertSame(ArticleAiOptimizationRun::TRIGGER_TASK_AUTO, $run->trigger);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $this->assertSame('waiting_optimization', data_get($check->fresh()->execution_meta, 'workflow_apply.status'));
        Queue::assertPushed(ProcessArticleAiOptimizationJob::class, fn (ProcessArticleAiOptimizationJob $job): bool => $job->queue === 'ai-content-optimization-bulk');
    }

    public function test_manual_recheck_returns_a_conflict_while_optimization_is_active(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
            dispatch: false,
        );

        try {
            app(ArticleGeoFlowService::class)->recheckAiQuality((int) $article->id, 0, 0);
            $this->fail('Expected an active optimization to block manual reinspection.');
        } catch (ApiException $exception) {
            $this->assertSame(409, $exception->getHttpStatus());
            $this->assertSame('article_ai_optimization_recheck_conflict', $exception->getErrorCode());
            $this->assertTrue((bool) data_get($exception->getDetails(), 'can_cancel_optimization'));
        }
    }

    public function test_quality_override_cancels_the_active_optimization_in_the_same_gate_flow(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model, $check] = $this->qualityArticle();
        $inspection = app(ArticleAiQualityInspectionService::class);
        $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article->fresh());
        $fingerprint = $inspection->currentFingerprint(
            $article->fresh(),
            $policy,
            $inspection->rules(),
            app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id),
        );
        $check->forceFill([
            'decision' => 'needs_review',
            'score' => 72,
            'input_fingerprint' => $fingerprint,
        ])->save();
        $admin = Admin::query()->create([
            'username' => 'optimization-override-admin',
            'password' => 'secret-password',
            'email' => 'optimization-override@example.test',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );

        app(ArticleGeoFlowService::class)->overrideAiQuality(
            (int) $article->id,
            '已核对知识库原始依据并确认可以放行',
            (int) $admin->id,
        );

        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame('quality_override', $run->fresh()->stop_reason);
        $this->assertTrue((bool) $check->fresh()->is_overridden);
        $this->assertSame($admin->id, $check->fresh()->overridden_by);
    }

    public function test_disabling_task_auto_optimization_cancels_the_run_and_recovers_the_workflow(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_percent', 100);
        Queue::fake();
        [$article, , $check] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_80',
        ]);
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $coordinator->interceptCompletedWorkflow((int) $check->id);
        $run = ArticleAiOptimizationRun::query()->where('article_id', $article->id)->firstOrFail();

        app(TaskLifecycleService::class)->updateTask((int) $article->task_id, [
            'ai_quality_auto_optimize_enabled' => false,
        ]);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame('task_auto_optimization_disabled', $run->fresh()->stop_reason);
        Queue::assertPushed(ReconcileArticleAiOptimizationJob::class);
        $coordinator->recoverWaitingWorkflow((int) $check->id);
        $this->assertNotSame('waiting_optimization', data_get($check->fresh()->execution_meta, 'workflow_apply.status'));
    }

    public function test_task_auto_start_rejects_a_disabled_or_mismatched_current_policy(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        [$article, $model] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => false,
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        try {
            app(ArticleAiOptimizationCoordinator::class)->start(
                $article,
                'excellent_80',
                $model,
                ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
                dispatch: false,
            );
            $this->fail('Expected the current task auto-optimization policy to reject the run.');
        } catch (ArticleAiOptimizationException $exception) {
            $this->assertSame('article_ai_optimization_task_policy_changed', $exception->errorCode());
            $this->assertSame(409, $exception->httpStatus());
        }

        $this->assertDatabaseCount('article_ai_optimization_runs', 0);
    }

    public function test_claim_stales_a_queued_auto_run_when_the_task_policy_changes(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        [$article, $model] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_80',
        ]);
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $run = $coordinator->start(
            $article->fresh(),
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            dispatch: false,
        );

        $article->task()->update(['ai_quality_optimization_level' => 'excellent_90']);
        $coordinator->process((int) $run->id);

        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $run->status);
        $this->assertSame('task_auto_optimization_policy_changed', $run->stop_reason);
        $this->assertNull($run->active_dedupe_key);
    }

    public function test_changing_task_optimization_level_restarts_evaluation_with_the_new_target(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_percent', 100);
        Queue::fake();
        [$article, , $check] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_80',
        ]);
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $coordinator->interceptCompletedWorkflow((int) $check->id);
        $oldRun = ArticleAiOptimizationRun::query()->where('article_id', $article->id)->firstOrFail();

        app(TaskLifecycleService::class)->updateTask((int) $article->task_id, [
            'ai_quality_optimization_level' => 'excellent_90',
        ]);
        $coordinator->recoverWaitingWorkflow((int) $check->id);

        $newRun = ArticleAiOptimizationRun::query()->where('article_id', $article->id)->latest('id')->firstOrFail();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $oldRun->fresh()->status);
        $this->assertSame('task_optimization_level_changed', $oldRun->fresh()->stop_reason);
        $this->assertNotSame($oldRun->id, $newRun->id);
        $this->assertSame(90, $newRun->target_score);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $newRun->status);
    }

    public function test_changing_the_task_auto_level_keeps_a_manual_run_active(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        Queue::fake();
        [$article, $model] = $this->qualityArticle();
        $run = app(ArticleAiOptimizationCoordinator::class)->start(
            $article,
            'excellent_80',
            $model,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            dispatch: false,
        );

        app(TaskLifecycleService::class)->updateTask((int) $article->task_id, [
            'ai_quality_optimization_level' => 'excellent_90',
        ]);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->fresh()->status);
        $this->assertSame('excellent_80', $run->fresh()->strategy);
    }

    public function test_global_kill_switch_cancels_active_runs_and_recovers_waiting_workflows(): void
    {
        config()->set('geoflow.ai_quality_optimization_enabled', true);
        config()->set('geoflow.ai_quality_optimization_percent', 100);
        Queue::fake();
        [$article, , $check] = $this->qualityArticle();
        $article->task()->update([
            'ai_quality_auto_optimize_enabled' => true,
            'ai_quality_optimization_level' => 'excellent_80',
        ]);
        app(ArticleAiOptimizationCoordinator::class)->interceptCompletedWorkflow((int) $check->id);
        $run = ArticleAiOptimizationRun::query()->where('article_id', $article->id)->firstOrFail();

        config()->set('geoflow.ai_quality_optimization_enabled', false);
        $counts = app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertSame('optimization_feature_disabled', $run->fresh()->stop_reason);
        $this->assertNotSame('waiting_optimization', data_get($check->fresh()->execution_meta, 'workflow_apply.status'));
        $this->assertGreaterThanOrEqual(1, $counts['workflow_recovered']);
    }

    /** @return array{Article,AiModel,ArticleAiQualityCheck} */
    private function qualityArticle(): array
    {
        $contentPrompt = Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '撰写正文',
        ]);
        $qualityPrompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '内容模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'content-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '知识库',
            'content' => '本产品可以帮助改善使用体验。',
            'review_status' => 'approved',
        ]);
        $task = Task::query()->create([
            'name' => '优化任务',
            'status' => 'paused',
            'prompt_id' => $contentPrompt->id,
            'ai_model_id' => $model->id,
            'knowledge_base_id' => $knowledgeBase->id,
            'ai_quality_enabled' => true,
            'ai_quality_retrieval_mode' => 'knowledge_broad',
            'ai_quality_prompt_id' => $qualityPrompt->id,
            'ai_quality_model_id' => $model->id,
            'ai_quality_pass_score' => 85,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '优化', 'slug' => 'optimization']);
        $author = Author::query()->create(['name' => '优化员']);
        $article = Article::query()->create([
            'title' => '产品说明',
            'slug' => 'optimization-article',
            'excerpt' => '产品摘要',
            'content' => '开场说明。保证100%有效，请结合实际情况使用。',
            'keywords' => '产品,说明',
            'meta_description' => '产品说明摘要',
            'status' => 'draft',
            'review_status' => 'pending',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'ai_quality_required_at_creation' => true,
        ]);
        $snapshot = app(ArticleAiQualityPolicyResolver::class)->articleSnapshot($article);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'prompt_id' => $qualityPrompt->id,
            'ai_model_id' => $model->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'active_dedupe_key' => null,
            'decision' => 'blocked',
            'score' => 62,
            'pass_score' => 85,
            'manual_override_min_score' => 70,
            'article_snapshot' => $snapshot,
            'article_content_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'input_fingerprint' => hash('sha256', 'quality-input'),
            'algorithm_version' => 'test',
            'gate_applied' => true,
            'evaluation_mode' => 'primary',
            'inspection_scope' => 'full',
            'issues' => [[
                'code' => 'ad_absolute_claim',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '保证100%有效',
                'location_status' => 'resolved',
                'start_offset' => 5,
                'end_offset' => 13,
                'root_cause_key' => 'ad_absolute_claim:content:5',
                'reason' => '绝对化承诺',
                'suggestion' => '收敛表达',
                'evidence_keys' => [],
            ]],
            'evidence_snapshot' => [],
            'finished_at' => now(),
        ])->save();
        $qualityPolicy = app(ArticleAiQualityPolicyResolver::class)->resolveForManualInspection($article->fresh());
        $versionSelection = app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id);
        $fullRules = app(ArticleAiQualityInspectionService::class)->rules();
        $principleSnapshot = app(ArticleAiQualityPrincipleCompiler::class)->compile(
            $snapshot,
            $fullRules,
            array_values(app(ArticleAiQualityPolicyResolver::class)->fingerprintInput(
                $article->fresh(),
                $qualityPolicy,
                $fullRules,
            )['knowledge'] ?? []),
            is_array($qualityPolicy['publication_context'] ?? null) ? $qualityPolicy['publication_context'] : [],
        );
        $rules = (string) ($versionSelection['principles'] ?? 'v1') === 'v2'
            ? app(ArticleAiQualityPrincipleCompiler::class)->rules($principleSnapshot)
            : $fullRules;
        $inputFingerprint = app(ArticleAiQualityInspectionService::class)->currentFingerprint(
            $article->fresh(),
            $qualityPolicy,
            $fullRules,
            $versionSelection,
        );
        $check->forceFill([
            'input_fingerprint' => $inputFingerprint,
            'algorithm_version' => $versionSelection['algorithm_version'],
            'prompt_template_snapshot' => (string) $qualityPrompt->content,
            'advertising_rules_snapshot' => $rules,
            'knowledge_hash' => hash('sha256', 'knowledge'),
            'execution_meta' => array_replace(is_array($check->execution_meta) ? $check->execution_meta : [], [
                'policy_snapshot' => app(ArticleAiQualityPolicyResolver::class)->snapshot($qualityPolicy),
                'version_selection' => $versionSelection,
                'principle_snapshot' => $principleSnapshot,
            ]),
        ])->save();

        $this->assertSame(
            app(ArticleRiskScanner::class)->contentHash($snapshot),
            app(ArticleRiskScanner::class)->contentHash($check->article_snapshot),
        );

        return [$article, $model, $check];
    }

    private function refreshQualityCheck(Article $article, ArticleAiQualityCheck $check): void
    {
        $article = $article->fresh();
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $inspectionService = app(ArticleAiQualityInspectionService::class);
        $policy = $resolver->resolveForManualInspection($article);
        $versionSelection = app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id);
        $snapshot = $resolver->articleSnapshot($article);
        $fullRules = $inspectionService->rules();
        $principleSnapshot = app(ArticleAiQualityPrincipleCompiler::class)->compile(
            $snapshot,
            $fullRules,
            array_values($resolver->fingerprintInput($article, $policy, $fullRules)['knowledge'] ?? []),
            is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
        );
        $rules = (string) ($versionSelection['principles'] ?? 'v1') === 'v2'
            ? app(ArticleAiQualityPrincipleCompiler::class)->rules($principleSnapshot)
            : $fullRules;
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];

        $check->forceFill([
            'article_snapshot' => $snapshot,
            'article_content_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'input_fingerprint' => $inspectionService->currentFingerprint(
                $article,
                $policy,
                $fullRules,
                $versionSelection,
            ),
            'algorithm_version' => $versionSelection['algorithm_version'],
            'advertising_rules_snapshot' => $rules,
            'execution_meta' => array_replace($executionMeta, [
                'policy_snapshot' => $resolver->snapshot($policy),
                'version_selection' => $versionSelection,
                'principle_snapshot' => $principleSnapshot,
            ]),
        ])->save();
    }

    private function setQualityRollout(
        int $execution = 0,
        int $scoring = 0,
        int $shadow = 0,
        int $principles = 0,
    ): void {
        ArticleAiQualityRollout::query()->updateOrCreate(['id' => 1], [
            'execution_percent' => $execution,
            'scoring_percent' => $scoring,
            'shadow_percent' => $shadow,
            'principle_percent' => $principles,
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
        ]);
        app(ArticleAiQualityRolloutPolicy::class)->forget();
    }

    private function completedCandidate(
        ArticleAiQualityCheck $source,
        int $runId,
        int $score,
        string $suffix,
    ): ArticleAiQualityCheck {
        $candidate = $source->replicate(['request_key', 'active_dedupe_key']);
        $candidate->forceFill([
            'request_key' => (string) Str::uuid(),
            'status' => 'completed',
            'decision' => 'passed',
            'score' => $score,
            'evaluation_mode' => 'optimization_candidate',
            'gate_applied' => false,
            'article_snapshot' => array_replace((array) $source->article_snapshot, [
                'excerpt' => '候选内容 '.$suffix,
            ]),
            'issues' => [],
            'gate_reasons' => [],
            'execution_meta' => array_replace((array) $source->execution_meta, [
                'optimization_run_id' => $runId,
            ]),
            'finished_at' => now(),
        ])->save();

        return $candidate;
    }
}
