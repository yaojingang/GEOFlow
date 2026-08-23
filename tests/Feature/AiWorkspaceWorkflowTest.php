<?php

namespace Tests\Feature;

use App\Ai\Agents\GeoHubPlanDrafterAgent;
use App\Ai\Agents\IntentResolverAgent;
use App\Ai\Workspace\AiPlanCompiler;
use App\Exceptions\ApiException;
use App\Jobs\ProcessAiWorkspaceRunJob;
use App\Jobs\ProcessArticleDistributionJob;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiWorkspaceApproval;
use App\Models\AiWorkspaceArtifact;
use App\Models\AiWorkspaceExternalOperation;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\AiWorkspace\AiCapabilityExecutor;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use App\Services\AiWorkspace\AiWorkspaceDispatchGuard;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Services\AiWorkspace\AiWorkspaceSnapshot;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Models\ConversationMessage;
use RuntimeException;
use Tests\TestCase;

final class AiWorkspaceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-workspace.runtime_enabled', true);
    }

    public function test_read_plan_executes_without_approval_and_emits_an_artifact(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $run = $this->planningRun($admin, 'analytics.daily_report');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'analytics.daily_report', [[
            'capability' => 'analytics.daily_report',
            'parameters' => ['date' => now()->toDateString()],
        ]]);

        $queued = app(AiWorkflowEngine::class)->prepare($run, $plan);
        self::assertSame('queued', $queued->state);
        self::assertDatabaseCount('ai_workspace_approvals', 0);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class);

        app(AiWorkflowEngine::class)->process((string) $queued->id);
        $completed = $queued->fresh();
        self::assertSame('completed', $completed->state);
        self::assertTrue($completed->system_operations_executed);
        self::assertGreaterThan(1, $completed->event_sequence);
        self::assertDatabaseHas('ai_workspace_artifacts', ['run_id' => $run->id, 'type' => 'operational_report']);
    }

    public function test_plan_compiler_rejects_empty_fanout_and_remaps_group_dependencies(): void
    {
        $admin = $this->admin();
        $compiler = app(AiPlanCompiler::class);
        try {
            $compiler->compile($admin, 'empty fanout', [[
                'capability' => 'distribution.site_settings_sync',
                'parameters' => ['channel_ids' => []],
            ]]);
            self::fail('Empty fan-out targets must be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $channels = collect([1, 2])->map(fn (int $index): DistributionChannel => DistributionChannel::query()->create([
            'name' => '分发站点 '.$index,
            'domain' => 'fanout-'.$index.'.example.com',
            'endpoint_url' => 'https://fanout-'.$index.'.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]));
        $plan = $compiler->compile($admin, 'fanout dependencies', [
            [
                'capability' => 'distribution.site_settings_sync',
                'parameters' => ['channel_ids' => $channels->pluck('id')->all()],
            ],
            [
                'capability' => 'analytics.daily_report',
                'parameters' => [],
                'depends_on' => [1],
            ],
        ]);

        self::assertCount(3, $plan->steps);
        self::assertSame([1, 2], $plan->steps[2]['depends_on']);
    }

    public function test_approved_internal_draft_is_atomic_and_idempotent(): void
    {
        Queue::fake();
        $dependencies = $this->draftTaskDependencies();
        $admin = $this->admin();
        $run = $this->planningRun($admin, 'task.draft');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'task.draft', [[
            'capability' => 'task.draft',
            'parameters' => ['name' => 'AI 草稿任务', 'article_limit' => 5],
        ]]);
        $awaiting = app(AiWorkflowEngine::class)->prepare($run, $plan);
        self::assertSame('awaiting_approval', $awaiting->state);
        $approval = $awaiting->approvals()->firstOrFail();

        $queued = app(AiWorkflowEngine::class)->approve($admin, $approval);
        app(AiWorkflowEngine::class)->process((string) $queued->id);
        app(AiWorkflowEngine::class)->process((string) $queued->id);

        self::assertSame('completed', $queued->fresh()->state);
        self::assertSame(1, Task::query()->where('name', 'AI 草稿任务')->count());
        self::assertDatabaseHas('tasks', [
            'name' => 'AI 草稿任务',
            'title_library_id' => $dependencies['title_library_id'],
            'prompt_id' => $dependencies['prompt_id'],
            'ai_model_id' => $dependencies['ai_model_id'],
        ]);
        self::assertSame(1, AiWorkspaceArtifact::query()->where('run_id', $queued->id)->where('type', 'task_draft')->count());
    }

    public function test_task_draft_reports_missing_legacy_dependencies_before_writing(): void
    {
        try {
            app(TaskLifecycleService::class)->createDraftTask(['name' => '缺少配置的草稿']);
            self::fail('Missing task dependencies must stop draft creation.');
        } catch (ApiException $exception) {
            self::assertSame('configuration_required', $exception->getErrorCode());
            self::assertSame(422, $exception->getHttpStatus());
            self::assertContains('标题库', $exception->getDetails()['missing']);
            self::assertContains('已启用的对话模型', $exception->getDetails()['missing']);
        }

        self::assertDatabaseMissing('tasks', ['name' => '缺少配置的草稿']);
    }

    public function test_approval_expires_and_cannot_be_replayed_or_used_after_tampering(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);

        $expiredRun = $this->preparedTaskDraft($admin, '过期审批');
        $expired = $expiredRun->approvals()->firstOrFail();
        $expired->forceFill(['expires_at' => now()->subSecond()])->save();
        try {
            $engine->approve($admin, $expired);
            self::fail('Expired approval should be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('失效', $exception->getMessage());
        }

        $tamperedRun = $this->preparedTaskDraft($admin, '防篡改审批');
        $tampered = $tamperedRun->approvals()->firstOrFail();
        $tamperedRun->forceFill(['parameter_digest' => str_repeat('f', 64)])->save();
        $this->expectException(RuntimeException::class);
        $engine->approve($admin, $tampered);
    }

    public function test_an_approval_cannot_be_replayed_after_it_is_accepted(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $run = $this->preparedTaskDraft($admin, '重放保护');
        $approval = $run->approvals()->firstOrFail();
        $engine->approve($admin, $approval);

        $this->expectException(RuntimeException::class);
        $engine->approve($admin, $approval);
    }

    public function test_plan_edit_changes_version_and_invalidates_prior_approval(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $run = $this->preparedTaskDraft($admin, '初始名称');
        $oldApprovalId = (string) $run->approvals()->firstOrFail()->id;
        $step = $run->steps()->firstOrFail();

        $updated = $engine->editPlan($admin, $run->load('steps'), [
            (string) $step->id => ['name' => '修改后名称', 'article_limit' => 3],
        ], 1);

        self::assertSame(2, $updated->plan_version);
        self::assertNotSame($run->parameter_digest, $updated->parameter_digest);
        $oldApproval = AiWorkspaceApproval::query()->findOrFail($oldApprovalId);
        self::assertSame('expired', $oldApproval->status);
        self::assertNull($oldApproval->step_id);
        self::assertDatabaseHas('ai_workspace_artifacts', ['run_id' => $run->id, 'type' => 'plan_revision']);
        self::assertSame('修改后名称', $updated->steps()->firstOrFail()->parameters['name']);
    }

    public function test_stale_plan_edit_is_rejected_without_changing_the_plan(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $run = $this->preparedTaskDraft($admin, '并发修改');
        $step = $run->steps()->firstOrFail();

        try {
            $engine->editPlan($admin, $run->load('steps'), [
                (string) $step->id => ['name' => '过期修改'],
            ], 99);
            self::fail('Stale plan versions must be rejected.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('版本', $exception->getMessage());
        }

        self::assertSame(1, $run->fresh()->plan_version);
        self::assertSame('并发修改', $step->fresh()->parameters['name']);
    }

    public function test_cancel_is_cooperative_and_unissued_distribution_work_is_safely_requeued(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $queued = $this->makeRun($admin, 'running', 'analytics.daily_report');
        $cancelRequested = $engine->cancel($admin, $queued);
        self::assertSame('cancel_requested', $cancelRequested->state);
        $engine->process((string) $queued->id);
        self::assertSame('cancelled', $queued->fresh()->state);

        $external = $this->makeRun($admin, 'running', 'distribution.publish');
        AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $external->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.0.0', 'state' => 'running',
            'risk_level' => 'high', 'execution_scope' => 'external_write', 'parameters' => ['article_ids' => [1], 'channel_ids' => [1]],
            'target_summary' => ['article_ids' => [1], 'channel_ids' => [1]], 'idempotency_key' => 'external-outcome-test',
            'requires_approval' => true, 'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'test', 'lease_expires_at' => now()->addMinute(),
        ]);
        $engine->markJobFailure((string) $external->id, new RuntimeException('worker connection reset'));
        self::assertSame('queued', $external->fresh()->state);
        self::assertSame('pending', $external->steps()->firstOrFail()->state);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class, fn (ProcessAiWorkspaceRunJob $job): bool => $job->runId === $external->id);
    }

    public function test_cancelling_an_approval_run_expires_pending_approvals(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $awaiting = $this->preparedTaskDraft($admin, '取消待审批');
        $approval = $awaiting->approvals()->firstOrFail();

        self::assertSame('cancelled', $engine->cancel($admin, $awaiting)->state);
        self::assertSame('expired', $approval->fresh()->status);
        self::assertStringContainsString('取消', (string) $approval->fresh()->decision_reason);

        $this->expectException(RuntimeException::class);
        $engine->approve($admin, $approval);
    }

    public function test_internal_failed_step_can_be_requeued_while_external_step_cannot(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);
        $run = $this->makeRun($admin, 'failed', 'analytics.daily_report');
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'failed',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'internal-retry-test', 'requires_approval' => false, 'external_operation' => false,
            'attempts' => 1, 'max_attempts' => 3,
        ]);

        $retried = $engine->retryStep($admin, $step);
        self::assertSame('queued', $retried->state);
        self::assertSame('pending', $step->fresh()->state);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class);
    }

    public function test_retry_is_rejected_while_the_original_run_is_still_running(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $run = $this->makeRun($admin, 'running', 'concurrent retry');
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'failed',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'running-retry-test', 'requires_approval' => false, 'external_operation' => false,
            'attempts' => 1, 'max_attempts' => 3,
        ]);

        $this->expectException(RuntimeException::class);
        app(AiWorkflowEngine::class)->retryStep($admin, $step);
    }

    public function test_retry_revives_transitive_dependents_skipped_by_the_failed_step(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $run = $this->makeRun($admin, 'partially_completed', 'retry dependency branch');
        $attributes = [
            'run_id' => $run->id, 'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'requires_approval' => false, 'external_operation' => false, 'attempts' => 1, 'max_attempts' => 3,
        ];
        $failed = AiWorkspaceStep::query()->create($attributes + [
            'id' => (string) Str::uuid7(), 'position' => 1, 'state' => 'failed',
            'idempotency_key' => 'retry-root', 'depends_on' => [], 'error_message' => 'root failed', 'finished_at' => now(),
        ]);
        $child = AiWorkspaceStep::query()->create($attributes + [
            'id' => (string) Str::uuid7(), 'position' => 2, 'state' => 'skipped',
            'idempotency_key' => 'retry-child', 'depends_on' => [1], 'error_message' => 'blocked', 'finished_at' => now(),
        ]);
        $grandchild = AiWorkspaceStep::query()->create($attributes + [
            'id' => (string) Str::uuid7(), 'position' => 3, 'state' => 'skipped',
            'idempotency_key' => 'retry-grandchild', 'depends_on' => [2], 'error_message' => 'blocked', 'finished_at' => now(),
        ]);
        $independent = AiWorkspaceStep::query()->create($attributes + [
            'id' => (string) Str::uuid7(), 'position' => 4, 'state' => 'skipped',
            'idempotency_key' => 'retry-independent', 'depends_on' => [], 'error_message' => 'other failure', 'finished_at' => now(),
        ]);

        app(AiWorkflowEngine::class)->retryStep($admin, $failed);

        self::assertSame('pending', $failed->fresh()->state);
        self::assertSame('pending', $child->fresh()->state);
        self::assertSame('pending', $grandchild->fresh()->state);
        self::assertSame('skipped', $independent->fresh()->state);
        self::assertNull($grandchild->fresh()->finished_at);
    }

    public function test_multi_step_workflow_records_partial_completion(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create([
            'name' => '待启用任务',
            'status' => 'paused',
            'publish_scope' => 'local_only',
            'distribution_strategy' => 'broadcast',
            'schedule_enabled' => false,
        ]);
        $run = $this->planningRun($admin, 'diagnose_then_change_task');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'diagnose_then_change_task', [
            ['capability' => 'analytics.daily_report', 'parameters' => []],
            ['capability' => 'task.status.change', 'parameters' => ['task_id' => $task->id, 'action' => 'start']],
        ]);
        $engine = app(AiWorkflowEngine::class);
        $awaiting = $engine->prepare($run, $plan);
        $queued = $engine->approve($admin, $awaiting->approvals()->firstOrFail());
        $task->delete();

        $engine->process((string) $queued->id);

        self::assertSame('partially_completed', $queued->fresh()->state);
        self::assertSame(['completed', 'failed'], $queued->steps()->orderBy('position')->pluck('state')->all());
        self::assertSame(1, $queued->artifacts()->count());
    }

    public function test_independent_dag_branch_continues_after_another_branch_fails(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $run = $this->planningRun($admin, 'independent branches');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'independent branches', [
            ['capability' => 'analytics.daily_report', 'parameters' => [], 'depends_on' => []],
            ['capability' => 'content.opportunities', 'parameters' => [], 'depends_on' => []],
        ]);
        $engine = app(AiWorkflowEngine::class);
        $queued = $engine->prepare($run, $plan);
        $queued->steps()->where('position', 1)->update(['capability_version' => '0.0.0']);

        $engine->process((string) $queued->id);

        self::assertSame('partially_completed', $queued->fresh()->state);
        self::assertSame(['failed', 'completed'], $queued->steps()->orderBy('position')->pluck('state')->all());
    }

    public function test_artifact_output_binding_pauses_for_downstream_approval_then_resumes(): void
    {
        Queue::fake();
        $this->draftTaskDependencies();
        $admin = $this->admin();
        $run = $this->planningRun($admin, 'draft then start');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'draft then start', [
            ['capability' => 'task.draft', 'parameters' => ['name' => '绑定任务']],
            [
                'capability' => 'task.status.change',
                'parameters' => ['action' => 'start'],
                'depends_on' => [1],
                'input_bindings' => ['task_id' => ['step' => 1, 'path' => 'task_id']],
            ],
        ]);
        $engine = app(AiWorkflowEngine::class);
        $awaiting = $engine->prepare($run, $plan);
        self::assertSame(1, $awaiting->approvals()->where('status', 'pending')->count());

        $queued = $engine->approve($admin, $awaiting->approvals()->where('status', 'pending')->firstOrFail());
        $engine->process((string) $queued->id);

        $paused = $queued->fresh();
        self::assertSame('awaiting_step_approval', $paused->state);
        $taskId = (int) $paused->steps()->where('position', 2)->firstOrFail()->parameters['task_id'];
        self::assertGreaterThan(0, $taskId);
        self::assertSame('paused', Task::query()->findOrFail($taskId)->status);

        $resumed = $engine->approve($admin, $paused->approvals()->where('status', 'pending')->firstOrFail());
        $engine->process((string) $resumed->id);

        self::assertSame('completed', $resumed->fresh()->state);
        self::assertSame('active', Task::query()->findOrFail($taskId)->status);
    }

    public function test_binding_digest_change_renews_all_remaining_grouped_approvals(): void
    {
        Queue::fake();
        $this->draftTaskDependencies();
        $admin = $this->admin();
        $run = $this->planningRun($admin, 'draft start and knowledge');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'draft start and knowledge', [
            ['capability' => 'task.draft', 'parameters' => ['name' => '续批任务']],
            [
                'capability' => 'task.status.change',
                'parameters' => ['action' => 'start'],
                'depends_on' => [1],
                'input_bindings' => ['task_id' => ['step' => 1, 'path' => 'task_id']],
            ],
            [
                'capability' => 'knowledge.draft',
                'parameters' => ['name' => '续批知识', 'content' => '知识草稿内容'],
                'depends_on' => [2],
            ],
        ]);
        $engine = app(AiWorkflowEngine::class);
        $awaiting = $engine->prepare($run, $plan);
        self::assertSame(2, $awaiting->approvals()->where('status', 'pending')->count());
        foreach ($awaiting->approvals()->where('status', 'pending')->get() as $approval) {
            $awaiting = $engine->approve($admin, $approval);
        }

        $engine->process((string) $awaiting->id);
        $paused = $awaiting->fresh();
        self::assertSame('awaiting_step_approval', $paused->state);
        self::assertSame(
            ['knowledge.draft', 'task.status.change'],
            $paused->approvals()->where('status', 'pending')->orderBy('capability_key')->pluck('capability_key')->all(),
        );

        foreach ($paused->approvals()->where('status', 'pending')->get() as $approval) {
            $paused = $engine->approve($admin, $approval);
        }
        $engine->process((string) $paused->id);
        self::assertSame('completed', $paused->fresh()->state);
    }

    public function test_stale_worker_tokens_cannot_overwrite_recovered_runs(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $resolution = $this->makeRun($admin, 'received', 'stale resolution');
        $resolution->forceFill([
            'resolution_lease_owner' => 'new-resolution-worker',
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        app(AiWorkspaceCoordinator::class)->markJobFailure(
            (string) $resolution->id,
            new RuntimeException('old resolver failed'),
            'old-resolution-worker',
        );
        self::assertSame('received', $resolution->fresh()->state);

        $execution = $this->makeRun($admin, 'running', 'stale execution');
        AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $execution->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'running',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'stale-execution-worker', 'requires_approval' => false, 'external_operation' => false,
            'attempts' => 1, 'max_attempts' => 3, 'lease_owner' => 'new-execution-worker',
            'lease_expires_at' => now()->addMinute(),
        ]);
        app(AiWorkflowEngine::class)->markJobFailure(
            (string) $execution->id,
            new RuntimeException('old executor failed'),
            'old-execution-worker',
        );

        self::assertSame('running', $execution->fresh()->state);
        self::assertSame('running', $execution->steps()->firstOrFail()->state);
    }

    public function test_execution_stops_when_the_admin_authorization_version_changes(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $run = $this->makeRun($admin, 'queued', 'authorization revision');
        $run->forceFill(['admin_auth_version' => $admin->auth_version])->save();
        AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'pending',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'authorization-revision', 'requires_approval' => false,
            'external_operation' => false, 'attempts' => 0, 'max_attempts' => 3,
        ]);
        $admin->forceFill(['status' => 'disabled', 'auth_version' => $admin->auth_version + 1])->save();

        app(AiWorkflowEngine::class)->process((string) $run->id);

        self::assertSame('failed', $run->fresh()->state);
        self::assertSame('authorization_revoked', $run->fresh()->failure_code);
        self::assertDatabaseCount('ai_workspace_artifacts', 0);
    }

    public function test_queued_distribution_is_blocked_after_ai_admin_authorization_is_revoked(): void
    {
        $admin = $this->admin();
        $task = Task::query()->create([
            'name' => '分发授权', 'status' => 'active', 'publish_scope' => 'both',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $category = Category::query()->create(['name' => '分发分类', 'slug' => 'distribution-guard']);
        $author = Author::query()->create(['name' => '分发作者']);
        $article = Article::query()->create([
            'task_id' => $task->id, 'title' => '分发授权文章', 'slug' => 'ai-authorization',
            'category_id' => $category->id, 'author_id' => $author->id,
            'content' => '内容', 'status' => 'published', 'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '分发授权站点', 'domain' => 'authorization.example.com',
            'endpoint_url' => 'https://authorization.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT, 'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'completed', 'distribution authorization');
        $run->forceFill([
            'admin_auth_version' => $admin->auth_version,
            'plan_digest' => str_repeat('a', 64),
            'parameter_digest' => str_repeat('b', 64),
            'target_digest' => str_repeat('c', 64),
        ])->save();
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.3.0', 'state' => 'completed',
            'risk_level' => 'high', 'execution_scope' => 'external_write', 'parameters' => [],
            'idempotency_key' => 'queued-distribution-guard', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id, 'distribution_channel_id' => $channel->id,
            'action' => 'publish', 'status' => 'queued', 'payload_hash' => str_repeat('d', 64),
            'idempotency_key' => 'distribution-authorization-guard',
            'remote_meta' => [
                'ai_workspace_guard' => [
                    'run_id' => $run->id, 'step_id' => $step->id, 'admin_id' => $admin->id,
                    'admin_auth_version' => $admin->auth_version, 'plan_digest' => $run->plan_digest,
                    'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
                    'capability_version' => '1.3.0',
                ],
                'ai_workspace_payload' => ['article' => ['id' => $article->id]],
            ],
        ]);
        $admin->forceFill(['status' => 'disabled', 'auth_version' => $admin->auth_version + 1])->save();

        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);

        self::assertSame('failed', $distribution->fresh()->status);
        self::assertNull(data_get($distribution->fresh()->remote_meta, 'ai_workspace_payload'));
        self::assertSame('denied', data_get($distribution->fresh()->remote_meta, 'ai_workspace_guard_status'));

        $distribution->forceFill(['status' => 'sending'])->save();
        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);
        self::assertSame('sending', $distribution->fresh()->status);
        $distribution->forceFill(['status' => 'synced'])->save();
        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);
        self::assertSame('synced', $distribution->fresh()->status);
    }

    public function test_queued_distribution_is_blocked_after_approval_expires(): void
    {
        $admin = $this->admin();
        $task = Task::query()->create([
            'name' => '分发过期审批', 'status' => 'active', 'publish_scope' => 'both',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $category = Category::query()->create(['name' => '过期审批分类', 'slug' => 'expired-approval']);
        $author = Author::query()->create(['name' => '过期审批作者']);
        $article = Article::query()->create([
            'task_id' => $task->id, 'title' => '过期审批文章', 'slug' => 'expired-ai-approval',
            'category_id' => $category->id, 'author_id' => $author->id,
            'content' => '内容', 'status' => 'published', 'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '过期审批站点', 'domain' => 'expired-approval.example.com',
            'endpoint_url' => 'https://expired-approval.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT, 'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'completed', 'expired distribution approval');
        $run->forceFill([
            'admin_auth_version' => $admin->auth_version,
            'plan_version' => 1,
            'plan_digest' => str_repeat('a', 64),
            'parameter_digest' => str_repeat('b', 64),
            'target_digest' => str_repeat('c', 64),
            'capability_versions' => ['distribution.publish' => '1.3.0'],
        ])->save();
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.3.0', 'state' => 'completed',
            'risk_level' => 'high', 'execution_scope' => 'external_write', 'parameters' => [],
            'idempotency_key' => 'expired-distribution-approval', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
        ]);
        AiWorkspaceApproval::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'capability_key' => 'distribution.publish',
            'admin_id' => $admin->id, 'admin_username_snapshot' => $admin->username,
            'status' => 'approved', 'plan_version' => 1,
            'capability_versions' => $run->capability_versions,
            'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
            'expires_at' => now()->subSecond(), 'decided_at' => now()->subMinute(),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id, 'distribution_channel_id' => $channel->id,
            'action' => 'publish', 'status' => 'queued', 'payload_hash' => str_repeat('d', 64),
            'idempotency_key' => 'expired-distribution-approval',
            'remote_meta' => [
                'ai_workspace_guard' => [
                    'run_id' => $run->id, 'step_id' => $step->id, 'admin_id' => $admin->id,
                    'admin_auth_version' => $admin->auth_version, 'plan_digest' => $run->plan_digest,
                    'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
                    'capability_version' => '1.3.0',
                ],
                'ai_workspace_payload' => ['article' => ['id' => $article->id]],
            ],
        ]);

        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);

        self::assertSame('failed', $distribution->fresh()->status);
        self::assertSame('denied', data_get($distribution->fresh()->remote_meta, 'ai_workspace_guard_status'));
    }

    public function test_distribution_rechecks_approval_and_stops_unknown_wordpress_retries(): void
    {
        Queue::fake();
        Http::fake();
        $admin = $this->admin();
        $task = Task::query()->create([
            'name' => '分发最终校验', 'status' => 'active', 'publish_scope' => 'both',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $category = Category::query()->create(['name' => '最终校验分类', 'slug' => 'final-dispatch-guard']);
        $author = Author::query()->create(['name' => '最终校验作者']);
        $article = Article::query()->create([
            'task_id' => $task->id, 'title' => '最终校验文章', 'slug' => 'final-dispatch-guard',
            'category_id' => $category->id, 'author_id' => $author->id,
            'content' => '内容', 'status' => 'published', 'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '最终校验站点', 'domain' => 'final-dispatch.example.com',
            'endpoint_url' => 'https://final-dispatch.example.com/wp-json',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'publisher',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'keep_original',
            ],
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp-final-dispatch',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('application-password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
        $targetSummary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $run = $this->makeRun($admin, 'completed', 'final distribution authorization');
        $run->forceFill([
            'admin_auth_version' => $admin->auth_version,
            'plan_version' => 1,
            'plan_digest' => str_repeat('a', 64),
            'parameter_digest' => str_repeat('b', 64),
            'target_digest' => str_repeat('c', 64),
            'capability_versions' => ['distribution.publish' => '1.3.0'],
        ])->save();
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.3.0',
            'state' => 'completed', 'risk_level' => 'high', 'execution_scope' => 'external_write',
            'approval_policy' => 'target_matrix', 'parameters' => [], 'target_summary' => $targetSummary,
            'idempotency_key' => 'final-distribution-guard', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
        ]);
        $approval = AiWorkspaceApproval::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id,
            'capability_key' => 'distribution.publish', 'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username, 'status' => 'approved',
            'plan_version' => 1, 'capability_versions' => $run->capability_versions,
            'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
            'expires_at' => now()->addMinutes(30), 'decided_at' => now(),
        ]);
        $payload = ['article' => [
            'id' => (int) $article->id,
            'slug' => (string) $article->slug,
            'title' => (string) $article->title,
            'content_html' => (string) $article->content,
            'excerpt' => '',
        ]];
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id, 'distribution_channel_id' => $channel->id,
            'action' => 'publish', 'status' => 'queued',
            'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
            'idempotency_key' => 'final-distribution-authorization',
            'remote_meta' => [
                'ai_workspace_guard' => [
                    'run_id' => $run->id, 'step_id' => $step->id, 'admin_id' => $admin->id,
                    'admin_auth_version' => $admin->auth_version, 'plan_digest' => $run->plan_digest,
                    'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
                    'capability_version' => '1.3.0',
                    'channel_revision' => data_get($targetSummary, 'channel_snapshots.0.revision'),
                ],
                'ai_workspace_payload' => $payload,
            ],
        ]);

        self::assertTrue(app(AiWorkspaceDispatchGuard::class)->allowsDistribution($distribution));
        $approval->forceFill(['expires_at' => now()->subSecond()])->save();

        try {
            app(DistributionOrchestrator::class)->process($distribution);
            self::fail('Expired approval must be rejected at the final dispatch boundary.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('授权或审批', $exception->getMessage());
        }

        self::assertSame('sending', $distribution->fresh()->status);
        self::assertNull(data_get($distribution->fresh()->remote_meta, 'ai_workspace_guard_status'));
        Http::assertNothingSent();

        $approval->forceFill(['expires_at' => now()->addMinutes(30)])->save();
        $distribution->refresh();
        $distribution->forceFill(['status' => 'queued'])->save();
        self::assertTrue(app(AiWorkspaceDispatchGuard::class)->allowsDistribution($distribution->fresh()));
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_contains($request->url(), '/wp/v2/posts')) {
                throw new ConnectionException('connection timeout after request dispatch');
            }

            return Http::response([], 200);
        });

        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);

        self::assertSame('outcome_unknown', $distribution->fresh()->status);
        self::assertNull($distribution->fresh()->next_retry_at);
        self::assertStringContainsString('人工对账', (string) $distribution->fresh()->last_error_message);
        self::assertDatabaseHas('distribution_logs', [
            'article_distribution_id' => (int) $distribution->id,
            'event' => 'distribution.outcome_unknown',
        ]);
        Queue::assertNotPushed(ProcessArticleDistributionJob::class);

        $distribution->refresh()->forceFill(['status' => 'sending'])->save();
        (new ProcessArticleDistributionJob((int) $distribution->id))->failed(new RuntimeException('worker timeout'));
        self::assertSame('outcome_unknown', $distribution->fresh()->status);
        self::assertGreaterThanOrEqual(2, DistributionLog::query()
            ->where('article_distribution_id', $distribution->id)
            ->where('event', 'distribution.outcome_unknown')
            ->count());
    }

    public function test_unknown_wordpress_outcome_can_be_reconciled_by_slug(): void
    {
        $task = Task::query()->create([
            'name' => 'WordPress 对账', 'status' => 'active', 'publish_scope' => 'both',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $category = Category::query()->create(['name' => 'WordPress 对账分类', 'slug' => 'wordpress-reconcile']);
        $author = Author::query()->create(['name' => 'WordPress 对账作者']);
        $article = Article::query()->create([
            'task_id' => $task->id, 'title' => 'WordPress 对账文章', 'slug' => 'wordpress-reconcile',
            'category_id' => $category->id, 'author_id' => $author->id,
            'content' => '内容', 'status' => 'published', 'review_status' => 'approved',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress 对账站点', 'domain' => 'wordpress-reconcile.example.com',
            'endpoint_url' => 'https://wordpress-reconcile.example.com/wp-json',
            'channel_type' => 'wordpress_rest',
            'channel_config' => ['wordpress_username' => 'publisher'],
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp-reconcile',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('application-password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id, 'distribution_channel_id' => $channel->id,
            'action' => 'publish', 'status' => 'outcome_unknown',
            'idempotency_key' => 'wordpress-reconcile',
            'remote_meta' => ['ai_workspace_payload' => ['article' => ['slug' => $article->slug]]],
        ]);
        Http::fake(fn () => Http::response([[
            'id' => 907,
            'link' => 'https://wordpress-reconcile.example.com/wordpress-reconcile',
            'slug' => (string) $article->slug,
        ]]));

        self::assertTrue(app(DistributionOrchestrator::class)->reconcileUnknownOutcome($distribution));
        self::assertSame('synced', $distribution->fresh()->status);
        self::assertSame('907', $distribution->fresh()->remote_id);
        self::assertTrue((bool) data_get($distribution->fresh()->remote_meta, 'outcome_reconciled'));
        self::assertNull(data_get($distribution->fresh()->remote_meta, 'ai_workspace_payload'));
    }

    public function test_grouped_and_per_step_approval_policies_are_enforced(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $engine = app(AiWorkflowEngine::class);

        $groupedRun = $this->planningRun($admin, 'two drafts');
        $groupedPlan = app(AiPlanCompiler::class)->compile($admin, 'two drafts', [
            ['capability' => 'task.draft', 'parameters' => ['name' => '草稿一']],
            ['capability' => 'task.draft', 'parameters' => ['name' => '草稿二']],
        ]);
        $grouped = $engine->prepare($groupedRun, $groupedPlan);
        self::assertSame(1, $grouped->approvals()->count());
        self::assertNull($grouped->approvals()->firstOrFail()->step_id);

        $firstTask = Task::query()->create([
            'name' => '任务一', 'status' => 'paused', 'publish_scope' => 'local_only',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $secondTask = Task::query()->create([
            'name' => '任务二', 'status' => 'paused', 'publish_scope' => 'local_only',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $perStepRun = $this->planningRun($admin, 'start two tasks');
        $perStepPlan = app(AiPlanCompiler::class)->compile($admin, 'start two tasks', [
            ['capability' => 'task.status.change', 'parameters' => ['task_id' => $firstTask->id, 'action' => 'start']],
            ['capability' => 'task.status.change', 'parameters' => ['task_id' => $secondTask->id, 'action' => 'start']],
        ]);
        $perStep = $engine->prepare($perStepRun, $perStepPlan);
        $firstApproval = $perStep->approvals()->firstOrFail();
        self::assertSame($perStep->steps()->firstOrFail()->id, $firstApproval->step_id);

        $queued = $engine->approve($admin, $firstApproval);
        $engine->process((string) $queued->id);
        $awaitingSecond = $queued->fresh();
        self::assertSame('awaiting_step_approval', $awaitingSecond->state);
        self::assertSame(1, $awaitingSecond->steps()->where('state', 'completed')->count());
        $secondApproval = $awaitingSecond->approvals()->where('status', 'pending')->firstOrFail();
        self::assertSame($awaitingSecond->steps()->where('state', 'pending')->firstOrFail()->id, $secondApproval->step_id);

        $engine->process((string) $engine->approve($admin, $secondApproval)->id);
        self::assertSame('completed', $awaitingSecond->fresh()->state);
    }

    public function test_target_change_after_approval_invalidates_execution(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create([
            'name' => '审批目标', 'status' => 'paused', 'publish_scope' => 'local_only',
            'distribution_strategy' => 'broadcast', 'schedule_enabled' => false,
        ]);
        $run = $this->planningRun($admin, 'task.status.change');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'task.status.change', [[
            'capability' => 'task.status.change',
            'parameters' => ['task_id' => $task->id, 'action' => 'start'],
        ]]);
        $engine = app(AiWorkflowEngine::class);
        $queued = $engine->approve($admin, $engine->prepare($run, $plan)->approvals()->firstOrFail());
        $task->forceFill(['publish_scope' => 'both'])->save();

        $engine->process((string) $queued->id);

        self::assertSame('failed', $queued->fresh()->state);
        self::assertSame('paused', $task->fresh()->status);
        self::assertStringContainsString('重新确认', (string) $queued->fresh()->failure_message);
    }

    public function test_site_settings_ledger_blocks_target_changes_before_dispatch(): void
    {
        Http::fake();
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '不可变设置站点', 'domain' => 'immutable.example.com',
            'endpoint_url' => 'https://immutable.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'site_settings' => ['site_name' => '审批时名称'],
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'running', 'immutable site settings');
        $run->forceFill([
            'admin_auth_version' => $admin->auth_version,
            'plan_version' => 1,
            'plan_digest' => str_repeat('a', 64),
            'parameter_digest' => str_repeat('b', 64),
            'target_digest' => str_repeat('c', 64),
            'capability_versions' => ['distribution.site_settings_sync' => '1.1.0'],
        ])->save();
        $parameters = ['channel_ids' => [$channel->id]];
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.site_settings_sync', 'capability_version' => '1.1.0',
            'state' => 'running', 'risk_level' => 'high', 'execution_scope' => 'external_write',
            'approval_policy' => 'target_matrix',
            'parameters' => $parameters, 'target_summary' => app(AiPlanCompiler::class)->targetSummaryFor('distribution.site_settings_sync', $parameters),
            'idempotency_key' => 'immutable-site-settings', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'immutable-site-settings-worker', 'lease_expires_at' => now()->addMinute(),
        ]);
        $approval = AiWorkspaceApproval::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id,
            'capability_key' => 'distribution.site_settings_sync', 'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username, 'status' => 'approved',
            'plan_version' => 1, 'capability_versions' => $run->capability_versions,
            'parameter_digest' => $run->parameter_digest, 'target_digest' => $run->target_digest,
            'expires_at' => now()->addMinutes(30), 'decided_at' => now(),
        ]);
        $executor = app(AiCapabilityExecutor::class);
        $executor->prepareExternalExecution($step);
        $channel->forceFill(['endpoint_url' => 'https://changed.example.com/api'])->save();

        try {
            $executor->execute('distribution.site_settings_sync', $parameters, $admin, 'immutable-site-settings');
            self::fail('Changed external targets must be rejected before dispatch.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('变化', $exception->getMessage());
        }

        self::assertSame('prepared', AiWorkspaceExternalOperation::query()->firstOrFail()->status);
        self::assertSame('审批时名称', data_get(AiWorkspaceExternalOperation::query()->firstOrFail()->request_payload, 'settings.site_name'));

        $channel->forceFill(['endpoint_url' => 'https://immutable.example.com/api'])->save();
        $approval->forceFill(['expires_at' => now()->subSecond()])->save();
        try {
            $executor->execute('distribution.site_settings_sync', $parameters, $admin, 'immutable-site-settings');
            self::fail('Expired approval must be rejected immediately before site settings dispatch.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('授权或审批', $exception->getMessage());
        }

        self::assertSame('prepared', AiWorkspaceExternalOperation::query()->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_clarification_followups_keep_context_without_attaching_stale_runs(): void
    {
        Queue::fake();
        AiModel::query()->create([
            'name' => '意图规则回退模型', 'model_id' => 'intent-fallback', 'model_type' => 'chat',
            'api_key' => '', 'api_url' => '', 'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
        $admin = $this->admin();
        $conversation = app(AiConversationRepository::class)->create($admin, '追问测试');
        $coordinator = app(AiWorkspaceCoordinator::class);

        $first = $coordinator->createRun($admin, $conversation, '帮我新建任务', 'clarify-1');
        $coordinator->resolveRun((string) $first->id);
        self::assertSame('clarifying', $first->fresh()->state);

        $followup = $coordinator->createRun($admin, $conversation, '名字是“八月增长计划”', 'clarify-2');
        self::assertSame($first->id, $followup->parent_run_id);
        self::assertSame('cancelled', $first->fresh()->state);
        $coordinator->resolveRun((string) $followup->id);
        self::assertSame('awaiting_approval', $followup->fresh()->state);
        self::assertSame('八月增长计划', $followup->steps()->firstOrFail()->parameters['name']);

        $unrelated = $coordinator->createRun($admin, $conversation, '解释一下 GEO', 'clarify-3');
        self::assertNull($unrelated->parent_run_id);
    }

    public function test_queued_resolution_stops_after_runtime_or_admin_authorization_changes(): void
    {
        Queue::fake();
        $coordinator = app(AiWorkspaceCoordinator::class);

        $runtimeAdmin = $this->admin();
        $runtimeRun = $coordinator->createRun($runtimeAdmin, null, '解释一下 GEO', 'runtime-disabled');
        config()->set('ai-workspace.runtime_enabled', false);
        $coordinator->resolveRun((string) $runtimeRun->id);
        self::assertSame('failed', $runtimeRun->fresh()->state);
        self::assertSame('runtime_disabled', $runtimeRun->fresh()->failure_code);

        config()->set('ai-workspace.runtime_enabled', true);
        $modelAdmin = $this->admin();
        $modelRun = $coordinator->createRun($modelAdmin, null, '解释一下 GEO', 'model-unavailable');
        $coordinator->resolveRun((string) $modelRun->id);
        self::assertSame('failed', $modelRun->fresh()->state);
        self::assertSame('model_unavailable', $modelRun->fresh()->failure_code);

        $revokedAdmin = $this->admin();
        $revokedRun = $coordinator->createRun($revokedAdmin, null, '解释一下 GEO', 'authorization-revoked');
        $revokedAdmin->forceFill(['auth_version' => $revokedAdmin->auth_version + 1])->save();
        $coordinator->resolveRun((string) $revokedRun->id);
        self::assertSame('failed', $revokedRun->fresh()->state);
        self::assertSame('authorization_revoked', $revokedRun->fresh()->failure_code);
    }

    public function test_answer_generation_failure_is_reported_as_failed_without_a_fake_answer(): void
    {
        Queue::fake();
        AiModel::query()->create([
            'name' => '失败回答模型',
            'model_id' => 'answer-failure-model',
            'model_type' => 'chat',
            'api_key' => 'invalid-encrypted-key',
            'api_url' => 'https://example.invalid/v1',
            'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
        $admin = $this->admin();
        $coordinator = app(AiWorkspaceCoordinator::class);
        $run = $coordinator->createRun($admin, null, '请解释 GEO 的基本概念', 'answer-failure');

        $coordinator->resolveRun((string) $run->id);

        $failed = $run->fresh();
        self::assertSame('failed', $failed->state);
        self::assertSame('answer_failed', $failed->failure_code);
        self::assertNull($failed->answer);
        self::assertFalse(ConversationMessage::query()
            ->where('conversation_id', $run->conversation_id)
            ->where('role', 'assistant')
            ->exists());
    }

    public function test_model_plan_preserves_each_ordered_operation_instance(): void
    {
        GeoHubPlanDrafterAgent::fake([
            [
                'summary' => '分别启停两个任务',
                'steps' => [
                    [
                        'operation_id' => 'start-task-12',
                        'capability' => 'task.status.change',
                        'parameters' => ['task_id' => 12, 'action' => 'start'],
                    ],
                    [
                        'operation_id' => 'stop-task-13',
                        'capability' => 'task.status.change',
                        'parameters' => ['task_id' => 13, 'action' => 'stop'],
                    ],
                ],
            ],
            [
                'summary' => '遗漏第二个任务',
                'steps' => [[
                    'operation_id' => 'start-task-12',
                    'capability' => 'task.status.change',
                    'parameters' => ['task_id' => 12, 'action' => 'start'],
                ]],
            ],
        ])->preventStrayPrompts();
        AiModel::query()->create([
            'name' => '计划草案模型',
            'model_id' => 'plan-model',
            'model_type' => 'chat',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('plan-secret'),
            'api_url' => 'https://api.openai.com/v1',
            'daily_limit' => 10,
            'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
        $resolution = [
            'candidate_capabilities' => [[
                'key' => 'task.status.change',
                'confidence' => 0.98,
                'reason' => '两个任务状态操作',
            ]],
            'workflow_steps' => [
                [
                    'operation_id' => 'start-task-12',
                    'capability' => 'task.status.change',
                    'parameters' => ['task_id' => 12, 'action' => 'start'],
                ],
                [
                    'operation_id' => 'stop-task-13',
                    'capability' => 'task.status.change',
                    'parameters' => ['task_id' => 13, 'action' => 'stop'],
                ],
            ],
        ];

        $steps = app(AiWorkspaceModelRuntime::class)->draftPlan('启动任务 12，然后停止任务 13', $resolution);

        self::assertSame(['start-task-12', 'stop-task-13'], array_column($steps, 'operation_id'));
        self::assertSame([12, 13], array_column(array_column($steps, 'parameters'), 'task_id'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('没有逐项保留意图操作');
        app(AiWorkspaceModelRuntime::class)->draftPlan('启动任务 12，然后停止任务 13', $resolution);
    }

    public function test_explicit_model_steps_control_governance_instead_of_ranked_candidates(): void
    {
        Queue::fake();
        IntentResolverAgent::fake([[
            'mode' => 'workflow',
            'intent' => 'analytics.daily_report',
            'candidate_capabilities' => [
                [
                    'key' => 'system.capabilities.explain',
                    'confidence' => 0.99,
                    'reason' => '相关能力候选',
                ],
                [
                    'key' => 'analytics.daily_report',
                    'confidence' => 0.96,
                    'reason' => '明确请求操作',
                ],
            ],
            'requested_steps' => [[
                'operation_id' => 'daily-report',
                'capability' => 'analytics.daily_report',
                'parameters' => [],
                'missing_parameters' => [],
            ]],
            'known_parameters' => [],
            'missing_parameters' => [],
            'ambiguities' => [],
            'semantic_confidence' => 0.98,
            'object_confidence' => 1,
            'completeness_confidence' => 1,
        ]])->preventStrayPrompts();
        GeoHubPlanDrafterAgent::fake([[
            'summary' => '生成运营日报',
            'steps' => [[
                'operation_id' => 'daily-report',
                'capability' => 'analytics.daily_report',
                'parameters' => [],
            ]],
        ]])->preventStrayPrompts();
        AiModel::query()->create([
            'name' => '意图治理模型',
            'model_id' => 'governance-model',
            'model_type' => 'chat',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('governance-secret'),
            'api_url' => 'https://api.openai.com/v1',
            'daily_limit' => 10,
            'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
        $admin = $this->admin();
        $coordinator = app(AiWorkspaceCoordinator::class);
        $run = $coordinator->createRun($admin, null, '生成今天的运营日报', 'explicit-step-governance');

        $coordinator->resolveRun((string) $run->id);

        self::assertSame('queued', $run->fresh()->state);
        self::assertSame('analytics.daily_report', $run->steps()->firstOrFail()->capability_key);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class);
    }

    public function test_active_run_cap_is_enforced_per_admin(): void
    {
        Queue::fake();
        config()->set('ai-workspace.max_active_runs_per_admin', 1);
        $admin = $this->admin();
        $conversation = app(AiConversationRepository::class)->create($admin);
        $coordinator = app(AiWorkspaceCoordinator::class);

        $coordinator->createRun($admin, $conversation, '第一个请求', 'active-cap-1');

        $this->expectException(RuntimeException::class);
        $coordinator->createRun($admin, $conversation, '第二个请求', 'active-cap-2');
    }

    public function test_retention_command_removes_payloads_and_keeps_audit_digests(): void
    {
        $admin = $this->admin();
        $unrelatedConversation = app(AiConversationRepository::class)->create($admin, '普通 Laravel AI 会话');
        $unrelatedMessage = app(AiConversationRepository::class)->append($unrelatedConversation, 'user', '不可清理');
        ConversationMessage::query()->whereKey($unrelatedMessage->id)->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);
        $run = $this->makeRun($admin, 'completed', 'general_question');
        $run->forceFill([
            'prompt' => '包含敏感业务载荷',
            'answer' => '完整回答',
            'plan_digest' => str_repeat('a', 64),
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
            'finished_at' => now()->subDays(100),
        ])->save();
        $artifact = AiWorkspaceArtifact::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'type' => 'report', 'name' => '机密报告名',
            'created_by_admin_id' => $admin->id, 'created_by_username_snapshot' => $admin->username,
            'data_classification' => 'confidential', 'content' => '机密内容', 'payload' => ['secret' => 'value'],
            'source_url' => 'https://secret.example/report',
        ]);
        $approval = AiWorkspaceApproval::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'capability_key' => 'task.draft',
            'admin_id' => $admin->id, 'admin_username_snapshot' => $admin->username,
            'status' => 'rejected', 'plan_version' => 1,
            'capability_versions' => ['task.draft' => '1.0.0'], 'parameter_digest' => str_repeat('b', 64),
            'target_digest' => str_repeat('c', 64), 'decision_reason' => '包含敏感业务原因',
            'expires_at' => now()->subDays(99), 'decided_at' => now()->subDays(99),
        ]);
        $retainedStep = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.site_settings_sync', 'capability_version' => '1.0.0',
            'state' => 'completed', 'risk_level' => 'high', 'execution_scope' => 'external_write',
            'parameters' => ['channel_ids' => [1]], 'idempotency_key' => 'retention-step',
            'requires_approval' => true, 'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
        ]);
        $operation = AiWorkspaceExternalOperation::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'step_id' => $retainedStep->id,
            'execution_key' => 'retention-operation', 'capability_key' => 'distribution.site_settings_sync',
            'target_type' => 'distribution_channel', 'target_id' => '1', 'status' => 'confirmed',
            'request_digest' => str_repeat('d', 64), 'target_digest' => str_repeat('e', 64),
            'request_payload' => ['settings' => ['secret' => 'request']],
            'remote_result' => ['secret' => 'response'], 'error_message' => '敏感错误',
        ]);
        $distributionLog = DistributionLog::query()->create([
            'level' => 'info', 'event' => 'site.settings.synced', 'message' => '设置同步完成',
            'context' => [
                'ai_workspace_execution_key' => 'retention-operation',
                'remote_result' => ['secret' => 'remote-response'],
                'refresh_count' => 2,
                'refresh_warning' => '敏感告警',
            ],
            'created_at' => now()->subDays(99),
        ]);
        $active = $this->makeRun($admin, 'running', 'old active payload');
        $active->forceFill(['created_at' => now()->subDays(100), 'updated_at' => now()->subDays(100)])->save();

        Artisan::call('geoflow:prune-ai-workspace', ['--days' => 90]);

        $pruned = $run->fresh();
        self::assertSame('[已按留存策略清理]', $pruned->prompt);
        self::assertNull($pruned->answer);
        self::assertSame(str_repeat('a', 64), $pruned->plan_digest);
        self::assertNotNull($pruned->payload_pruned_at);
        self::assertSame('已清理产物', $artifact->fresh()->name);
        self::assertNull($artifact->fresh()->content);
        self::assertNull($approval->fresh()->decision_reason);
        self::assertSame(str_repeat('b', 64), $approval->fresh()->parameter_digest);
        self::assertNull($operation->fresh()->request_payload);
        self::assertNull($operation->fresh()->remote_result);
        self::assertNull($operation->fresh()->error_message);
        self::assertSame(str_repeat('d', 64), $operation->fresh()->request_digest);
        self::assertTrue((bool) data_get($distributionLog->fresh()->context, 'payload_pruned'));
        self::assertNull(data_get($distributionLog->fresh()->context, 'remote_result'));
        self::assertNull(data_get($distributionLog->fresh()->context, 'ai_workspace_execution_key'));
        self::assertSame('old active payload', $active->fresh()->prompt);
        self::assertNull($active->fresh()->payload_pruned_at);
        self::assertSame('不可清理', ConversationMessage::query()->findOrFail($unrelatedMessage->id)->content);
    }

    public function test_pruned_runs_cannot_be_edited_or_retried(): void
    {
        $admin = $this->admin();
        $run = $this->makeRun($admin, 'failed', 'pruned failed run');
        $run->forceFill(['payload_pruned_at' => now(), 'plan_version' => 1])->save();
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'failed',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'pruned-retry', 'requires_approval' => false,
            'external_operation' => false, 'attempts' => 1, 'max_attempts' => 3,
        ]);
        $engine = app(AiWorkflowEngine::class);

        try {
            $engine->retryStep($admin, $step);
            self::fail('Pruned runs must not be retried.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('留存策略清理', $exception->getMessage());
        }

        try {
            $engine->editPlan($admin, $run, [(string) $step->id => []], 1);
            self::fail('Pruned runs must not be edited.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('留存策略清理', $exception->getMessage());
        }

        self::assertTrue((bool) app(AiWorkspaceSnapshot::class)->make($run->fresh())['payload_pruned']);
    }

    public function test_recovery_requeues_an_interrupted_resolution_stage(): void
    {
        Queue::fake();
        $run = $this->makeRun($this->admin(), 'planning', 'interrupted planning');
        $run->forceFill([
            'resolution_lease_owner' => 'expired-worker',
            'resolution_lease_expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(5),
        ])->save();

        Artisan::call('geoflow:recover-ai-workspace', ['--limit' => 10]);

        self::assertSame('received', $run->fresh()->state);
        self::assertNull($run->fresh()->resolution_lease_owner);
        Queue::assertPushed(ResolveAiWorkspaceRunJob::class, fn (ResolveAiWorkspaceRunJob $job): bool => $job->runId === $run->id);
    }

    public function test_cancel_requested_run_is_finalized_when_an_internal_worker_fails(): void
    {
        $run = $this->makeRun($this->admin(), 'cancel_requested', 'cancel interrupted worker');
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'running',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'cancel-worker-failure', 'requires_approval' => false,
            'external_operation' => false, 'attempts' => 1, 'max_attempts' => 3,
            'lease_owner' => 'cancel-worker', 'lease_expires_at' => now()->addMinute(),
        ]);

        app(AiWorkflowEngine::class)->markJobFailure((string) $run->id, new RuntimeException('worker stopped'), 'cancel-worker');

        self::assertSame('cancelled', $run->fresh()->state);
        self::assertSame('skipped', $step->fresh()->state);
    }

    public function test_expired_prepared_external_step_is_safely_requeued(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '未发出站点', 'domain' => 'prepared-recovery.example.com',
            'endpoint_url' => 'https://prepared-recovery.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'running', 'prepared external recovery');
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.site_settings_sync', 'capability_version' => '1.1.0', 'state' => 'running',
            'risk_level' => 'high', 'execution_scope' => 'external_write',
            'parameters' => ['channel_ids' => [$channel->id]],
            'idempotency_key' => 'prepared-external-recovery', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'expired-prepared-worker', 'lease_expires_at' => now()->subMinute(),
        ]);
        AiWorkspaceExternalOperation::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'step_id' => $step->id,
            'execution_key' => 'prepared-external-recovery', 'capability_key' => 'distribution.site_settings_sync',
            'target_type' => 'distribution_channel', 'target_id' => (string) $channel->id,
            'status' => 'prepared', 'request_digest' => str_repeat('d', 64), 'target_digest' => str_repeat('e', 64),
        ]);

        Artisan::call('geoflow:recover-ai-workspace', ['--limit' => 10]);

        self::assertSame('queued', $run->fresh()->state);
        self::assertSame('pending', $step->fresh()->state);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class, fn (ProcessAiWorkspaceRunJob $job): bool => $job->runId === $run->id);
    }

    public function test_interrupted_atomic_distribution_enqueue_is_safely_requeued_without_an_external_ledger(): void
    {
        Queue::fake();
        $run = $this->makeRun($this->admin(), 'running', 'distribution enqueue recovery');
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.3.0', 'state' => 'running',
            'risk_level' => 'high', 'execution_scope' => 'external_write',
            'parameters' => ['article_ids' => [1], 'channel_ids' => [1]],
            'idempotency_key' => 'atomic-distribution-recovery', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'expired-distribution-worker', 'lease_expires_at' => now()->subMinute(),
        ]);

        Artisan::call('geoflow:recover-ai-workspace', ['--limit' => 10]);

        self::assertSame('queued', $run->fresh()->state);
        self::assertSame('pending', $step->fresh()->state);
        self::assertSame(0, $step->fresh()->attempts);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class, fn (ProcessAiWorkspaceRunJob $job): bool => $job->runId === $run->id);
    }

    public function test_recovery_categories_cannot_starve_expired_external_steps(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $staleResolution = $this->makeRun($admin, 'received', 'stale resolution');
        $staleResolution->forceFill([
            'resolution_lease_expires_at' => now()->subMinute(),
            'updated_at' => now()->subMinutes(5),
        ])->save();
        $staleQueued = $this->makeRun($admin, 'queued', 'stale queued run');
        $staleQueued->forceFill(['updated_at' => now()->subMinutes(5)])->save();
        $externalRun = $this->makeRun($admin, 'running', 'expired external step');
        $externalStep = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $externalRun->id, 'position' => 1,
            'capability_key' => 'distribution.publish', 'capability_version' => '1.3.0', 'state' => 'running',
            'risk_level' => 'high', 'execution_scope' => 'external_write',
            'parameters' => ['article_ids' => [1], 'channel_ids' => [1]],
            'idempotency_key' => 'non-starved-external-recovery', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'expired-priority-worker', 'lease_expires_at' => now()->subMinute(),
        ]);

        Artisan::call('geoflow:recover-ai-workspace', ['--limit' => 1]);

        self::assertSame('queued', $externalRun->fresh()->state);
        self::assertSame('pending', $externalStep->fresh()->state);
    }

    public function test_disabled_runtime_recovery_settles_pending_work_and_reconciles_confirmed_outcomes(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $queuedRun = $this->makeRun($admin, 'queued', 'disabled queued run');
        $queuedStep = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $queuedRun->id, 'position' => 1,
            'capability_key' => 'analytics.daily_report', 'capability_version' => '1.0.0', 'state' => 'pending',
            'risk_level' => 'low', 'execution_scope' => 'internal_read', 'parameters' => [],
            'idempotency_key' => 'disabled-pending-step', 'requires_approval' => false,
            'external_operation' => false, 'attempts' => 0, 'max_attempts' => 3,
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '关闭运行时对账站点', 'domain' => 'disabled-reconcile.example.com',
            'endpoint_url' => 'https://disabled-reconcile.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $externalRun = $this->makeRun($admin, 'running', 'disabled confirmed external');
        $externalRun->forceFill(['plan' => ['steps' => [['data_classification' => 'confidential']]]])->save();
        $externalStep = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $externalRun->id, 'position' => 1,
            'capability_key' => 'distribution.site_settings_sync', 'capability_version' => '1.1.0',
            'state' => 'running', 'risk_level' => 'high', 'execution_scope' => 'external_write',
            'result_contract' => ['type' => 'site_settings_sync'],
            'parameters' => ['channel_ids' => [$channel->id]],
            'idempotency_key' => 'disabled-confirmed-external', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'disabled-external-worker', 'lease_expires_at' => now()->subMinute(),
        ]);
        $activeRun = $this->makeRun($admin, 'running', 'disabled active external read');
        $activeStep = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $activeRun->id, 'position' => 1,
            'capability_key' => 'url_import.preview', 'capability_version' => '1.0.0',
            'state' => 'running', 'risk_level' => 'medium', 'execution_scope' => 'external_read',
            'parameters' => ['url' => 'https://example.com/article'],
            'idempotency_key' => 'disabled-active-external-read', 'requires_approval' => false,
            'external_operation' => false, 'attempts' => 1, 'max_attempts' => 3,
            'lease_owner' => 'active-external-read-worker', 'lease_expires_at' => now()->addMinute(),
        ]);
        AiWorkspaceExternalOperation::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $externalRun->id, 'step_id' => $externalStep->id,
            'execution_key' => 'disabled-confirmed-external',
            'capability_key' => 'distribution.site_settings_sync',
            'target_type' => 'distribution_channel', 'target_id' => (string) $channel->id,
            'status' => 'confirmed', 'request_digest' => str_repeat('a', 64),
            'target_digest' => str_repeat('b', 64),
            'remote_result' => ['remote_result' => ['success' => true], 'refresh_count' => 1],
            'dispatched_at' => now()->subMinute(), 'confirmed_at' => now()->subSeconds(30),
        ]);
        config()->set('ai-workspace.runtime_enabled', false);

        Artisan::call('geoflow:recover-ai-workspace', ['--limit' => 10]);

        self::assertSame('cancelled', $queuedRun->fresh()->state);
        self::assertSame('skipped', $queuedStep->fresh()->state);
        self::assertSame('partially_completed', $externalRun->fresh()->state);
        self::assertSame('completed', $externalStep->fresh()->state);
        self::assertSame('cancel_requested', $activeRun->fresh()->state);
        self::assertSame('running', $activeStep->fresh()->state);
        self::assertSame('active-external-read-worker', $activeStep->fresh()->lease_owner);
        self::assertDatabaseHas('ai_workspace_artifacts', ['step_id' => $externalStep->id, 'type' => 'site_settings_sync']);
        Queue::assertNotPushed(ProcessAiWorkspaceRunJob::class);
        Queue::assertNotPushed(ResolveAiWorkspaceRunJob::class);
    }

    public function test_expired_external_step_reconciles_a_confirmed_remote_result_before_retry(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '对账站点',
            'domain' => 'reconcile.example.com',
            'endpoint_url' => 'https://reconcile.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'running', 'distribution.site_settings_sync');
        $executionKey = 'aiw:external-reconciliation';
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(),
            'run_id' => $run->id,
            'position' => 1,
            'capability_key' => 'distribution.site_settings_sync',
            'capability_name' => '站点设置同步',
            'capability_version' => '1.1.0',
            'state' => 'running',
            'risk_level' => 'high',
            'execution_scope' => 'external_write',
            'approval_policy' => 'target_matrix',
            'result_contract' => ['type' => 'site_settings_sync'],
            'parameters' => ['channel_ids' => [$channel->id]],
            'target_summary' => ['channel_ids' => [$channel->id]],
            'idempotency_key' => $executionKey,
            'requires_approval' => true,
            'external_operation' => true,
            'attempts' => 1,
            'max_attempts' => 1,
            'lease_owner' => 'expired-worker',
            'lease_expires_at' => now()->subMinute(),
        ]);
        AiWorkspaceExternalOperation::query()->create([
            'id' => (string) Str::uuid7(),
            'run_id' => (string) $run->id,
            'step_id' => (string) $step->id,
            'execution_key' => $executionKey,
            'capability_key' => 'distribution.site_settings_sync',
            'target_type' => 'distribution_channel',
            'target_id' => (string) $channel->id,
            'status' => 'confirmed',
            'request_digest' => str_repeat('a', 64),
            'target_digest' => str_repeat('b', 64),
            'remote_result' => [
                'remote_result' => ['success' => true],
                'refresh_count' => 2,
            ],
            'dispatched_at' => now()->subSecond(),
            'confirmed_at' => now(),
        ]);

        $reconciled = app(AiWorkflowEngine::class)->reconcileExpiredExternalStep($step);

        self::assertSame('queued', $reconciled?->state);
        self::assertSame('completed', $step->fresh()->state);
        self::assertDatabaseHas('ai_workspace_artifacts', ['step_id' => $step->id, 'type' => 'site_settings_sync']);
        Queue::assertPushed(ProcessAiWorkspaceRunJob::class);
    }

    public function test_confirmed_external_result_is_preserved_after_admin_authorization_changes(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $channel = DistributionChannel::query()->create([
            'name' => '授权变化对账站点',
            'domain' => 'reconcile-auth-change.example.com',
            'endpoint_url' => 'https://reconcile-auth-change.example.com/api',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $run = $this->makeRun($admin, 'running', 'confirmed result after auth change');
        $run->forceFill([
            'admin_auth_version' => $admin->auth_version,
            'plan' => ['steps' => [['data_classification' => 'confidential']]],
        ])->save();
        $step = AiWorkspaceStep::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'position' => 1,
            'capability_key' => 'distribution.site_settings_sync', 'capability_version' => '1.1.0',
            'capability_name' => '站点设置同步', 'state' => 'running',
            'risk_level' => 'high', 'execution_scope' => 'external_write',
            'result_contract' => ['type' => 'site_settings_sync'],
            'parameters' => ['channel_ids' => [$channel->id]],
            'idempotency_key' => 'confirmed-after-auth-change', 'requires_approval' => true,
            'external_operation' => true, 'attempts' => 1, 'max_attempts' => 1,
            'lease_owner' => 'expired-auth-change-worker', 'lease_expires_at' => now()->subMinute(),
        ]);
        AiWorkspaceExternalOperation::query()->create([
            'id' => (string) Str::uuid7(), 'run_id' => $run->id, 'step_id' => $step->id,
            'execution_key' => 'confirmed-after-auth-change',
            'capability_key' => 'distribution.site_settings_sync',
            'target_type' => 'distribution_channel', 'target_id' => (string) $channel->id,
            'status' => 'confirmed', 'request_digest' => str_repeat('a', 64),
            'target_digest' => str_repeat('b', 64),
            'remote_result' => ['remote_result' => ['success' => true], 'refresh_count' => 1],
            'dispatched_at' => now()->subMinute(), 'confirmed_at' => now()->subSeconds(30),
        ]);
        $admin->forceFill(['status' => 'disabled', 'auth_version' => $admin->auth_version + 1])->save();

        $reconciled = app(AiWorkflowEngine::class)->reconcileExpiredExternalStep($step);

        self::assertSame('partially_completed', $reconciled?->state);
        self::assertSame('completed', $step->fresh()->state);
        self::assertSame('governance_changed_after_external_result', $run->fresh()->failure_code);
        self::assertDatabaseHas('ai_workspace_artifacts', [
            'step_id' => $step->id,
            'created_by_admin_id' => $admin->id,
            'type' => 'site_settings_sync',
            'data_classification' => 'confidential',
        ]);
        Queue::assertNotPushed(ProcessAiWorkspaceRunJob::class);
    }

    private function preparedTaskDraft(Admin $admin, string $name): AiWorkspaceRun
    {
        $run = $this->planningRun($admin, 'task.draft');
        $plan = app(AiPlanCompiler::class)->compile($admin, 'task.draft', [[
            'capability' => 'task.draft', 'parameters' => ['name' => $name],
        ]]);

        return app(AiWorkflowEngine::class)->prepare($run, $plan);
    }

    /** @return array{title_library_id:int,prompt_id:int,ai_model_id:int} */
    private function draftTaskDependencies(): array
    {
        $prompt = Prompt::query()->create([
            'name' => 'AI 工作台内容提示词',
            'type' => 'content',
            'content' => '请根据标题生成内容。',
        ]);
        $model = AiModel::query()->create([
            'name' => 'AI 工作台对话模型',
            'model_id' => 'workspace-chat-model',
            'model_type' => 'chat',
            'api_key' => 'test-key',
            'api_url' => 'https://example.com/v1',
            'failover_priority' => 10,
            'status' => 'active',
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => 'AI 工作台标题库',
            'description' => '任务草稿默认标题库',
        ]);

        return [
            'title_library_id' => (int) $titleLibrary->id,
            'prompt_id' => (int) $prompt->id,
            'ai_model_id' => (int) $model->id,
        ];
    }

    private function planningRun(Admin $admin, string $intent): AiWorkspaceRun
    {
        return $this->makeRun($admin, 'planning', $intent);
    }

    private function makeRun(Admin $admin, string $state, string $intent): AiWorkspaceRun
    {
        $conversation = app(AiConversationRepository::class)->create($admin, '测试会话');

        return AiWorkspaceRun::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'mode' => 'workflow',
            'state' => $state,
            'prompt' => $intent,
            'intent' => $intent,
            'risk_level' => 'low',
            'status_message' => '测试',
        ]);
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'workflow-'.Str::lower(Str::random(8)),
            'password' => 'secret-123',
            'email' => Str::lower(Str::random(8)).'@example.com',
            'display_name' => 'Workflow Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
