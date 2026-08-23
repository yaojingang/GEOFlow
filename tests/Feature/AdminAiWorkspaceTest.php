<?php

namespace Tests\Feature;

use App\Ai\Agents\GeoHubPlanDrafterAgent;
use App\Ai\Agents\IntentResolverAgent;
use App\Ai\Workspace\AiPlanCompiler;
use App\Jobs\ProcessAiWorkspaceRunJob;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\AiConversation;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Models\Task;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminAiWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_api_is_closed_by_default(): void
    {
        $admin = $this->admin('runtime-disabled');
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.index'))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.store'), ['title' => '关闭状态'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_workspace_disabled');
    }

    public function test_runtime_requires_a_verified_structured_output_model(): void
    {
        $admin = $this->admin('model-required');
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.index'))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.store'), ['title' => '模型未就绪'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_workspace_model_unavailable');
    }

    public function test_conversations_are_scoped_to_the_current_admin_and_can_be_archived(): void
    {
        $owner = $this->readyAdmin('conversation-owner');
        $other = $this->admin('conversation-other');

        $created = $this->actingAs($owner, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.store'), ['title' => 'GEO 周报'])
            ->assertCreated()
            ->assertJsonPath('data.title', 'GEO 周报')
            ->json('data.id');

        $this->actingAs($other, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.show', ['conversation' => $created]))
            ->assertNotFound();

        $this->actingAs($owner, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.archive', ['conversation' => $created]))
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        self::assertNotNull(AiConversation::query()->findOrFail($created)->archived_at);
    }

    public function test_existing_conversation_can_be_archived_while_runtime_is_disabled(): void
    {
        $admin = $this->readyAdmin('archive-runtime-disabled');
        $conversation = app(AiConversationRepository::class)->create($admin, '待归档会话');
        config()->set('ai-workspace.runtime_enabled', false);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.archive', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.archived', true);
    }

    public function test_message_submission_precreates_an_authoritative_run_and_is_idempotent(): void
    {
        Queue::fake();
        $this->ensureAuditTable();
        $admin = $this->readyAdmin('message-owner');
        $conversation = AiConversation::query()->create([
            'id' => '0191f912-7084-7ae1-a6bb-e6ea14a71f11',
            'participant_type' => $admin->getMorphClass(),
            'participant_id' => $admin->id,
            'title' => '任务',
        ]);
        $payload = ['prompt' => '创建任务“八月计划”', 'request_key' => 'request-123'];
        $url = route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]);

        $first = $this->actingAs($admin, 'admin')->postJson($url, $payload)->assertAccepted();
        $second = $this->actingAs($admin, 'admin')->postJson($url, $payload)->assertAccepted();

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, AiWorkspaceRun::query()->where('request_key', 'request-123')->count());
        self::assertSame('received', $first->json('data.state'));
        self::assertSame(config('ai-workspace.prompt_versions'), $first->json('data.prompt_versions'));
        Queue::assertPushed(ResolveAiWorkspaceRunJob::class, 1);

        $audit = AdminActivityLog::query()->latest('id')->firstOrFail();
        self::assertStringNotContainsString('八月计划', (string) $audit->details);
        self::assertStringContainsString((string) $first->json('data.id'), (string) $audit->details);
    }

    public function test_http_message_runs_through_agent_planning_execution_and_result_snapshot(): void
    {
        Queue::fake();
        IntentResolverAgent::fake([[
            'mode' => 'workflow',
            'intent' => 'analytics.daily_report',
            'candidate_capabilities' => [[
                'key' => 'analytics.daily_report',
                'confidence' => 0.99,
                'reason' => '用户明确请求今日运营报告',
            ]],
            'requested_steps' => [[
                'operation_id' => 'daily-report',
                'capability' => 'analytics.daily_report',
                'parameters' => ['date' => now()->toDateString()],
                'missing_parameters' => [],
            ]],
            'known_parameters' => ['date' => now()->toDateString()],
            'missing_parameters' => [],
            'ambiguities' => [],
            'semantic_confidence' => 0.99,
            'object_confidence' => 1,
            'completeness_confidence' => 1,
        ]])->preventStrayPrompts();
        GeoHubPlanDrafterAgent::fake([[
            'summary' => '生成今日运营报告',
            'steps' => [[
                'operation_id' => 'daily-report',
                'capability' => 'analytics.daily_report',
                'parameters' => ['date' => now()->toDateString()],
                'depends_on' => [],
                'input_bindings' => [],
            ]],
        ]])->preventStrayPrompts();
        $admin = $this->readyAdmin('http-agent-chain');

        $conversationId = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.conversations.store'), ['title' => 'Agent 全链路'])
            ->assertCreated()
            ->json('data.id');
        $runId = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.messages.store', ['conversation' => $conversationId]), [
                'prompt' => '生成今天的运营日报',
                'request_key' => 'http-agent-chain-1',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.state', 'received')
            ->json('data.id');

        (new ResolveAiWorkspaceRunJob($runId))->handle(app(AiWorkspaceCoordinator::class));
        self::assertSame('queued', AiWorkspaceRun::query()->findOrFail($runId)->state);
        (new ProcessAiWorkspaceRunJob($runId))->handle(app(AiWorkflowEngine::class));

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.runs.show', ['run' => $runId]))
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.system_operations_executed', true)
            ->assertJsonPath('data.steps.0.state', 'completed')
            ->assertJsonPath('data.artifacts.0.type', 'operational_report');
        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.show', ['conversation' => $conversationId]))
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', '生成今天的运营日报');

        IntentResolverAgent::assertPrompted('生成今天的运营日报');
        GeoHubPlanDrafterAgent::assertPrompted('生成今天的运营日报');
    }

    public function test_request_key_replay_must_match_the_original_conversation_and_prompt(): void
    {
        Queue::fake();
        $this->ensureAuditTable();
        $admin = $this->readyAdmin('request-replay-owner');
        $firstConversation = app(AiConversationRepository::class)->create($admin, '第一段对话');
        $secondConversation = app(AiConversationRepository::class)->create($admin, '第二段对话');
        $requestKey = 'request-replay-123';

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $firstConversation->id]),
            ['prompt' => '生成运营日报', 'request_key' => $requestKey],
        )->assertAccepted();

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $firstConversation->id]),
            ['prompt' => '创建任务草稿', 'request_key' => $requestKey],
        )->assertConflict()->assertJsonPath('code', 'workflow_conflict');

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $secondConversation->id]),
            ['prompt' => '生成运营日报', 'request_key' => $requestKey],
        )->assertConflict()->assertJsonPath('code', 'workflow_conflict');

        self::assertSame(1, AiWorkspaceRun::query()->where('request_key', $requestKey)->count());
    }

    public function test_message_rate_limit_is_separate_from_read_polling(): void
    {
        Queue::fake();
        config()->set('ai-workspace.max_active_runs_per_admin', 20);
        $admin = $this->readyAdmin('message-throttle');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $url = route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]);

        foreach (range(1, 6) as $index) {
            $this->actingAs($admin, 'admin')->postJson($url, [
                'prompt' => '请求 '.$index,
                'request_key' => 'throttle-'.$index,
            ])->assertAccepted();
        }

        $this->actingAs($admin, 'admin')->postJson($url, [
            'prompt' => '第七个请求',
            'request_key' => 'throttle-7',
        ])->assertTooManyRequests();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.index'))
            ->assertOk();
    }

    public function test_model_configuration_change_invalidates_workspace_readiness(): void
    {
        $model = $this->verifiedModel();

        $model->update(['api_url' => 'https://changed.example.invalid/v1']);

        self::assertNull($model->fresh()->ai_workspace_structured_output_status);
        self::assertNull($model->fresh()->ai_workspace_structured_output_verified_at);
    }

    public function test_validation_authentication_metrics_and_admin_ownership_use_json_contracts(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        $this->verifiedModel();
        $admin = $this->admin('validation-owner', 'admin');
        $super = $this->admin('metrics-owner');

        $this->getJson(route('admin.ai-workspace.conversations.index'))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]), ['prompt' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed');

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.metrics'))
            ->assertForbidden();
        $this->actingAs($super, 'admin')
            ->getJson(route('admin.ai-workspace.metrics', ['days' => 7]))
            ->assertOk()
            ->assertJsonPath('data.window_days', 7);
    }

    public function test_unauthenticated_workspace_api_returns_json_without_accept_header(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $this->get(route('admin.ai-workspace.conversations.index'))
            ->assertUnauthorized()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_plan_edit_with_a_missing_target_returns_validation_error(): void
    {
        Queue::fake();
        $admin = $this->readyAdmin('missing-plan-target');
        $conversation = app(AiConversationRepository::class)->create($admin, '计划目标校验');
        $task = Task::query()->create([
            'name' => '计划目标',
            'status' => 'paused',
            'publish_scope' => 'local_only',
            'distribution_strategy' => 'broadcast',
            'schedule_enabled' => false,
        ]);
        $run = AiWorkspaceRun::query()->create([
            'id' => '0191f912-7084-7ae1-a6bb-e6ea14a71f33',
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'admin_auth_version' => $admin->auth_version,
            'mode' => 'workflow',
            'state' => 'planning',
            'prompt' => '启动任务',
            'intent' => 'task.status.change',
            'risk_level' => 'medium',
        ]);
        $plan = app(AiPlanCompiler::class)->compile($admin, 'task.status.change', [[
            'capability' => 'task.status.change',
            'parameters' => ['task_id' => $task->id, 'action' => 'start'],
        ]]);
        $awaiting = app(AiWorkflowEngine::class)->prepare($run, $plan);
        $step = $awaiting->steps()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->putJson(route('admin.ai-workspace.runs.plan.update', ['run' => $awaiting->id]), [
                'plan_version' => 1,
                'step_parameters' => [
                    (string) $step->id => ['task_id' => 999999, 'action' => 'start'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.step_parameters.0', 'Task target does not exist: 999999');
    }

    public function test_workspace_states_render_in_all_six_supported_locales(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = $this->admin('locale-owner');

        foreach (['zh_CN', 'en', 'es', 'pt_BR', 'ja', 'ru'] as $locale) {
            $this->withSession(['locale' => $locale, Admin::AUTH_VERSION_SESSION_KEY => 1])
                ->actingAs($admin, 'admin')
                ->get(route('admin.ai-workspace'))
                ->assertOk()
                ->assertDontSee('admin.ai_workspace.status_running')
                ->assertSee('data-ai-labels', false);
        }
    }

    private function readyAdmin(string $username): Admin
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        $this->verifiedModel();

        return $this->admin($username);
    }

    private function verifiedModel(): AiModel
    {
        return AiModel::query()->firstOrCreate(['model_id' => 'workspace-test-model'], [
            'name' => 'Workspace Test',
            'version' => '1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('workspace-test-key'),
            'model_type' => 'chat',
            'api_url' => 'https://example.invalid/v1',
            'status' => 'active',
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
        ]);
    }

    private function ensureAuditTable(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }
        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('admin_id')->nullable();
            $table->string('admin_username')->default('');
            $table->string('admin_role')->default('');
            $table->string('action');
            $table->string('request_method', 10)->default('GET');
            $table->string('page')->default('');
            $table->string('target_type')->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->default('');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    private function admin(string $username, string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
