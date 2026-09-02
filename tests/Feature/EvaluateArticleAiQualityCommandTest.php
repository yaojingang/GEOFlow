<?php

namespace Tests\Feature;

use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Console\Commands\EvaluateArticleAiQualityCommand;
use App\Contracts\ArticleAiQualityReviewer;
use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\GeoFlow\ArticleAiQualityProviderCircuitBreaker;
use App\Services\GeoFlow\DirectAdminAiExecutionGuard;
use App\Services\GeoFlow\DirectAdminAiInvocationBoundaryHook;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class EvaluateArticleAiQualityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_comparator_contract_contains_250_valid_deterministic_cases(): void
    {
        $this->artisan('geoflow:validate-atomic-comparator-contract')->assertSuccessful();
    }

    public function test_offline_evaluation_generates_machine_and_human_readable_reports(): void
    {
        $directory = storage_path('framework/testing/ai-quality-evaluation');
        $basePath = $directory.'/report';

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => base_path('tests/Fixtures/ai-quality/golden-v1.json'),
            '--output' => $basePath,
        ])->assertSuccessful();

        $this->assertFileExists($basePath.'.json');
        $this->assertFileExists($basePath.'.md');
        $report = json_decode((string) file_get_contents($basePath.'.json'), true);
        $this->assertSame('offline', $report['mode']);
        $this->assertSame(240, $report['dataset']['case_count']);
        $this->assertSame(2, $report['schema_version']);
        $this->assertSame(64, strlen($report['dataset']['sha256']));
        $this->assertSame('tests/Fixtures/ai-quality/golden-v1.json', $report['dataset']['path']);
        $this->assertFalse($report['production_gate_ready']);
        $this->assertSame('saved_predictions', $report['evaluation_scope']);
        $this->assertFalse($report['gate_checks']['end_to_end_latency']);
        $this->assertFalse($report['gate_checks']['repeat_stability']);
        $this->assertArrayHasKey('decision_confusion_matrix', $report['metrics']);
        $this->assertArrayHasKey('prompt_tokens', $report['metrics']);
        $this->assertArrayHasKey('completion_tokens', $report['metrics']);
        $this->assertArrayHasKey('token_reduction_vs_baseline', $report['metrics']);
        $this->assertArrayHasKey('repeat_stability', $report['metrics']);
        $this->assertArrayHasKey('atomic_facts', $report['metrics']);
        $this->assertArrayHasKey('wilson_95', $report['metrics']['atomic_facts']['precision']);
        $this->assertSame(240, $report['metrics']['by_inspection_scope']['full']['case_count']);
        $this->assertSame(0, $report['metrics']['by_inspection_scope']['fallback_sampled']['case_count']);
        $this->assertStringContainsString('AI 质检黄金集评测报告', (string) file_get_contents($basePath.'.md'));
        $this->assertStringContainsString('已保存预测离线复算', (string) file_get_contents($basePath.'.md'));
    }

    public function test_manual_review_of_a_safe_case_counts_as_a_false_gate(): void
    {
        $directory = storage_path('framework/testing/ai-quality-evaluation-false-gate');
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        File::put($datasetPath, json_encode([
            'version' => 'false-gate-test',
            'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60],
            'cases' => [[
                'id' => 'safe-held-for-review',
                'split' => 'calibration',
                'expected' => ['decision' => 'passed', 'issue_codes' => []],
                'prediction' => ['decision' => 'needs_review', 'issue_codes' => []],
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
        ])->assertSuccessful();

        $reportJson = (string) File::get($basePath.'.json');
        $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertEquals(1.0, $report['metrics']['safe_false_block_rate']);
        $this->assertFalse($report['production_gate_ready']);
    }

    public function test_atomic_false_block_rate_uses_only_atomic_cases(): void
    {
        $directory = storage_path('framework/testing/ai-quality-atomic-false-gate');
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        $cases = [
            ['id' => 'atomic-safe-blocked', 'split' => 'calibration', 'atomic_fact' => [
                'claim_role' => 'material_claim', 'definition' => '安全事实', 'canonical' => ['value' => '1'], 'evidence' => [],
                'expected_applicability' => 'applicable', 'expected_result' => 'match', 'expected_fallback' => false, 'expected_final_decision' => 'passed',
            ], 'expected' => ['decision' => 'passed', 'issue_codes' => []], 'prediction' => ['decision' => 'blocked', 'issue_codes' => []]],
            ['id' => 'general-safe-passed', 'split' => 'calibration', 'expected' => ['decision' => 'passed', 'issue_codes' => []], 'prediction' => ['decision' => 'passed', 'issue_codes' => []]],
        ];
        for ($index = 3; $index <= 240; $index++) {
            $split = $index <= 120 ? 'calibration' : ($index <= 180 ? 'regression' : 'blind');
            $cases[] = ['id' => 'risk-'.$index, 'split' => $split, 'expected' => ['decision' => 'blocked', 'issue_codes' => ['risk']], 'prediction' => ['decision' => 'blocked', 'issue_codes' => ['risk']]];
        }
        File::put($datasetPath, json_encode([
            'version' => 'atomic-false-gate-test',
            'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60],
            'cases' => $cases,
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', ['--dataset' => $datasetPath, '--output' => $basePath])->assertSuccessful();

        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0.5, $report['metrics']['safe_false_block_rate']);
        $this->assertEquals(1.0, $report['metrics']['atomic_facts']['false_block_rate']);
    }

    public function test_live_evaluation_runs_distinct_full_and_sampled_production_components(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            /** @var list<string> */
            public array $instructions = [];

            public function review(AiModel $model, string $instructions): array
            {
                $this->instructions[] = $instructions;

                return [
                    'result' => [
                        'summary' => '检查完成。',
                        'promotion_context' => 'informational',
                        'reviewed_claim_hashes' => [],
                        'issues' => [],
                        'uncertainties' => [],
                        'truncated_issue_count' => 0,
                    ],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $admin = $this->admin('live-evaluation-owner');
        $model = AiModel::query()->create([
            'name' => 'Live evaluation fake model',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('live-evaluation-secret'),
            'api_url' => 'https://secret.example.test/v1',
            'model_id' => 'live-evaluation-fake-model',
            'model_type' => 'chat',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $directory = storage_path('framework/testing/ai-quality-evaluation-live');
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        File::put($datasetPath, json_encode([
            'version' => 'live-components-test',
            'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60],
            'cases' => [
                [
                    'id' => 'full-safe',
                    'split' => 'calibration',
                    'inspection_scope' => 'full',
                    'article' => ['title' => 'Full check', 'content' => str_repeat('Safe content. ', 100)],
                    'facts' => [],
                    'evidence' => [],
                    'expected' => ['decision' => 'passed', 'issue_codes' => []],
                ],
                [
                    'id' => 'sampled-safe',
                    'split' => 'regression',
                    'inspection_scope' => 'fallback_sampled',
                    'article' => ['title' => 'Sampled check', 'content' => str_repeat('Safe sampled content. ', 1000)],
                    'facts' => [],
                    'evidence' => [],
                    'expected' => ['decision' => 'passed', 'issue_codes' => []],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertSuccessful();

        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('production_components', $report['evaluation_scope']);
        $this->assertSame(1, $report['metrics']['by_inspection_scope']['full']['case_count']);
        $this->assertSame(1, $report['metrics']['by_inspection_scope']['fallback_sampled']['case_count']);
        $this->assertNull($report['cases'][0]['coverage']);
        $this->assertSame(
            'article-quality-sampling-1.1.0',
            $report['cases'][1]['coverage']['algorithm_version'],
        );
        $this->assertCount(2, $reviewer->instructions);
        $this->assertStringContainsString('fallback_sampled', $reviewer->instructions[1]);
    }

    public function test_live_evaluation_requires_an_active_execution_admin_before_reviewer_or_output(): void
    {
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-admin-required');
        File::put($basePath.'.json', 'historical-json');
        File::put($basePath.'.md', 'historical-markdown');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
        ])->assertFailed();

        $this->assertSame([], $reviewer->modelIds);
        $this->assertSame('historical-json', File::get($basePath.'.json'));
        $this->assertSame('historical-markdown', File::get($basePath.'.md'));

        $inactive = $this->admin('live-inactive-admin');
        $model = $this->model($inactive, 'inactive-admin-model');
        $inactive->forceFill(['status' => 'inactive'])->save();
        [$inactiveDataset, $inactiveBase] = $this->liveFixture('live-inactive-admin');
        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $inactiveDataset,
            '--output' => $inactiveBase,
            '--live' => true,
            '--admin' => $inactive->id,
            '--model' => $model->id,
        ])->assertFailed();
        $this->assertSame([], $reviewer->modelIds);
        $this->assertFileDoesNotExist($inactiveBase.'.json');
        $this->assertFileDoesNotExist($inactiveBase.'.md');
    }

    public function test_live_comparison_keeps_a_historical_checkpoint_when_execution_identity_is_invalid(): void
    {
        [, $basePath] = $this->liveFixture('live-comparison-invalid-admin');
        $historicalCheckpoint = $basePath.'.partial.json';
        File::put($historicalCheckpoint, 'historical-checkpoint');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--output' => $basePath,
            '--live' => true,
            '--articles' => '1',
            '--knowledge-base' => '1',
            '--compare' => 'atomic,knowledge',
        ])->assertFailed();

        $this->assertSame('historical-checkpoint', File::get($historicalCheckpoint));
        $this->assertSame([], glob($basePath.'.*.partial.json') ?: []);
    }

    public function test_live_comparison_resumes_from_the_stable_checkpoint_and_reviews_only_remaining_calls(): void
    {
        $admin = $this->admin('live-comparison-resume-admin', 'admin');
        $model = $this->model($admin, 'live-comparison-resume-model');
        $author = Author::query()->create(['name' => '断点恢复作者']);
        $category = Category::query()->create([
            'name' => '断点恢复分类',
            'slug' => 'comparison-resume-category',
        ]);
        $article = Article::query()->create([
            'title' => '断点恢复文章',
            'slug' => 'comparison-resume-article',
            'content' => '断点恢复正文。',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '断点恢复知识库',
            'content' => '断点恢复知识。',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);
        [, $basePath] = $this->liveFixture('live-comparison-resume');
        $reviewCount = 0;
        $interruptingReviewer = $this->recordingReviewer(function () use (&$reviewCount, $model): void {
            $reviewCount++;
            if ($reviewCount === 2) {
                $model->forceFill(['status' => 'inactive'])->save();
            }
        });
        $this->app->instance(ArticleAiQualityReviewer::class, $interruptingReviewer);
        $arguments = [
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
            '--articles' => (string) $article->id,
            '--knowledge-base' => (string) $knowledgeBase->id,
            '--compare' => 'atomic,knowledge',
        ];

        $this->artisan('geoflow:evaluate-ai-quality', $arguments)->assertFailed();

        $checkpointPath = $basePath.'.partial.json';
        $checkpoint = json_decode((string) File::get($checkpointPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1, $checkpoint['calls']);
        $this->assertSame(2, $checkpoint['schema_version']);
        $this->assertNotSame('', $checkpoint['run_id']);
        $this->assertSame(64, strlen($checkpoint['fingerprint']));
        $this->assertSame($admin->id, $checkpoint['request']['admin_id']);
        $this->assertSame($model->id, $checkpoint['request']['requested_model_id']);
        $this->assertSame([], glob($checkpointPath.'.*.tmp') ?: []);
        $this->assertCount(2, $interruptingReviewer->modelIds);

        $model->forceFill(['status' => 'active'])->save();

        $this->artisan('geoflow:evaluate-ai-quality', $arguments)->assertSuccessful();

        $this->assertCount(3, $interruptingReviewer->modelIds);
        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $report['calls']);
        $this->assertFileDoesNotExist($checkpointPath);
        $this->assertSame([], glob($checkpointPath.'.*.tmp') ?: []);
    }

    public function test_live_comparison_resumes_an_automatic_run_with_the_current_candidate_and_preserves_per_call_model_attribution(): void
    {
        $provider = $this->admin('comparison-auto-provider');
        $admin = $this->admin('comparison-auto-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'comparison-auto-personal', 1);
        $personal->forceFill(['daily_limit' => 1])->save();
        $shared = $this->model($provider, 'comparison-auto-shared', 1);
        $author = Author::query()->create(['name' => '自动恢复作者']);
        $category = Category::query()->create([
            'name' => '自动恢复分类',
            'slug' => 'comparison-auto-resume-category',
        ]);
        $article = Article::query()->create([
            'title' => '自动恢复文章',
            'slug' => 'comparison-auto-resume-article',
            'content' => '自动恢复正文。',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '自动恢复知识库',
            'content' => '自动恢复知识。',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);
        $state = (object) ['interrupted' => false];
        $this->app->instance(
            DirectAdminAiInvocationBoundaryHook::class,
            new class($admin, (int) $shared->id, $state) extends DirectAdminAiInvocationBoundaryHook
            {
                public function __construct(
                    private readonly Admin $admin,
                    private readonly int $sharedModelId,
                    private readonly object $state,
                ) {}

                public function beforeCandidateLock(DirectAdminAiExecutionContext $context, AiModel $candidate): void
                {
                    if ($this->state->interrupted || (int) $candidate->id !== $this->sharedModelId) {
                        return;
                    }
                    $this->state->interrupted = true;

                    throw AiModelAccessException::modelUnavailable($this->admin, $candidate);
                }
            },
        );
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [, $basePath] = $this->liveFixture('live-comparison-auto-resume');
        $arguments = [
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--articles' => (string) $article->id,
            '--knowledge-base' => (string) $knowledgeBase->id,
            '--compare' => 'atomic,knowledge',
        ];

        $this->artisan('geoflow:evaluate-ai-quality', $arguments)->assertFailed();

        $checkpointPath = $basePath.'.partial.json';
        $checkpoint = json_decode((string) File::get($checkpointPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($checkpoint['request']['requested_model_id']);
        $this->assertSame(['atomic', 'knowledge'], $checkpoint['request']['compare']);
        $this->assertCount(1, $checkpoint['calls']);
        $this->assertSame($personal->id, $checkpoint['calls'][0]['resolved_model_id']);
        $this->assertSame('personal', $checkpoint['calls'][0]['resolved_model_source']);
        $this->assertSame([$personal->id], $reviewer->modelIds);

        $this->artisan('geoflow:evaluate-ai-quality', $arguments)->assertSuccessful();

        $this->assertSame([$personal->id, $shared->id], $reviewer->modelIds);
        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mixed', $report['model_usage_mode']);
        $this->assertNull($report['model']);
        $this->assertSame([$personal->id, $shared->id], array_column($report['calls'], 'resolved_model_id'));
        $this->assertSame(['personal', 'shared'], array_column($report['calls'], 'resolved_model_source'));
        $this->assertSame([
            ['id' => $personal->id, 'name' => $personal->name, 'source' => 'personal', 'call_count' => 1],
            ['id' => $shared->id, 'name' => $shared->name, 'source' => 'shared', 'call_count' => 1],
        ], $report['models_used']);
        $this->assertSame(1, (int) $personal->fresh()->total_used);
        $this->assertSame(1, (int) $shared->fresh()->total_used);
        $this->assertFileDoesNotExist($checkpointPath);
    }

    public function test_live_evaluation_automatically_prefers_the_admins_personal_model_over_shared_fallback(): void
    {
        $provider = $this->admin('live-shared-provider');
        $admin = $this->admin('live-personal-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $shared = $this->model($provider, 'shared-model', 1);
        $invalidPersonal = $this->model($admin, 'invalid-personal-model', 1);
        $invalidPersonal->forceFill(['api_key' => ''])->save();
        $personal = $this->model($admin, 'personal-model', 100);
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-personal-first');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertSuccessful();

        $this->assertSame([$personal->id], $reviewer->modelIds);
        $this->assertNotSame($invalidPersonal->id, $reviewer->modelIds[0]);
        $this->assertNotSame($shared->id, $reviewer->modelIds[0]);
        $reportJson = (string) File::get($basePath.'.json');
        $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($admin->id, $report['execution']['admin_id']);
        $this->assertSame($personal->id, $report['model_id']);
        $this->assertSame('personal', $report['execution']['model_source']);
        $this->assertArrayNotHasKey('api_key', $report['execution']);
        $this->assertArrayNotHasKey('api_url', $report['execution']);
        $this->assertStringNotContainsString('test-secret-', $reportJson);
        $this->assertStringNotContainsString('secret.example.test', $reportJson);
        $this->assertSame('single', $report['model_usage_mode']);
        $this->assertSame([[
            'id' => $personal->id,
            'name' => $personal->name,
            'source' => 'personal',
            'call_count' => 1,
        ]], $report['models_used']);
        $this->assertSame($personal->id, $report['cases'][0]['resolved_model_id']);
        $this->assertSame('personal', $report['cases'][0]['resolved_model_source']);
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usageEvent->status);
        $this->assertSame('ai_quality_live_cli', $usageEvent->business_source);
        $this->assertSame($admin->id, $usageEvent->execution_admin_id);

        $personal->forceFill([
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ])->save();
        [$fallbackDataset, $fallbackBase] = $this->liveFixture('live-shared-fallback');
        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $fallbackDataset,
            '--output' => $fallbackBase,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertSuccessful();
        $fallbackReport = json_decode((string) File::get($fallbackBase.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$personal->id, $shared->id], $reviewer->modelIds);
        $this->assertSame($shared->id, $fallbackReport['model_id']);
        $this->assertSame('shared', $fallbackReport['execution']['model_source']);
    }

    public function test_live_structured_output_fallback_records_each_real_provider_call_once(): void
    {
        $admin = $this->admin('live-provider-attempt-ledger');
        $model = $this->model($admin, 'live-provider-attempt-model');
        ArticleQualityReviewerAgent::fake(static function (): never {
            throw new ArticleAiQualityRuntimeException('structured_output_unsupported');
        })->preventStrayPrompts();
        ArticleQualityJsonReviewerAgent::fake([[
            'summary' => '检查完成。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ]])->preventStrayPrompts();
        [$datasetPath, $basePath] = $this->liveFixture('live-provider-attempt-ledger');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertSuccessful();

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame($events[0]->request_id, $events[1]->request_id);
        $this->assertSame(['provider-1', 'provider-2'], $events->pluck('call_key')->all());
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
    }

    public function test_live_fallback_marks_the_returned_attempt_revoked_when_access_changes_before_acceptance(): void
    {
        $admin = $this->admin('live-provider-fallback-revoked');
        $model = $this->model($admin, 'live-provider-fallback-revoked-model');
        ArticleQualityReviewerAgent::fake(static function (): never {
            throw new ArticleAiQualityRuntimeException('structured_output_unsupported');
        })->preventStrayPrompts();
        ArticleQualityJsonReviewerAgent::fake(function () use ($admin): string {
            Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');

            return json_encode([
                'summary' => '检查完成。',
                'promotion_context' => 'informational',
                'reviewed_claim_hashes' => [],
                'issues' => [],
                'uncertainties' => [],
                'truncated_issue_count' => 0,
            ], JSON_THROW_ON_ERROR);
        })->preventStrayPrompts();
        [$datasetPath, $basePath] = $this->liveFixture('live-provider-fallback-revoked');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertFailed();

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_REVOKED],
            $events->pluck('status')->all(),
        );
        $this->assertFileDoesNotExist($basePath.'.json');
        $this->assertFileDoesNotExist($basePath.'.md');
    }

    public function test_live_evaluation_reports_each_call_when_automatic_candidates_change_between_cases(): void
    {
        $provider = $this->admin('live-mixed-provider');
        $admin = $this->admin('live-mixed-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'live-mixed-personal', 1);
        $personal->forceFill(['daily_limit' => 1])->save();
        $shared = $this->model($provider, 'live-mixed-shared', 1);
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-mixed-models');
        $dataset = json_decode((string) File::get($datasetPath), true, flags: JSON_THROW_ON_ERROR);
        $second = $dataset['cases'][0];
        $second['id'] = 'safe-case-2';
        $dataset['cases'][] = $second;
        $dataset['requirements']['total_cases'] = 2;
        $dataset['requirements']['calibration'] = 2;
        File::put($datasetPath, json_encode($dataset, JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertSuccessful();

        $this->assertSame([$personal->id, $shared->id], $reviewer->modelIds);
        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('mixed', $report['model_usage_mode']);
        $this->assertNull($report['model_id']);
        $this->assertSame('mixed', $report['execution']['model_source']);
        $this->assertSame([$personal->id, $shared->id], array_column($report['calls'], 'resolved_model_id'));
        $this->assertSame(['personal', 'shared'], array_column($report['calls'], 'resolved_model_source'));
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame($events[0]->request_id, $events[1]->request_id);
        $this->assertSame(['provider-1', 'provider-2'], $events->pluck('call_key')->all());
        $this->assertSame(['personal', 'shared'], $events->pluck('model_source')->all());
    }

    public function test_live_evaluation_skips_a_personal_candidate_whose_last_quota_is_consumed_before_lock(): void
    {
        $provider = $this->admin('live-race-shared-provider');
        $admin = $this->admin('live-race-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'live-race-personal', 1);
        $personal->forceFill(['daily_limit' => 1])->save();
        $shared = $this->model($provider, 'live-race-shared', 1);
        $state = (object) ['consumed' => false];
        $this->app->instance(
            DirectAdminAiInvocationBoundaryHook::class,
            new class((int) $personal->id, $state) extends DirectAdminAiInvocationBoundaryHook
            {
                public function __construct(
                    private readonly int $personalModelId,
                    private readonly object $state,
                ) {}

                public function beforeCandidateLock(DirectAdminAiExecutionContext $context, AiModel $candidate): void
                {
                    if ($this->state->consumed || (int) $candidate->id !== $this->personalModelId) {
                        return;
                    }
                    $this->state->consumed = true;
                    DB::table('ai_models')->where('id', $this->personalModelId)->update([
                        'used_today' => 1,
                        'total_used' => 1,
                        'usage_date' => now()->toDateString(),
                    ]);
                }
            },
        );
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-quota-race-shared-fallback');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertSuccessful();

        $report = json_decode((string) File::get($basePath.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($state->consumed);
        $this->assertSame([$shared->id], $reviewer->modelIds);
        $this->assertSame($shared->id, $report['model_id']);
        $this->assertSame('shared', $report['execution']['model_source']);
        $this->assertSame(1, (int) $personal->fresh()->total_used);
        $this->assertSame(1, (int) $shared->fresh()->used_today);
        $this->assertSame(1, (int) $shared->fresh()->total_used);
    }

    public function test_live_evaluation_skips_a_personal_model_while_its_quality_circuit_is_open(): void
    {
        config()->set('geoflow.ai_quality_circuit_consecutive_failures', 1);
        $provider = $this->admin('live-circuit-shared-provider');
        $admin = $this->admin('live-circuit-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'live-circuit-personal', 1);
        $shared = $this->model($provider, 'live-circuit-shared', 1);
        app(ArticleAiQualityProviderCircuitBreaker::class)->recordFailure(
            $personal,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
        );
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-circuit-shared-fallback');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertSuccessful();

        $this->assertSame([$shared->id], $reviewer->modelIds);
        $this->assertSame(0, (int) $personal->fresh()->used_today);
        $this->assertSame(1, (int) $shared->fresh()->used_today);
    }

    public function test_live_evaluation_rejects_an_explicit_model_while_its_quality_circuit_is_open(): void
    {
        config()->set('geoflow.ai_quality_circuit_consecutive_failures', 1);
        $admin = $this->admin('live-explicit-circuit-admin');
        $model = $this->model($admin, 'live-explicit-circuit-model');
        app(ArticleAiQualityProviderCircuitBreaker::class)->recordFailure(
            $model,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
        );
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-explicit-circuit-open');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertFailed();

        $this->assertSame([], $reviewer->modelIds);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertFileDoesNotExist($basePath.'.json');
    }

    public function test_live_evaluation_fails_without_reserving_quota_when_all_quality_circuits_are_open(): void
    {
        config()->set('geoflow.ai_quality_circuit_consecutive_failures', 1);
        $provider = $this->admin('live-all-circuits-provider');
        $admin = $this->admin('live-all-circuits-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'live-all-circuits-personal', 1);
        $shared = $this->model($provider, 'live-all-circuits-shared', 1);
        $breaker = app(ArticleAiQualityProviderCircuitBreaker::class);
        foreach ([$personal, $shared] as $model) {
            $breaker->recordFailure($model, new ArticleAiQualityRuntimeException('provider_timeout', true));
        }
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-all-circuits-open');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertFailed();

        $this->assertSame([], $reviewer->modelIds);
        $this->assertSame(0, (int) $personal->fresh()->used_today);
        $this->assertSame(0, (int) $shared->fresh()->used_today);
        $this->assertFileDoesNotExist($basePath.'.json');
    }

    public function test_live_evaluation_does_not_fail_over_an_authentication_circuit_to_a_shared_model(): void
    {
        config()->set('geoflow.ai_quality_circuit_consecutive_failures', 5);
        config()->set('geoflow.ai_quality_circuit_sample_size', 2);
        config()->set('geoflow.ai_quality_circuit_failure_percent', 50);
        $provider = $this->admin('live-auth-circuit-provider');
        $admin = $this->admin('live-auth-circuit-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $personal = $this->model($admin, 'live-auth-circuit-personal', 1);
        $shared = $this->model($provider, 'live-auth-circuit-shared', 1);
        $breaker = app(ArticleAiQualityProviderCircuitBreaker::class);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $breaker->recordFailure(
                $personal,
                new ArticleAiQualityRuntimeException('provider_authentication_failed'),
            );
        }
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-auth-circuit-no-fallback');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
        ])->assertFailed();

        $this->assertSame([], $reviewer->modelIds);
        $this->assertSame(0, (int) $personal->fresh()->used_today);
        $this->assertSame(0, (int) $shared->fresh()->used_today);
        $this->assertFileDoesNotExist($basePath.'.json');
    }

    public function test_live_evaluation_rejects_a_peer_or_system_model_before_reviewer_or_output(): void
    {
        $provider = $this->admin('live-isolated-provider');
        $admin = $this->admin('live-isolated-admin', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $this->model($provider, 'live-isolated-shared-fallback');
        $peer = $this->admin('live-peer-admin', 'admin');
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);

        foreach ([
            'peer' => $this->model($peer, 'peer-model'),
            'system' => $this->model($admin, 'system-model', scope: AiModel::ACCESS_SCOPE_SYSTEM_ONLY),
            'embedding' => tap($this->model($admin, 'embedding-model'), static function (AiModel $model): void {
                $model->forceFill(['model_type' => 'embedding'])->save();
            }),
            'exhausted' => tap($this->model($admin, 'exhausted-model'), static function (AiModel $model): void {
                $model->forceFill([
                    'daily_limit' => 1,
                    'used_today' => 1,
                    'usage_date' => now()->toDateString(),
                ])->save();
            }),
        ] as $suffix => $model) {
            [$datasetPath, $basePath] = $this->liveFixture('live-reject-'.$suffix);

            $this->artisan('geoflow:evaluate-ai-quality', [
                '--dataset' => $datasetPath,
                '--output' => $basePath,
                '--live' => true,
                '--admin' => $admin->id,
                '--model' => $model->id,
            ])->assertFailed();

            $this->assertFileDoesNotExist($basePath.'.json');
            $this->assertFileDoesNotExist($basePath.'.md');
        }

        $this->assertSame([], $reviewer->modelIds);
    }

    public function test_live_evaluation_discards_the_result_when_access_is_revoked_during_review(): void
    {
        $admin = $this->admin('live-revoked-admin', 'admin');
        $model = $this->model($admin, 'revoked-model');
        $reviewer = $this->recordingReviewer(function () use ($admin): void {
            Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');
        });
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-revoked-during-review');
        File::put($basePath.'.json', 'historical-json');
        File::put($basePath.'.md', 'historical-markdown');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertFailed();

        $this->assertSame([$model->id], $reviewer->modelIds);
        $this->assertSame('historical-json', File::get($basePath.'.json'));
        $this->assertSame('historical-markdown', File::get($basePath.'.md'));
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $usageEvent->status);
        $this->assertSame('ai_config_access_revoked', $usageEvent->error_code);
    }

    public function test_live_report_publish_restores_the_historical_pair_when_access_changes_during_commit(): void
    {
        $admin = $this->admin('live-publish-revoked-admin', 'admin');
        $model = $this->model($admin, 'live-publish-revoked-model');
        $context = app(DirectAdminAiExecutionGuard::class)->freeze(
            $admin,
            'ai_quality_evaluation',
            (int) $admin->id,
            requestedModelId: (int) $model->id,
        );
        [, $basePath] = $this->liveFixture('live-revoked-during-publish');
        File::put($basePath.'.json', 'historical-json');
        File::put($basePath.'.md', 'historical-markdown');

        $adminReads = 0;
        DB::listen(function (QueryExecuted $query) use ($admin, &$adminReads): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'from "admins"') && ! str_contains($sql, 'from `admins`')) {
                return;
            }
            $adminReads++;
            if ($adminReads === 3) {
                DB::table('admins')->where('id', $admin->id)->increment('ai_config_access_version');
            }
        });

        $publish = new ReflectionMethod(EvaluateArticleAiQualityCommand::class, 'publishLiveReport');
        try {
            $publish->invoke(
                app(EvaluateArticleAiQualityCommand::class),
                $context,
                $model,
                $basePath,
                'new-json',
                'new-markdown',
            );
            $this->fail('Expected the final publication access check to reject this report.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $this->assertSame(4, $adminReads);
        $this->assertSame('historical-json', File::get($basePath.'.json'));
        $this->assertSame('historical-markdown', File::get($basePath.'.md'));
        $this->assertSame([], glob(dirname($basePath).'/.'.basename($basePath).'.*.tmp') ?: []);
    }

    public function test_live_evaluation_holds_the_model_mutation_lock_for_each_review_and_releases_it(): void
    {
        $admin = $this->admin('live-review-lock-owner');
        $model = $this->model($admin, 'live-review-lock-model');
        $blockedMutation = null;
        $reviewer = $this->recordingReviewer(function () use ($admin, $model, &$blockedMutation): void {
            $blockedMutation = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                ['name' => 'blocked during CLI review'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
        });
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-review-lock');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertSuccessful();

        $this->assertNotNull($blockedMutation);
        $this->assertFalse($blockedMutation->succeeded());
        $this->assertSame('task', $blockedMutation->error);
        $this->assertTrue(app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'allowed after CLI review'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        )->succeeded());
    }

    public function test_live_evaluation_rejects_an_inactive_shared_provider_before_reviewer_or_output(): void
    {
        $provider = $this->admin('live-inactive-provider');
        $admin = $this->admin('live-inactive-provider-consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $model = $this->model($provider, 'inactive-provider-model');
        $provider->forceFill(['status' => 'inactive'])->save();
        $reviewer = $this->recordingReviewer();
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        [$datasetPath, $basePath] = $this->liveFixture('live-inactive-provider');

        $this->artisan('geoflow:evaluate-ai-quality', [
            '--dataset' => $datasetPath,
            '--output' => $basePath,
            '--live' => true,
            '--admin' => $admin->id,
            '--model' => $model->id,
        ])->assertFailed();

        $this->assertSame([], $reviewer->modelIds);
        $this->assertFileDoesNotExist($basePath.'.json');
        $this->assertFileDoesNotExist($basePath.'.md');
    }

    /** @return object&ArticleAiQualityReviewer */
    private function recordingReviewer(?\Closure $afterReview = null): object
    {
        return new class($afterReview) implements ArticleAiQualityReviewer
        {
            /** @var list<int> */
            public array $modelIds = [];

            public function __construct(private readonly ?\Closure $afterReview) {}

            public function review(AiModel $model, string $instructions): array
            {
                $this->modelIds[] = (int) $model->id;
                ($this->afterReview ?? static function (): void {})();

                return [
                    'result' => [
                        'summary' => '检查完成。',
                        'promotion_context' => 'informational',
                        'reviewed_claim_hashes' => [],
                        'issues' => [],
                        'uncertainties' => [],
                        'truncated_issue_count' => 0,
                    ],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
                ];
            }
        };
    }

    /** @return array{string,string} */
    private function liveFixture(string $name): array
    {
        $directory = storage_path('framework/testing/'.$name);
        File::deleteDirectory($directory);
        File::ensureDirectoryExists($directory);
        $datasetPath = $directory.'/dataset.json';
        $basePath = $directory.'/report';
        File::put($datasetPath, json_encode([
            'version' => $name,
            'requirements' => ['total_cases' => 1, 'calibration' => 1, 'regression' => 0, 'blind' => 0],
            'cases' => [[
                'id' => 'safe-case',
                'split' => 'calibration',
                'inspection_scope' => 'full',
                'article' => ['title' => 'Safe', 'content' => 'Safe content.'],
                'facts' => [],
                'evidence' => [],
                'expected' => ['decision' => 'passed', 'issue_codes' => []],
            ]],
        ], JSON_THROW_ON_ERROR));

        return [$datasetPath, $basePath];
    }

    /** @param array<string,mixed> $attributes */
    private function admin(string $username, string $role = 'super_admin', array $attributes = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill($attributes)->save();

        return $admin;
    }

    private function model(
        Admin $owner,
        string $name,
        int $priority = 100,
        string $scope = AiModel::ACCESS_SCOPE_USER_CONTENT,
    ): AiModel {
        $model = AiModel::query()->create([
            'name' => $name,
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-secret-'.$name),
            'api_url' => 'https://secret.example.test/v1',
            'model_id' => $name,
            'model_type' => 'chat',
            'failover_priority' => $priority,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $scope,
        ])->save();

        return $model;
    }
}
