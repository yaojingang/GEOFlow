<?php

namespace Tests\Feature;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\TaskTitleReadinessException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\AiExecutionContextFactory;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskTitleReadinessService;
use App\Services\GeoFlow\WorkerExecutionService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TaskTitleReadinessQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_raises_structured_title_readiness_exception_when_titles_are_exhausted(): void
    {
        $library = TitleLibrary::query()->create(['name' => 'Worker 耗尽库']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'Worker 已用标题',
            'keyword' => 'Worker',
            'used_count' => 2,
        ]);
        $task = Task::query()->create([
            'name' => 'Worker 耗尽任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        [$admin, $model] = $this->bindAiIdentity($task, 'worker-title-readiness');
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $this->persistRunIdentity($run, $admin, $model, (string) Str::uuid());
        $context = app(AiExecutionContextFactory::class)->fromTaskRun($run->fresh());

        try {
            app(WorkerExecutionService::class)->executeTask((int) $task->id, $context);
            $this->fail('The worker should raise a structured title readiness exception.');
        } catch (TaskTitleReadinessException $exception) {
            $this->assertSame('task_title_library_not_ready', $exception->getErrorCode());
            $this->assertSame('title_library_exhausted', $exception->getDetails()['title_readiness']['issues'][0]['code']);
        }
    }

    public function test_title_exhaustion_fails_once_pauses_task_and_cancels_other_pending_runs(): void
    {
        Queue::fake();
        $library = TitleLibrary::query()->create(['name' => '运行期耗尽库']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => '已经使用的标题',
            'keyword' => '耗尽',
            'used_count' => 1,
        ]);
        $task = Task::query()->create([
            'name' => '运行期兜底任务',
            'title_library_id' => $library->id,
            'article_limit' => 3,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => now()->addMinute(),
            'max_retry_count' => 3,
        ]);
        [$admin, $model] = $this->bindAiIdentity($task, 'queue-title-readiness');
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => [
                'attempt_count' => 0,
                'max_attempts' => 3,
                'available_at' => now()->toDateTimeString(),
            ],
        ]);
        $otherPending = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'pending',
            'meta' => ['attempt_count' => 0, 'max_attempts' => 3],
        ]);
        $this->persistRunIdentity($run, $admin, $model);
        $this->persistRunIdentity($otherPending, $admin, $model);
        $report = app(TaskTitleReadinessService::class)->inspect(
            (int) $library->id,
            3,
            false,
            'active',
            (int) $task->id,
        );
        $worker = Mockery::mock(WorkerExecutionService::class);
        $worker->shouldReceive('executeTask')
            ->once()
            ->withArgs(fn (int $taskId, AiExecutionContext $context): bool => $taskId === (int) $task->id
                && $context->modelAccessAdminId === (int) $admin->id)
            ->andThrow(new TaskTitleReadinessException($report, 409));

        (new ProcessGeoFlowTaskJob((int) $run->id))->handle(
            app(JobQueueService::class),
            $worker,
        );

        $failedRun = $run->fresh();
        $this->assertSame('failed', $failedRun->status);
        $this->assertNotNull($failedRun->finished_at);
        $this->assertSame(1, $failedRun->meta['attempt_count']);
        $this->assertFalse($failedRun->meta['retryable']);
        $this->assertSame('task_title_library_not_ready', $failedRun->meta['error_code']);
        $this->assertSame('cancelled', $otherPending->fresh()->status);
        $this->assertSame('paused', $task->fresh()->status);
        $this->assertSame(0, (int) $task->fresh()->schedule_enabled);
        $this->assertNull($task->fresh()->next_run_at);
        Queue::assertNothingPushed();
    }

    public function test_low_level_enqueue_rechecks_that_the_locked_task_is_active(): void
    {
        Queue::fake();
        $task = Task::query()->create([
            'name' => '已暂停任务',
            'status' => 'paused',
            'schedule_enabled' => 0,
        ]);

        $runId = app(JobQueueService::class)->enqueueTaskJob((int) $task->id);

        $this->assertNull($runId);
        $this->assertDatabaseMissing('task_runs', ['task_id' => $task->id]);
        Queue::assertNothingPushed();
    }

    public function test_failed_callback_keeps_a_title_configuration_error_out_of_business_retries(): void
    {
        Queue::fake();
        $library = TitleLibrary::query()->create(['name' => '失败回调标题库']);
        $task = Task::query()->create([
            'name' => '失败回调任务',
            'title_library_id' => $library->id,
            'article_limit' => 2,
            'created_count' => 0,
            'is_loop' => 0,
            'status' => 'active',
            'schedule_enabled' => 1,
        ]);
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => 'running',
            'meta' => ['attempt_count' => 0, 'max_attempts' => 3],
        ]);
        $report = app(TaskTitleReadinessService::class)->inspectTask($task);

        (new ProcessGeoFlowTaskJob((int) $run->id))->failed(
            new TaskTitleReadinessException($report, 409),
        );

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->meta['attempt_count']);
        $this->assertFalse($run->fresh()->meta['retryable']);
        $this->assertSame('paused', $task->fresh()->status);
        Queue::assertNothingPushed();
    }

    /** @return array{Admin,AiModel} */
    private function bindAiIdentity(Task $task, string $username): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'safe-password',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => $username.' model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => $username.'-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $task->forceFill([
            'ai_model_id' => $model->id,
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ])->save();

        return [$admin, $model];
    }

    private function persistRunIdentity(
        TaskRun $run,
        Admin $admin,
        AiModel $model,
        ?string $executionLeaseToken = null,
    ): void {
        $run->forceFill([
            'model_access_admin_id' => $admin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => (int) $admin->ai_config_access_version,
            'requested_ai_model_id' => $model->id,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            'execution_lease_token' => $executionLeaseToken,
        ])->save();
    }
}
