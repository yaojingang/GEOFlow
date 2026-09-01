<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminAiExecutionIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_creation_records_the_stable_ai_execution_admin_identity(): void
    {
        $admin = $this->admin('task-owner');
        $model = $this->model($admin, 'task-model');
        $library = TitleLibrary::query()->create(['name' => 'Execution identity titles']);
        $prompt = Prompt::query()->create([
            'name' => 'Execution identity prompt',
            'type' => 'content',
            'content' => 'Write the article.',
        ]);

        $created = app(TaskLifecycleService::class)->createTask([
            'name' => 'Execution identity task',
            'title_library_id' => $library->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
            'status' => 'paused',
            'model_access_admin_id' => 999999,
            'model_access_policy_version' => 999999,
        ], (int) $admin->id);

        $task = Task::query()->findOrFail((int) $created['id']);

        $this->assertSame((int) $admin->id, (int) $task->model_access_admin_id);
        $this->assertSame('admin', $task->model_access_admin_role);
        $this->assertSame(1, (int) $task->model_access_policy_version);
    }

    public function test_enqueue_copies_authoritative_identity_and_removes_sensitive_payload_fields(): void
    {
        Queue::fake();
        $admin = $this->admin('queue-owner', ['ai_config_access_version' => 7]);
        $model = $this->model($admin, 'queue-model');
        $task = Task::query()->create([
            'name' => 'Queue identity task',
            'ai_model_id' => $model->id,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id, 'https://jobs.example.test?token=job-token', [
            'source' => 'api_manual_start',
            'model_access_admin_id' => 999999,
            'ai_config_access_version' => 999999,
            'api_key' => 'never-log-this-key',
            'providerApiKey' => 'camel-case-api-key',
            'access_token' => 'access-token-value',
            'bearer' => 'bearer-value',
            'clientSecret' => 'client-secret-value',
            'key' => 'short-key-value',
            'base_url' => 'https://private.example.test/v1?token=query-token-value',
            'note' => 'Prompt text with bearer note-secret-value',
            'nested' => [
                'endpoint' => 'https://private.example.test',
                'prompt' => 'private prompt',
                'safe_looking' => 'nested-secret-value',
            ],
        ]);

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame((int) $admin->id, (int) $run->model_access_admin_id);
        $this->assertSame('admin', $run->model_access_admin_role);
        $this->assertSame(7, (int) $run->ai_config_access_version);
        $this->assertSame((int) $model->id, (int) $run->requested_ai_model_id);
        $this->assertSame(1, (int) $run->resolver_policy_version);

        $serializedMeta = json_encode($run->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('never-log-this-key', $serializedMeta);
        $this->assertStringNotContainsString('camel-case-api-key', $serializedMeta);
        $this->assertStringNotContainsString('access-token-value', $serializedMeta);
        $this->assertStringNotContainsString('bearer-value', $serializedMeta);
        $this->assertStringNotContainsString('client-secret-value', $serializedMeta);
        $this->assertStringNotContainsString('short-key-value', $serializedMeta);
        $this->assertStringNotContainsString('query-token-value', $serializedMeta);
        $this->assertStringNotContainsString('note-secret-value', $serializedMeta);
        $this->assertStringNotContainsString('nested-secret-value', $serializedMeta);
        $this->assertStringNotContainsString('private.example.test', $serializedMeta);
        $this->assertStringNotContainsString('private prompt', $serializedMeta);
        $this->assertNull(data_get($run->meta, 'payload.model_access_admin_id'));
        $this->assertSame('generate_article', data_get($run->meta, 'job_type'));
        $this->assertSame(['source' => 'api_manual_start'], data_get($run->meta, 'payload'));

        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'context-worker'));
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run->fresh());
        $this->assertSame('task-run:'.$run->id, $context->requestId);
        $this->assertSame((int) $admin->id, $context->modelAccessAdminId);
        $this->assertArrayNotHasKey('execution_lease_token', $context->toSafeArray());
        $this->assertArrayNotHasKey('execution_lease_token', $run->fresh()->toArray());
        $this->assertStringNotContainsString(
            (string) $run->fresh()->execution_lease_token,
            json_encode($context, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString('never-log-this-key', json_encode($context->toSafeArray(), JSON_THROW_ON_ERROR));

        $unsafeTask = $this->executableTask($admin, $model, 'Unsafe API metadata task');
        $unsafeRunId = app(JobQueueService::class)->enqueueTaskJob(
            (int) $unsafeTask->id,
            'generate_article',
            ['source' => 'api_key=source-secret'],
        );
        $unsafeMeta = TaskRun::query()->findOrFail((int) $unsafeRunId)->meta;
        $this->assertSame([], data_get($unsafeMeta, 'payload'));
        $this->assertStringNotContainsString('source-secret', json_encode($unsafeMeta, JSON_THROW_ON_ERROR));
    }

    public function test_claim_permanently_fails_when_sharing_was_revoked_after_enqueue(): void
    {
        Queue::fake();
        $provider = $this->admin('shared-provider', ['role' => 'super_admin']);
        $admin = $this->admin('revoked-runner', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 3,
        ]);
        $model = $this->model($provider, 'shared-run-model');
        $task = $this->executableTask($admin, $model, 'Revoked queue task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $admin->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => 4,
        ])->save();
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);

        $claimed = app(JobQueueService::class)->claimPendingJobById((int) $runId, 'worker-1');

        $this->assertNull($claimed);
        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertSame('ai_config_access_revoked', $run->error_message);
        $this->assertFalse((bool) data_get($run->meta, 'retryable', true));
        $this->assertSame(0, (int) data_get($run->meta, 'attempt_count', -1));
    }

    public function test_queue_job_uses_persisted_context_without_auth_and_never_retries_authorization_failures(): void
    {
        Queue::fake();
        $admin = $this->admin('headless-runner', ['ai_config_access_version' => 2]);
        $model = $this->model($admin, 'headless-model');
        $task = $this->executableTask($admin, $model, 'Headless queue task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        auth('admin')->logout();

        $worker = Mockery::mock(WorkerExecutionService::class);
        $worker->shouldReceive('executeTask')
            ->once()
            ->withArgs(function (int $taskId, AiExecutionContext $context) use ($task, $admin): bool {
                $this->assertNull(auth('admin')->user());
                $this->assertSame((int) $task->id, $taskId);
                $this->assertSame((int) $admin->id, $context->modelAccessAdminId);

                return true;
            })
            ->andThrow(AiModelAccessException::configAccessRevoked($admin));

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            $worker,
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('ai_config_access_revoked', $run->error_code);
        $this->assertFalse((bool) data_get($run->meta, 'retryable', true));
        $this->assertSame(0, (int) data_get($run->meta, 'attempt_count', -1));
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 1);
    }

    public function test_revocation_during_the_provider_call_discards_the_result_without_business_side_effects(): void
    {
        Queue::fake();
        Category::query()->create([
            'name' => 'Revocation category',
            'slug' => 'revocation-category',
        ]);
        $admin = $this->admin('mid-call-runner', ['ai_config_access_version' => 5]);
        $model = $this->model($admin, 'mid-call-model');
        $this->model($admin, 'mid-call-fallback-model');
        $library = TitleLibrary::query()->create(['name' => 'Mid-call titles']);
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Discarded provider result',
            'keyword' => 'revocation',
        ]);
        $task = $this->executableTask($admin, $model, 'Mid-call revocation task');
        $task->forceFill([
            'title_library_id' => $library->id,
            'draft_limit' => 10,
            'article_limit' => 10,
            'created_count' => 0,
            'loop_count' => 0,
            'model_selection_mode' => 'smart_failover',
        ])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        $providerCalls = 0;
        Http::fake(function () use ($admin, &$providerCalls) {
            $providerCalls++;
            $admin->newQuery()->whereKey($admin->id)->update([
                'ai_config_access_version' => 6,
            ]);

            return Http::response([
                'model' => 'mid-call-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Discarded\n\nProvider result."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 8, 'completion_tokens' => 12, 'total_tokens' => 20],
            ]);
        });

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $this->assertSame(0, Article::query()->where('task_id', $task->id)->count());
        $this->assertSame(0, (int) $title->fresh()->used_count);
        $this->assertSame(0, (int) $title->fresh()->usage_count);
        $this->assertSame(0, (int) $task->fresh()->created_count);
        $this->assertSame(0, (int) $task->fresh()->loop_count);
        $this->assertSame(1, $providerCalls);
        $this->assertSame('failed', TaskRun::query()->findOrFail((int) $runId)->status);
        $this->assertSame('ai_config_access_revoked', TaskRun::query()->findOrFail((int) $runId)->error_code);
        $this->assertSame(0, (int) data_get(TaskRun::query()->findOrFail((int) $runId)->meta, 'attempt_count'));
    }

    public function test_business_result_and_task_run_terminal_state_commit_before_worker_returns(): void
    {
        Queue::fake();
        $admin = $this->admin('atomic-completion-runner');
        $model = $this->model($admin, 'atomic-completion-model');
        [$task, $title] = $this->generationTask($admin, $model, 'Atomic completion task');
        $imageLibrary = ImageLibrary::query()->create(['name' => 'Atomic completion images']);
        $image = Image::query()->create([
            'library_id' => $imageLibrary->id,
            'filename' => 'atomic.png',
            'original_name' => 'Atomic image.png',
            'file_path' => 'images/atomic.png',
            'managed_path_hash' => hash('sha256', 'images/atomic.png'),
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $task->forceFill([
            'image_library_id' => $imageLibrary->id,
            'image_count' => 1,
        ])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'atomic-worker'));
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail((int) $runId));
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::fake(Http::response([
            'model' => 'atomic-completion-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "# Atomic\n\nCommitted content."],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
        ]));

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id, $context);

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('completed', $run->status);
        $this->assertSame((int) $result['article_id'], (int) $run->article_id);
        $this->assertNull($run->execution_lease_token);
        $this->assertSame(1, Article::query()->where('task_id', $task->id)->count());
        $this->assertSame(1, ArticleImage::query()->where('image_id', $image->id)->count());
        $this->assertSame(1, (int) $image->fresh()->used_count);
        $this->assertSame(1, (int) $image->fresh()->usage_count);
        $this->assertSame(1, (int) $title->fresh()->used_count);
        $this->assertSame(1, (int) $title->fresh()->usage_count);
        $this->assertSame(1, (int) $task->fresh()->created_count);
        $this->assertSame(1, (int) $task->fresh()->loop_count);

        TaskRun::query()->whereKey($runId)->update(['started_at' => now()->subMinutes(20)]);
        $this->assertSame(0, app(JobQueueService::class)->recoverStaleJobs(600));
        $this->assertSame(1, Article::query()->where('task_id', $task->id)->count());
    }

    public function test_quality_side_effect_is_created_inside_the_business_completion_transaction(): void
    {
        Queue::fake();
        $admin = $this->admin('quality-fence-runner');
        $model = $this->model($admin, 'quality-fence-model');
        [$task] = $this->generationTask($admin, $model, 'Quality fence task');
        $qualityPrompt = Prompt::query()
            ->where('system_key', 'article_quality.cn_ads_knowledge.v1')
            ->firstOrFail();
        $task->forceFill([
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $qualityPrompt->id,
            'ai_quality_model_id' => $model->id,
        ])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'quality-fence-worker'));
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail((int) $runId));
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::fake(Http::response([
            'model' => 'quality-fence-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "# Quality\n\nCommitted content."],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
        ]));
        $observedTransactionLevel = null;
        $inspection = Mockery::mock(ArticleAiQualityInspectionService::class);
        $inspection->shouldReceive('createOrReuse')
            ->once()
            ->andReturnUsing(function () use (&$observedTransactionLevel) {
                $observedTransactionLevel = DB::transactionLevel();

                return null;
            });
        $this->app->instance(ArticleAiQualityInspectionService::class, $inspection);

        app(WorkerExecutionService::class)->executeTask((int) $task->id, $context);

        $this->assertGreaterThan(1, (int) $observedTransactionLevel);
        $this->assertSame('completed', TaskRun::query()->findOrFail((int) $runId)->status);
    }

    public function test_distribution_observes_a_terminal_run_after_draft_publication(): void
    {
        Queue::fake();
        $admin = $this->admin('distribution-fence-runner');
        $model = $this->model($admin, 'distribution-fence-model');
        [$task] = $this->generationTask($admin, $model, 'Distribution fence task');
        $task->forceFill([
            'next_publish_at' => now()->subMinute(),
            'publish_scope' => 'local_and_distribution',
        ])->save();
        $author = Author::query()->create(['name' => 'Distribution fence author']);
        $category = Category::query()->where('slug', 'execution-identity-category')->firstOrFail();
        $article = Article::query()->create([
            'title' => 'Distribution fence draft',
            'slug' => 'distribution-fence-draft',
            'content' => 'Approved content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'distribution-fence-worker'));
        $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail((int) $runId));
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        $observedRunStatus = null;
        $distribution = Mockery::mock(DistributionOrchestrator::class);
        $distribution->shouldReceive('enqueueForArticle')
            ->once()
            ->with((int) $article->id)
            ->andReturnUsing(function () use ($runId, &$observedRunStatus): array {
                $observedRunStatus = TaskRun::query()->findOrFail((int) $runId)->status;

                return [];
            });
        $this->app->instance(DistributionOrchestrator::class, $distribution);

        app(WorkerExecutionService::class)->executeTask((int) $task->id, $context);

        $this->assertSame('completed', $observedRunStatus);
        $this->assertSame('completed', TaskRun::query()->findOrFail((int) $runId)->status);
        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_inactive_shared_provider_does_not_block_a_personal_fixed_model(): void
    {
        Queue::fake();
        $provider = $this->admin('inactive-unused-provider', ['role' => 'super_admin']);
        $admin = $this->admin('personal-model-runner', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 2,
        ]);
        $personalModel = $this->model($admin, 'personal-fixed-model');
        [$task] = $this->generationTask($admin, $personalModel, 'Personal model task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $provider->forceFill(['status' => 'inactive'])->save();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::fake(Http::response([
            'model' => 'personal-fixed-model',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => "# Personal\n\nPersonal model result."],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
        ]));

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('completed', $run->status);
        $this->assertSame((int) $personalModel->id, (int) $run->resolved_ai_model_id);
        $this->assertSame('personal', $run->resolved_model_source);
        $this->assertSame(1, Article::query()->where('task_id', $task->id)->count());
    }

    public function test_inactive_shared_provider_permanently_rejects_its_requested_model(): void
    {
        Queue::fake();
        $provider = $this->admin('inactive-used-provider', ['role' => 'super_admin']);
        $admin = $this->admin('shared-model-runner', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 9,
        ]);
        $sharedModel = $this->model($provider, 'shared-fixed-model');
        [$task, $title] = $this->generationTask($admin, $sharedModel, 'Shared model task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $provider->forceFill(['status' => 'inactive'])->save();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::preventStrayRequests();

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('ai_config_owner_inactive', $run->error_code);
        $this->assertSame(0, Article::query()->where('task_id', $task->id)->count());
        $this->assertSame(0, (int) $title->fresh()->used_count);
        $this->assertSame(0, (int) data_get($run->meta, 'attempt_count', -1));
    }

    public function test_enforced_enqueue_rejects_a_legacy_task_without_execution_identity(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => 'Missing execution identity',
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);

        try {
            app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
            $this->fail('A task without execution identity must be rejected in enforce mode.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $this->assertSame(0, TaskRun::query()->where('task_id', $task->id)->count());
        Queue::assertNothingPushed();
    }

    public function test_claim_permanently_fails_for_inactive_or_role_changed_execution_admins(): void
    {
        Queue::fake();
        $inactive = $this->admin('inactive-executor');
        $inactiveModel = $this->model($inactive, 'inactive-executor-model');
        $inactiveTask = $this->executableTask($inactive, $inactiveModel, 'Inactive executor task');
        $inactiveRunId = app(JobQueueService::class)->enqueueTaskJob((int) $inactiveTask->id);
        $inactive->forceFill(['status' => 'inactive'])->save();

        $roleChanged = $this->admin('role-changed-executor');
        $roleModel = $this->model($roleChanged, 'role-changed-model');
        $roleTask = $this->executableTask($roleChanged, $roleModel, 'Role changed task');
        $roleRunId = app(JobQueueService::class)->enqueueTaskJob((int) $roleTask->id);
        $roleChanged->forceFill(['role' => 'super_admin'])->save();
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);

        $this->assertNull(app(JobQueueService::class)->claimPendingJobById((int) $inactiveRunId, 'worker-inactive'));
        $this->assertNull(app(JobQueueService::class)->claimPendingJobById((int) $roleRunId, 'worker-role'));
        $this->assertSame('ai_execution_admin_inactive', TaskRun::query()->findOrFail((int) $inactiveRunId)->error_code);
        $this->assertSame('ai_config_access_revoked', TaskRun::query()->findOrFail((int) $roleRunId)->error_code);
    }

    public function test_regular_failures_retry_the_same_run_without_refreshing_execution_identity(): void
    {
        Queue::fake();
        $admin = $this->admin('retry-runner', ['ai_config_access_version' => 11]);
        $model = $this->model($admin, 'retry-model');
        $task = $this->executableTask($admin, $model, 'Retry identity task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $worker = Mockery::mock(WorkerExecutionService::class);
        $worker->shouldReceive('executeTask')
            ->once()
            ->withArgs(fn (int $taskId, AiExecutionContext $context): bool => $taskId === (int) $task->id
                && $context->aiConfigAccessVersion === 11)
            ->andThrow(new RuntimeException('temporary provider failure'));

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            $worker,
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('pending', $run->status);
        $this->assertSame(1, (int) data_get($run->meta, 'attempt_count'));
        $this->assertSame((int) $admin->id, (int) $run->model_access_admin_id);
        $this->assertSame(11, (int) $run->ai_config_access_version);
        $this->assertSame((int) $model->id, (int) $run->requested_ai_model_id);
        Queue::assertPushed(ProcessGeoFlowTaskJob::class, 2);
    }

    public function test_reinstantiated_failed_callback_uses_the_serialized_claim_lease(): void
    {
        Queue::fake();
        $admin = $this->admin('serialized-failure-runner');
        $model = $this->model($admin, 'serialized-failure-model');
        $task = $this->executableTask($admin, $model, 'Serialized failure lease task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $claimLease = '5a36e75b-803a-4a63-b8d4-dc6adcf05d4d';

        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById(
            (int) $runId,
            'serialized-failure-worker',
            $claimLease,
        ));
        $this->assertSame($claimLease, TaskRun::query()->findOrFail((int) $runId)->execution_lease_token);

        (new ProcessGeoFlowTaskJob((int) $runId, $claimLease))->failed(
            new RuntimeException('provider process exited'),
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('pending', $run->status);
        $this->assertNull($run->execution_lease_token);
        $this->assertSame(1, (int) data_get($run->meta, 'attempt_count'));
    }

    public function test_stale_recovery_preserves_the_original_execution_identity_snapshot(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $admin = $this->admin('recovery-runner', ['ai_config_access_version' => 13]);
        $model = $this->model($admin, 'recovery-model');
        $task = $this->executableTask($admin, $model, 'Recovery identity task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $claimed = app(JobQueueService::class)->claimPendingJobById((int) $runId, 'worker-recovery');
        $this->assertIsArray($claimed);
        TaskRun::query()->whereKey($runId)->update(['started_at' => now()->subMinutes(20)]);
        $admin->forceFill(['ai_config_access_version' => 14])->save();

        $this->assertSame(1, app(JobQueueService::class)->recoverStaleJobs(600));

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('pending', $run->status);
        $this->assertSame((int) $admin->id, (int) $run->model_access_admin_id);
        $this->assertSame(13, (int) $run->ai_config_access_version);
        $this->assertSame((int) $model->id, (int) $run->requested_ai_model_id);
        $this->assertSame(1, (int) $run->resolver_policy_version);
    }

    public function test_recovery_rotates_the_execution_lease_and_discards_the_old_workers_provider_result(): void
    {
        Queue::fake();
        $admin = $this->admin('lease-runner', ['ai_config_access_version' => 21]);
        $model = $this->model($admin, 'lease-model');
        [$task, $title] = $this->generationTask($admin, $model, 'Lease fencing task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'old-worker'));
        $oldRun = TaskRun::query()->findOrFail((int) $runId);
        $oldContext = app(AiExecutionContextFactory::class)->fromTaskRun($oldRun);
        $oldLease = (string) $oldRun->execution_lease_token;
        $newLease = null;
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);

        Http::fake(function () use ($runId, &$newLease) {
            TaskRun::query()->whereKey($runId)->update(['started_at' => now()->subMinutes(20)]);
            $this->assertSame(1, app(JobQueueService::class)->recoverStaleJobs(600));
            $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'new-worker'));
            $newLease = (string) TaskRun::query()->findOrFail((int) $runId)->execution_lease_token;

            return Http::response([
                'model' => 'lease-model',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Stale\n\nDiscard this result."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
            ]);
        });

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id, $oldContext);
            $this->fail('The recovered worker lease must fence the old provider result.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertNotSame('', $oldLease);
        $this->assertNotSame($oldLease, $newLease);
        $this->assertSame('running', $run->status);
        $this->assertSame($newLease, $run->execution_lease_token);
        $this->assertNull($run->resolved_ai_model_id);
        $this->assertSame(0, Article::query()->where('task_id', $task->id)->count());
        $this->assertSame(0, (int) $title->fresh()->used_count);
        $this->assertSame(0, (int) $task->fresh()->created_count);

        app(JobQueueService::class)->completeJob(
            (int) $runId,
            (int) $task->id,
            null,
            1,
            executionContext: $oldContext,
        );
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($newLease, $run->fresh()->execution_lease_token);

        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        app(JobQueueService::class)->completeJob((int) $runId, (int) $task->id, null, 1);
        app(JobQueueService::class)->failJob((int) $runId, (int) $task->id, 'unleased callback', 1);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($newLease, $run->fresh()->execution_lease_token);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);

        app(JobQueueService::class)->failJob(
            (int) $runId,
            (int) $task->id,
            'stale worker failure',
            1,
            executionContext: $oldContext,
        );
        app(JobQueueService::class)->failForAiAuthorization(
            (int) $runId,
            (int) $task->id,
            'ai_config_access_revoked',
            1,
            $oldContext,
        );
        app(JobQueueService::class)->failForTaskConfiguration(
            (int) $runId,
            (int) $task->id,
            'stale configuration failure',
            1,
            [],
            $oldContext,
        );
        $run = $run->fresh();
        $this->assertSame('running', $run->status);
        $this->assertSame($newLease, $run->execution_lease_token);
        $this->assertSame(0, (int) data_get($run->meta, 'attempt_count'));
        $this->assertSame('active', $task->fresh()->status);

        (new ProcessGeoFlowTaskJob((int) $runId, $oldLease))->failed(
            new RuntimeException('old worker terminated after recovery'),
        );
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame($newLease, $run->fresh()->execution_lease_token);
    }

    public function test_smart_failover_only_uses_personal_then_shared_authorized_models(): void
    {
        Queue::fake();
        $provider = $this->admin('failover-provider', ['role' => 'super_admin']);
        $admin = $this->admin('failover-runner', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $otherAdmin = $this->admin('unrelated-model-owner');
        $personalModel = $this->model($admin, 'failover-personal-model');
        $sharedModel = $this->model($provider, 'failover-shared-model');
        $unrelatedModel = $this->model($otherAdmin, 'failover-unrelated-model');
        $personalModel->forceFill(['failover_priority' => 10])->save();
        $unrelatedModel->forceFill(['failover_priority' => 20])->save();
        $sharedModel->forceFill(['failover_priority' => 30])->save();
        [$task] = $this->generationTask($admin, $personalModel, 'Authorized failover task');
        $task->forceFill(['model_selection_mode' => 'smart_failover'])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        $requestedModels = [];
        Http::fake(function ($request) use (&$requestedModels) {
            $model = (string) $request['model'];
            $requestedModels[] = $model;
            if ($model === 'failover-personal-model') {
                return Http::response([
                    'model' => $model,
                    'choices' => [],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 1],
                ]);
            }

            return Http::response([
                'model' => $model,
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Shared fallback\n\nAuthorized result."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
            ]);
        });

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame(['failover-personal-model', 'failover-shared-model'], $requestedModels);
        $this->assertNotContains('failover-unrelated-model', $requestedModels);
        $this->assertSame('completed', $run->status);
        $this->assertSame((int) $sharedModel->id, (int) $run->resolved_ai_model_id);
        $this->assertSame('shared', $run->resolved_model_source);
        $this->assertSame(1, Article::query()->where('task_id', $task->id)->count());
    }

    public function test_default_shadow_mode_never_calls_another_regular_admins_private_model(): void
    {
        Queue::fake();
        $provider = $this->admin('shadow-provider', ['role' => 'super_admin']);
        $admin = $this->admin('shadow-runner', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $otherAdmin = $this->admin('shadow-unrelated-owner');
        $personalModel = $this->model($admin, 'shadow-personal-model');
        $sharedModel = $this->model($provider, 'shadow-shared-model');
        $unrelatedModel = $this->model($otherAdmin, 'shadow-unrelated-model');
        $personalModel->forceFill(['failover_priority' => 10])->save();
        $unrelatedModel->forceFill(['failover_priority' => 20])->save();
        $sharedModel->forceFill(['failover_priority' => 30])->save();
        [$task] = $this->generationTask($admin, $personalModel, 'Shadow authorized failover task');
        $task->forceFill(['model_selection_mode' => 'smart_failover'])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.ownership_write_enabled', true);
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);
        $requestedModels = [];
        Http::fake(function ($request) use (&$requestedModels) {
            $model = (string) $request['model'];
            $requestedModels[] = $model;
            if ($model === 'shadow-personal-model') {
                return Http::response([
                    'model' => $model,
                    'choices' => [],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0, 'total_tokens' => 1],
                ]);
            }

            return Http::response([
                'model' => $model,
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Shadow\n\nAuthorized fallback."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
            ]);
        });

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame(['shadow-personal-model', 'shadow-shared-model'], $requestedModels);
        $this->assertNotContains('shadow-unrelated-model', $requestedModels);
        $this->assertSame('completed', $run->status);
        $this->assertSame((int) $sharedModel->id, (int) $run->resolved_ai_model_id);
        $this->assertSame('shared', $run->resolved_model_source);
    }

    public function test_default_shadow_mode_rejects_an_explicit_model_owned_by_another_regular_admin(): void
    {
        Queue::fake();
        $admin = $this->admin('shadow-explicit-runner');
        $otherAdmin = $this->admin('shadow-explicit-owner');
        $otherModel = $this->model($otherAdmin, 'shadow-explicit-private-model');
        [$task] = $this->generationTask($admin, $otherModel, 'Shadow explicit private task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.ownership_write_enabled', true);
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);
        Http::fake();

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        Http::assertNothingSent();
        $run = TaskRun::query()->findOrFail((int) $runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame(AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE, $run->error_code);
        $this->assertSame(0, Article::query()->where('task_id', $task->id)->count());
    }

    public function test_permanent_provider_errors_do_not_trigger_shared_model_failover(): void
    {
        Queue::fake();
        $provider = $this->admin('permanent-error-provider', ['role' => 'super_admin']);
        $sharedModel = $this->model($provider, 'permanent-error-shared-model');
        $cases = [
            ['status' => 401, 'message' => 'invalid api key', 'suffix' => 'auth'],
            ['status' => 403, 'message' => 'permission denied', 'suffix' => 'permission'],
            ['status' => 400, 'message' => 'unsupported parameter', 'suffix' => 'parameter'],
            ['status' => 422, 'message' => 'model capability is incompatible', 'suffix' => 'capability'],
        ];

        foreach ($cases as $case) {
            $admin = $this->admin('permanent-error-'.$case['suffix'], [
                'shared_ai_config_owner_id' => $provider->id,
            ]);
            $personalModel = $this->model($admin, 'permanent-error-'.$case['suffix'].'-model');
            [$task] = $this->generationTask($admin, $personalModel, 'Permanent error '.$case['suffix']);
            $task->forceFill(['model_selection_mode' => 'smart_failover'])->save();
            $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
            config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
            $requestedModels = [];
            Http::fake(function ($request) use ($case, &$requestedModels) {
                $model = (string) $request['model'];
                $requestedModels[] = $model;
                if (str_starts_with($model, 'permanent-error-') && $model !== 'permanent-error-shared-model') {
                    return Http::response([
                        'error' => ['message' => $case['message']],
                    ], $case['status']);
                }

                return Http::response([
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'message' => ['role' => 'assistant', 'content' => "# Shared\n\nThis must not be used."],
                        'finish_reason' => 'stop',
                    ]],
                    'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
                ]);
            });

            (new ProcessGeoFlowTaskJob((int) $runId))->handle(
                app(JobQueueService::class),
                app(WorkerExecutionService::class),
            );

            $this->assertSame(
                ['permanent-error-'.$case['suffix'].'-model'],
                array_values(array_unique($requestedModels)),
            );
            $this->assertNotContains('permanent-error-shared-model', $requestedModels);
            $this->assertNotSame('completed', TaskRun::query()->findOrFail((int) $runId)->status);
            $this->assertSame(0, Article::query()->where('task_id', $task->id)->count());
        }
        $this->assertSame('active', $sharedModel->fresh()->status);
    }

    public function test_each_run_executes_the_requested_model_snapshot_captured_at_enqueue_time(): void
    {
        Queue::fake();
        $admin = $this->admin('model-snapshot-runner');
        $oldModel = $this->model($admin, 'snapshot-old-model');
        $newModel = $this->model($admin, 'snapshot-new-model');
        [$task] = $this->generationTask($admin, $oldModel, 'Model snapshot task');
        $task->forceFill(['is_loop' => 1])->save();
        $oldRunId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $task->forceFill(['ai_model_id' => $newModel->id])->save();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        $requestedModels = [];
        Http::fake(function ($request) use (&$requestedModels) {
            $requestedModels[] = (string) $request['model'];

            return Http::response([
                'model' => (string) $request['model'],
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => "# Snapshot\n\nGenerated content."],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10],
            ]);
        });

        (new ProcessGeoFlowTaskJob((int) $oldRunId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );
        $oldRun = TaskRun::query()->findOrFail((int) $oldRunId);
        $this->assertSame((int) $oldModel->id, (int) $oldRun->requested_ai_model_id);
        $this->assertSame((int) $oldModel->id, (int) $oldRun->resolved_ai_model_id);

        $task->forceFill(['model_selection_mode' => 'smart_failover'])->save();
        $newRunId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        (new ProcessGeoFlowTaskJob((int) $newRunId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );
        $newRun = TaskRun::query()->findOrFail((int) $newRunId);
        $this->assertSame((int) $newModel->id, (int) $newRun->requested_ai_model_id);
        $this->assertSame((int) $newModel->id, (int) $newRun->resolved_ai_model_id);
        $this->assertSame(['snapshot-old-model', 'snapshot-new-model'], $requestedModels);
    }

    public function test_queue_failure_and_completion_metadata_never_persist_provider_secrets_or_urls(): void
    {
        Queue::fake();
        $admin = $this->admin('sanitized-error-runner');
        $model = $this->model($admin, 'sanitized-error-model');
        $task = $this->executableTask($admin, $model, 'Sanitized error task');
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $sensitive = 'provider https://api.example.test/v1/chat?token=query-secret Authorization: Bearer bearer-secret api_key=plain-key secret=plain-secret password=plain-pass credential=plain-credential {"api_key":"json-key","key":"json-bare-key","base_url":"https://json.example.test?token=json-url-secret","note":"json-note-secret","nested":{"password":"json-pass"}}';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $runId, 'failure-worker-'.$attempt));
            $context = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail((int) $runId));
            app(JobQueueService::class)->failJob(
                (int) $runId,
                (int) $task->id,
                $sensitive,
                10,
                1,
                executionContext: $context,
            );
            if ($attempt < 3) {
                TaskRun::query()->whereKey($runId)->update([
                    'meta->available_at' => now()->subSecond()->toDateTimeString(),
                ]);
            }
        }

        $failedRun = TaskRun::query()->findOrFail((int) $runId);
        $failedState = json_encode([
            'error' => $failedRun->error_message,
            'meta' => $failedRun->meta,
            'task_error' => $task->fresh()->last_error_message,
        ], JSON_THROW_ON_ERROR);
        foreach (['api.example.test', 'query-secret', 'bearer-secret', 'plain-key', 'plain-secret', 'plain-pass', 'plain-credential', 'json-key', 'json-bare-key', 'json.example.test', 'json-url-secret', 'json-note-secret', 'json-pass'] as $secret) {
            $this->assertStringNotContainsString($secret, $failedState);
        }
        $this->assertSame('failed', $failedRun->status);
        $this->assertLessThanOrEqual(500, mb_strlen((string) $failedRun->error_message, 'UTF-8'));

        $completedTask = $this->executableTask($admin, $model, 'Sanitized completion task');
        $completedRunId = app(JobQueueService::class)->enqueueTaskJob((int) $completedTask->id);
        $this->assertIsArray(app(JobQueueService::class)->claimPendingJobById((int) $completedRunId, 'completion-worker'));
        $completedContext = app(AiExecutionContextFactory::class)->fromTaskRun(TaskRun::query()->findOrFail((int) $completedRunId));
        app(JobQueueService::class)->completeJob(
            (int) $completedRunId,
            (int) $completedTask->id,
            null,
            10,
            [
                'model_attempts' => [['status' => 'failed', 'reason' => $sensitive]],
                'key' => 'completed-key-secret',
                'base_url' => 'https://completed.example.test?token=completed-url-secret',
                'note' => 'completed-note-secret',
                'nested' => ['accessToken' => 'completed-nested-secret'],
            ],
            $completedContext,
        );
        $completedMeta = json_encode(TaskRun::query()->findOrFail((int) $completedRunId)->meta, JSON_THROW_ON_ERROR);
        foreach (['api.example.test', 'query-secret', 'bearer-secret', 'plain-key', 'plain-secret', 'plain-pass', 'plain-credential', 'json-key', 'json-bare-key', 'json.example.test', 'json-url-secret', 'json-note-secret', 'json-pass', 'completed-key-secret', 'completed.example.test', 'completed-url-secret', 'completed-note-secret', 'completed-nested-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $completedMeta);
        }
    }

    public function test_admin_deletion_dependencies_include_persisted_tasks_and_run_history(): void
    {
        $admin = $this->admin('deletion-dependent-admin');
        $task = Task::query()->create([
            'name' => 'Deletion dependency task',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'completed',
            'finished_at' => now(),
        ]);
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 1,
            'resolver_policy_version' => 1,
        ])->save();

        $dependencies = app(AdminAiDependencyInspector::class)->deletionDependencies($admin);

        $this->assertTrue($dependencies->blocksDeletion());
        $this->assertSame(1, $dependencies->executionTaskCount);
        $this->assertSame(1, $dependencies->executionTaskRunCount);
        $this->assertSame(2, $dependencies->counts()['pending_task_count']);
    }

    public function test_due_draft_publication_does_not_require_the_requested_ai_model(): void
    {
        Queue::fake();
        $provider = $this->admin('publish-provider', ['role' => 'super_admin']);
        $admin = $this->admin('draft-publisher', [
            'shared_ai_config_owner_id' => $provider->id,
            'ai_config_access_version' => 4,
        ]);
        $sharedModel = $this->model($provider, 'unused-publish-model');
        [$task] = $this->generationTask($admin, $sharedModel, 'Due draft publication');
        $task->forceFill([
            'next_publish_at' => now()->subMinute(),
            'publish_scope' => 'local_only',
        ])->save();
        $author = Author::query()->create(['name' => 'Draft publication author']);
        $category = Category::query()->where('slug', 'execution-identity-category')->firstOrFail();
        $article = Article::query()->create([
            'title' => 'Already generated draft',
            'slug' => 'already-generated-draft',
            'content' => 'Existing draft content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
        ]);
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        $provider->forceFill(['status' => 'inactive'])->save();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::preventStrayRequests();

        (new ProcessGeoFlowTaskJob((int) $runId))->handle(
            app(JobQueueService::class),
            app(WorkerExecutionService::class),
        );

        $this->assertSame('completed', TaskRun::query()->findOrFail((int) $runId)->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame(1, (int) $task->fresh()->published_count);
    }

    public function test_task_updates_cannot_change_the_persisted_execution_identity(): void
    {
        $admin = $this->admin('immutable-task-owner');
        $model = $this->model($admin, 'immutable-task-model');
        $task = $this->executableTask($admin, $model, 'Immutable identity task');
        $library = TitleLibrary::query()->create(['name' => 'Immutable identity titles']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Available immutable title',
            'keyword' => 'immutable',
        ]);
        $task->forceFill([
            'title_library_id' => $library->id,
            'article_limit' => 1,
            'created_count' => 0,
            'is_loop' => 0,
        ])->save();

        app(TaskLifecycleService::class)->updateTask((int) $task->id, [
            'name' => 'Updated task name',
            'model_access_admin_id' => 999999,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 999999,
        ]);

        $fresh = $task->fresh();
        $this->assertSame('Updated task name', $fresh->name);
        $this->assertSame((int) $admin->id, (int) $fresh->model_access_admin_id);
        $this->assertSame('admin', $fresh->model_access_admin_role);
        $this->assertSame(1, (int) $fresh->model_access_policy_version);
    }

    private function admin(string $username, array $attributes = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $admin->forceFill($attributes)->save();

        return $admin->refresh();
    }

    private function model(Admin $owner, string $modelId): AiModel
    {
        $model = new AiModel([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('never-log-this-key'),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();

        return $model;
    }

    private function executableTask(Admin $admin, AiModel $model, string $name): Task
    {
        $task = Task::query()->create([
            'name' => $name,
            'ai_model_id' => $model->id,
            'status' => 'active',
            'schedule_enabled' => 1,
            'max_retry_count' => 3,
        ]);
        $task->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => $admin->isSuperAdmin() ? 'super_admin' : 'admin',
            'model_access_policy_version' => 1,
        ])->save();

        return $task;
    }

    /** @return array{Task, Title} */
    private function generationTask(Admin $admin, AiModel $model, string $name): array
    {
        Category::query()->firstOrCreate([
            'slug' => 'execution-identity-category',
        ], [
            'name' => 'Execution identity category',
        ]);
        $library = TitleLibrary::query()->create(['name' => $name.' titles']);
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => $name.' title',
            'keyword' => 'identity',
        ]);
        $task = $this->executableTask($admin, $model, $name);
        $task->forceFill([
            'title_library_id' => $library->id,
            'draft_limit' => 10,
            'article_limit' => 10,
            'created_count' => 0,
            'loop_count' => 0,
        ])->save();

        return [$task, $title];
    }
}
