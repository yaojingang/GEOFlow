<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiDependencyInspector;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id, payload: [
            'source' => 'test',
            'model_access_admin_id' => 999999,
            'ai_config_access_version' => 999999,
            'api_key' => 'never-log-this-key',
            'providerApiKey' => 'camel-case-api-key',
            'access_token' => 'access-token-value',
            'bearer' => 'bearer-value',
            'clientSecret' => 'client-secret-value',
            'nested' => [
                'endpoint' => 'https://private.example.test',
                'prompt' => 'private prompt',
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
        $this->assertStringNotContainsString('private.example.test', $serializedMeta);
        $this->assertStringNotContainsString('private prompt', $serializedMeta);
        $this->assertNull(data_get($run->meta, 'payload.model_access_admin_id'));

        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run);
        $this->assertSame('task-run:'.$run->id, $context->requestId);
        $this->assertSame((int) $admin->id, $context->modelAccessAdminId);
        $this->assertStringNotContainsString('never-log-this-key', json_encode($context->toSafeArray(), JSON_THROW_ON_ERROR));
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
        ])->save();
        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);
        Http::fake(function () use ($admin) {
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
        $this->assertSame('failed', TaskRun::query()->findOrFail((int) $runId)->status);
        $this->assertSame('ai_config_access_revoked', TaskRun::query()->findOrFail((int) $runId)->error_code);
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
