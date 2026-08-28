<?php

namespace Tests\Feature;

use App\Contracts\ArticleAiQualityReviewer;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ArticleAiQualityInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_passing_async_check_preserves_an_explicit_manual_rejection(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('manual-rejection', needReview: false);
        $article->forceFill(['review_status' => 'rejected'])->save();

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $completed = $service->process($check);

        $this->assertSame('passed', $completed->decision);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('rejected', $article->fresh()->review_status);
    }

    public function test_long_articles_continue_one_segment_per_job_until_complete(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('segmented-continuation', needReview: false);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => str_repeat('分段质检内容。', 2200)])->save();
        });

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        $firstPass = $service->process($check);

        $this->assertSame('queued', $firstPass->status);
        $this->assertGreaterThan(1, $firstPass->segment_count);
        $this->assertSame(1, $firstPass->segments()->where('status', 'completed')->count());
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);

        $result = $firstPass;
        for ($attempt = 0; $attempt < 10 && $result->status !== 'completed'; $attempt++) {
            $result = $service->process((int) $result->id);
        }

        $this->assertSame('completed', $result->status);
        $this->assertSame($result->segment_count, $result->segments()->where('status', 'completed')->count());
        $this->assertSame(900, (new ProcessArticleAiQualityJob((int) $result->id))->timeout);
        $this->assertFalse((new ProcessArticleAiQualityJob((int) $result->id))->failOnTimeout);
    }

    public function test_a_stale_check_terminalizes_its_segments_and_holds_the_article_for_review(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('stale-segment', needReview: false);
        $article->forceFill(['review_status' => 'approved'])->save();

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => '质检排队后发生变化的正文。'])->save();
        });

        $stale = $service->process($check);

        $this->assertSame('stale', $stale->status);
        $this->assertSame('stale', $stale->segments->first()->status);
        $this->assertSame('input_changed', $stale->segments->first()->error_code);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('pending', $article->fresh()->review_status);
    }

    public function test_smart_failover_uses_the_next_active_model_and_records_a_sanitized_attempt_trace(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            /** @var list<int> */
            public array $modelIds = [];

            public int $failingModelId = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->modelIds[] = (int) $model->id;
                if ((int) $model->id === $this->failingModelId) {
                    throw new \RuntimeException('temporary primary model timeout');
                }

                return [
                    'result' => [
                        'summary' => '备用模型完成质检。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => ['totalTokens' => 30],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('smart-failover', needReview: true);
        $primaryModelId = (int) $article->task()->value('ai_model_id');
        $reviewer->failingModelId = $primaryModelId;
        $fallback = AiModel::query()->create([
            'name' => '质检备用模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-fallback-model',
            'api_url' => 'https://fallback.example.test',
            'status' => 'active',
            'model_type' => 'chat',
            'failover_priority' => 1,
        ]);
        $article->task()->update(['model_selection_mode' => 'smart_failover']);

        $service = app(ArticleAiQualityInspectionService::class);
        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $this->assertSame('passed', $completed->decision);
        $this->assertSame([$primaryModelId, (int) $fallback->id], $reviewer->modelIds);
        $this->assertSame((int) $fallback->id, (int) $completed->ai_model_id);
        $this->assertSame((int) $fallback->id, (int) $completed->model_snapshot['id']);
        $this->assertSame([$primaryModelId, (int) $fallback->id], $completed->execution_meta['model_candidate_ids']);
        $this->assertSame('failed', $completed->execution_meta['model_attempts'][0]['outcome']);
        $this->assertSame('succeeded', $completed->execution_meta['model_attempts'][1]['outcome']);
        $this->assertArrayNotHasKey('api_key', $completed->execution_meta['model_attempts'][0]);
    }

    public function test_it_runs_a_check_persists_evidence_and_uses_backend_scoring(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                return [
                    'result' => [
                        'summary' => '数据与知识依据一致。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => ['promptTokens' => 100, 'completionTokens' => 20, 'totalTokens' => 120],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });

        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '企业资料',
            'content' => '标准价格为 980 元。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'kb-source-v1',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '标准价格为 980 元。',
            'content_hash' => hash('sha256', '标准价格为 980 元。'),
            'source_hash' => 'kb-source-v1',
            'chunk_title' => '价格说明',
            'section_path' => '产品价格',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $task = Task::query()->create([
            'name' => '质检任务',
            'ai_model_id' => $model->id,
            'need_review' => 1,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '质检', 'slug' => 'quality-inspection']);
        $author = Author::query()->create(['name' => '作者']);
        $article = Article::query()->create([
            'title' => '产品价格说明',
            'slug' => 'quality-service-test',
            'content' => '标准价格为 980 元。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $completed = $service->process($check);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame(100, $completed->score);
        $this->assertSame('sufficient', $completed->knowledge_coverage);
        $this->assertSame('K1', $completed->evidence_snapshot[0]['id']);
        $this->assertSame('sufficient', $completed->fact_candidates_snapshot[0]['coverage_status']);
        $this->assertSame(1, $completed->completed_segment_count);
        $this->assertSame(120, $completed->usage_meta['total_tokens']);
        $this->assertNull($completed->active_dedupe_key);
        $this->assertSame('completed', $completed->segments->first()->status);
    }

    public function test_a_retryable_job_error_requeues_the_check_and_marks_the_running_segment_for_retry(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                throw new \RuntimeException('temporary model timeout');
            }
        });

        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '重试质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-retry-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '重试质检任务',
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '重试质检知识库',
            'content' => '等待模型质检的正文依据。',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '重试质检', 'slug' => 'quality-retry']);
        $author = Author::query()->create(['name' => '重试作者']);
        $article = Article::query()->create([
            'title' => '重试质检文章',
            'slug' => 'quality-retry-article',
            'content' => '等待模型质检的正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $job = new ProcessArticleAiQualityJob((int) $check->id);

        try {
            $job->handle($service);
            $this->fail('Expected the temporary reviewer failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('temporary model timeout', $exception->getMessage());
        }

        $check->refresh();
        $this->assertSame('queued', $check->status);
        $this->assertSame('error', $check->decision);
        $this->assertNotNull($check->active_dedupe_key);
        $this->assertNull($check->finished_at);
        $this->assertSame('failed', $check->segments()->firstOrFail()->status);
        $this->assertSame('model_timeout', $check->segments()->firstOrFail()->error_code);
    }

    public function test_an_interrupted_running_check_can_resume_when_the_queue_redelivers_it(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('resume-running', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->save();

        $completed = $service->process($check->id, allowRunningRecovery: true);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
    }

    public function test_a_fresh_running_check_is_not_claimed_by_a_second_worker(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('fresh-running', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ])->save();

        $returned = $service->process($check->id, allowRunningRecovery: true);

        $this->assertSame('running', $returned->status);
        $this->assertSame(0, $reviewer->calls);
        $this->assertSame(0, $returned->attempt_count);
    }

    public function test_completion_preserves_manual_audit_entries_appended_while_the_check_is_running(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $checkId = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $check = ArticleAiQualityCheck::query()->findOrFail($this->checkId);
                $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
                $executionMeta['manual_requests'][] = [
                    'trigger' => 'api_manual',
                    'admin_id' => 7,
                    'api_token_id' => 11,
                    'requested_at' => now()->toIso8601String(),
                ];
                $check->forceFill(['execution_meta' => $executionMeta])->save();

                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('audit-merge', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $reviewer->checkId = (int) $check->id;

        $completed = $service->process($check->id);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('api_manual', $completed->execution_meta['manual_requests'][0]['trigger']);
        $this->assertSame(7, $completed->execution_meta['manual_requests'][0]['admin_id']);
        $this->assertSame(11, $completed->execution_meta['manual_requests'][0]['api_token_id']);
    }

    public function test_manual_inspection_preserves_private_distribution_state(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('private-manual', needReview: false);
        $article->forceFill([
            'status' => 'private',
            'review_status' => 'approved',
        ])->save();
        $service = app(ArticleAiQualityInspectionService::class);

        $check = $service->requestManualInspection($article, dispatch: false);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);

        $completed = $service->process($check);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
    }

    public function test_passing_manual_inspection_restores_a_requested_private_target(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('restore-private-target', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->requestManualInspection(
            $article,
            dispatch: false,
            requestedWorkflowState: [
                'status' => 'private',
                'review_status' => 'approved',
                'published_at' => null,
            ],
        );

        $completed = $service->process($check);

        $this->assertSame('passed', $completed->decision);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
    }

    public function test_manual_inspection_persists_an_article_policy_even_when_the_task_is_currently_enabled(): void
    {
        $article = $this->createQualityFixture('manual-snapshot-enabled-task', needReview: false);
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();

        app(ArticleAiQualityInspectionService::class)->requestManualInspection($article, dispatch: false);
        $article->task()->update(['ai_quality_enabled' => false]);

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertSame('manual_article', data_get($article->ai_quality_policy_snapshot, 'source'));
        $this->assertTrue((bool) app(ArticleAiQualityPolicyResolver::class)
            ->resolve($article)['required']);
    }

    public function test_manual_recheck_refreshes_the_article_snapshot_from_the_current_task_configuration(): void
    {
        $article = $this->createQualityFixture('manual-refresh', needReview: false);
        $article->task()->update(['ai_quality_enabled' => false]);
        $service = app(ArticleAiQualityInspectionService::class);
        $service->requestManualInspection($article, dispatch: false);
        $replacement = KnowledgeBase::query()->create([
            'name' => '替换后的单篇质检知识库',
            'content' => '最新核查依据。',
        ]);
        $article->task->knowledgeBases()->sync([$replacement->id => ['sort_order' => 0]]);

        $service->requestManualInspection($article->fresh(), dispatch: false);

        $this->assertSame(
            [(int) $replacement->id],
            data_get($article->fresh()->ai_quality_policy_snapshot, 'knowledge_base_ids'),
        );
    }

    public function test_manual_inspection_uses_the_active_task_model_when_the_dedicated_quality_model_is_disabled(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('disabled-quality-model', needReview: false);
        $activeTaskModelId = (int) $article->task()->value('ai_model_id');
        $disabledQualityModel = AiModel::query()->create([
            'name' => '已停用专用质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'disabled-quality-model',
            'api_url' => 'https://example.test',
            'status' => 'inactive',
        ]);
        $article->task()->update(['ai_quality_model_id' => $disabledQualityModel->id]);
        $service = app(ArticleAiQualityInspectionService::class);

        $check = $service->requestManualInspection($article->fresh(), dispatch: false);
        $completed = $service->process($check->id);

        $this->assertSame($activeTaskModelId, (int) $check->ai_model_id);
        $this->assertSame($activeTaskModelId, (int) data_get($article->fresh()->ai_quality_policy_snapshot, 'model_id'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
    }

    public function test_a_queued_manual_check_becomes_stale_when_the_task_evidence_changes(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('manual-stale-policy', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->requestManualInspection($article, dispatch: false);
        $replacement = KnowledgeBase::query()->create([
            'name' => '排队后替换的知识库',
            'content' => '服务客户为 900 家。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'manual-stale-policy-source',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $replacement->id,
            'chunk_index' => 0,
            'content' => '服务客户为 900 家。',
            'content_hash' => hash('sha256', '服务客户为 900 家。'),
            'source_hash' => 'manual-stale-policy-source',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $article->task->knowledgeBases()->sync([$replacement->id => ['sort_order' => 0]]);

        $stale = $service->process($check->id);

        $this->assertSame('stale', $stale->status);
        $this->assertSame('input_changed', $stale->error_code);
    }

    public function test_queue_dispatch_failure_marks_the_committed_check_retryable_instead_of_leaving_it_stuck(): void
    {
        $article = $this->createQualityFixture('dispatch-failure', needReview: false);
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('push')->once()->andThrow(new \RuntimeException('queue connection unavailable'));
        Queue::shouldReceive('connection')->andReturn($queue);

        try {
            app(ArticleAiQualityInspectionService::class)->requestManualInspection($article);
            $this->fail('Expected queue dispatch to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('failed', $check->status);
        $this->assertNull($check->active_dedupe_key);
        $this->assertSame('queue_dispatch_failed', $check->error_code);
    }

    private function bindPassingReviewer(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });
    }

    private function createQualityFixture(string $suffix, bool $needReview): Article
    {
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型 '.$suffix,
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-'.$suffix,
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '质检知识库 '.$suffix,
            'content' => '服务客户为 800 家。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'source-'.$suffix,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '服务客户为 800 家。',
            'content_hash' => hash('sha256', '服务客户为 800 家。'),
            'source_hash' => 'source-'.$suffix,
            'chunk_title' => '客户数据',
            'section_path' => '企业资料',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $task = Task::query()->create([
            'name' => '质检任务 '.$suffix,
            'ai_model_id' => $model->id,
            'need_review' => $needReview ? 1 : 0,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create([
            'name' => '质检分类 '.$suffix,
            'slug' => 'quality-'.$suffix,
        ]);
        $author = Author::query()->create(['name' => '质检作者 '.$suffix]);

        return Article::query()->create([
            'title' => '质检文章 '.$suffix,
            'slug' => 'quality-article-'.$suffix,
            'content' => '服务客户为 800 家。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }
}
