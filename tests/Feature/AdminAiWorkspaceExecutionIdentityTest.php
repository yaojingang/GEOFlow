<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Ai\Workspace\AiPlanCompiler;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiConversationMessage;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\AiWorkspaceRun;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use App\Services\AiWorkspace\AiWorkspaceExecutionAccessGuard;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Services\AiWorkspace\AiWorkspaceModelUsageDelivery;
use App\Services\AiWorkspace\AiWorkspaceRuntimeGuardException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Generator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminAiWorkspaceExecutionIdentityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        config()->set('ai-workspace.require_verified_model', false);
    }

    public function test_readiness_and_runtime_use_personal_then_shared_models_and_exclude_peer_and_system_models(): void
    {
        $provider = $this->admin('workspace-provider', 'super_admin');
        $actor = $this->admin('workspace-actor', 'admin', $provider);
        $peer = $this->admin('workspace-peer', 'admin');

        $peerModel = $this->model($peer, 'peer-model', 1);
        $systemModel = $this->model($provider, 'system-model', 1, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $sharedModel = $this->model($provider, 'shared-model', 2);
        $personalModel = $this->model($actor, 'personal-model', 20);
        AdminAiSetting::query()->forceCreate([
            'admin_id' => $actor->id,
            'default_chat_model_id' => $sharedModel->id,
            'updated_by_admin_id' => $provider->id,
        ]);

        $status = app(AiWorkspaceModelReadiness::class)->status($actor);

        self::assertTrue($status['ready']);
        self::assertSame($personalModel->id, $status['model_id']);

        AdminHelpAssistant::fake(['personal answer'])->preventStrayPrompts();
        $answer = app(AiWorkspaceModelRuntime::class)->answer('question', 'context', [], $actor);

        self::assertSame('personal answer', $answer);
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === 'personal-model',
        );
        AdminHelpAssistant::assertNotPrompted(
            static fn ($prompt): bool => in_array($prompt->model, [
                (string) $peerModel->model_id,
                (string) $systemModel->model_id,
                (string) $sharedModel->model_id,
            ], true),
        );
    }

    public function test_requested_workspace_model_is_frozen_with_admin_access_identity_when_run_is_created(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-run-owner', 'admin');
        $model = $this->model($admin, 'workspace-requested');
        AdminAiSetting::query()->forceCreate([
            'admin_id' => $admin->id,
            'default_chat_model_id' => $model->id,
            'updated_by_admin_id' => $admin->id,
        ]);
        $conversation = app(AiConversationRepository::class)->create($admin);

        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            $conversation,
            '请分析本周的内容运营情况并给出建议。',
        );

        self::assertSame((int) $admin->id, (int) $run->model_access_admin_id);
        self::assertSame('admin', $run->model_access_admin_role);
        self::assertSame((int) $admin->ai_config_access_version, (int) $run->ai_config_access_version);
        self::assertSame((int) $model->id, (int) $run->requested_ai_model_id);
        self::assertSame(1, (int) $run->resolver_policy_version);
        self::assertNull($run->resolved_ai_model_id);
        self::assertArrayNotHasKey('resolution_lease_owner', $run->toArray());
        self::assertArrayNotHasKey('execution_lease_token', $run->toArray());
    }

    public function test_workspace_execution_context_exposes_only_safe_identity_metadata(): void
    {
        $admin = $this->admin('workspace-safe-context', 'super_admin');
        $context = app(AiWorkspaceExecutionAccessGuard::class)->directContext(
            $admin,
            requestId: 'workspace-request-safe',
        );
        $safe = $context->toSafeArray();

        self::assertSame((int) $admin->id, $safe['model_access_admin_id']);
        self::assertSame('workspace-request-safe', $safe['request_id']);
        self::assertArrayNotHasKey('execution_lease_token', $safe);
        self::assertArrayNotHasKey('lease_token', $safe);
        self::assertArrayNotHasKey('api_key', $safe);
        self::assertArrayNotHasKey('endpoint', $safe);
        self::assertArrayNotHasKey('prompt', $safe);
    }

    public function test_business_mass_assignment_cannot_override_workspace_execution_identity(): void
    {
        $admin = $this->admin('workspace-mass-assignment', 'super_admin');
        $conversation = app(AiConversationRepository::class)->create($admin);

        $run = AiWorkspaceRun::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'admin_auth_version' => $admin->auth_version,
            'mode' => 'workflow',
            'state' => 'received',
            'prompt' => '尝试覆盖执行身份',
            'risk_level' => 'low',
            'model_access_admin_id' => 999999,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => 999,
            'resolver_policy_version' => 999,
            'execution_lease_token' => 'attacker-controlled',
        ]);

        self::assertNull($run->model_access_admin_id);
        self::assertNull($run->model_access_admin_role);
        self::assertNull($run->ai_config_access_version);
        self::assertNull($run->resolver_policy_version);
        self::assertNull($run->execution_lease_token);
    }

    public function test_shared_default_is_used_only_when_personal_pool_is_empty(): void
    {
        Queue::fake();
        $provider = $this->admin('workspace-shared-provider', 'super_admin');
        $admin = $this->admin('workspace-shared-actor', 'admin', $provider);
        $shared = $this->model($provider, 'workspace-shared-default');
        AdminAiSetting::query()->forceCreate([
            'admin_id' => $admin->id,
            'default_chat_model_id' => $shared->id,
            'updated_by_admin_id' => $provider->id,
        ]);

        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请根据后台数据准备一份运营建议。',
        );

        self::assertSame((int) $shared->id, (int) $run->requested_ai_model_id);
    }

    public function test_transient_personal_failure_can_fall_back_to_an_authorized_shared_model(): void
    {
        $provider = $this->admin('workspace-fallback-provider', 'super_admin');
        $admin = $this->admin('workspace-fallback-actor', 'admin', $provider);
        $personal = $this->model($admin, 'workspace-personal-failure', 20);
        $shared = $this->model($provider, 'workspace-shared-success', 1);
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls): string {
            $calls++;
            if ($calls === 1) {
                throw $this->requestException(429, 'temporary personal failure');
            }

            return 'shared fallback answer';
        })->preventStrayPrompts();

        self::assertSame(
            'shared fallback answer',
            app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin),
        );
        self::assertSame(2, $calls);
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $personal->model_id,
        );
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $shared->model_id,
        );
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame(AiModelUsageEvent::STATUS_FAILED, $events[0]->status);
        self::assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $events[0]->model_source);
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[1]->status);
        self::assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $events[1]->model_source);
        self::assertSame($provider->id, $events[1]->config_owner_admin_id);
        self::assertSame($admin->id, $events[1]->execution_admin_id);
    }

    public function test_workspace_stream_usage_remains_pending_until_the_consumer_commits_the_result(): void
    {
        $admin = $this->admin('workspace-usage-delivery-owner', 'super_admin');
        $this->model($admin, 'workspace-usage-delivery-model');
        AdminHelpAssistant::fake(['provider response'])->preventStrayPrompts();

        $stream = app(AiWorkspaceModelRuntime::class)->stream('question', 'context', [], $admin);
        iterator_to_array($stream);
        $result = $stream->getReturn();

        self::assertDatabaseCount('ai_model_usage_events', 0);
        self::assertInstanceOf(
            AiWorkspaceModelUsageDelivery::class,
            $result['usage_delivery'] ?? null,
        );

        $result['usage_delivery']->succeeded();

        $event = AiModelUsageEvent::query()->sole();
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
    }

    public function test_interactive_workspace_marks_usage_succeeded_after_complete_generation_commits(): void
    {
        $admin = $this->admin('workspace-complete-generation-usage', 'super_admin');
        $this->model($admin, 'workspace-complete-generation-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        AdminHelpAssistant::fake(['committed interactive answer'])->preventStrayPrompts();

        $stream = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请回答这个需要模型处理的问题。'],
        )->assertOk()->streamedContent();

        self::assertStringContainsString('event: done', $stream);
        self::assertSame(1, AiConversationMessage::query()->where('role', 'assistant')->count());
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, AiModelUsageEvent::query()->sole()->status);
    }

    public function test_workspace_answer_records_revoked_when_access_changes_after_provider_response_before_commit(): void
    {
        $admin = $this->admin('workspace-post-provider-revoked', 'super_admin');
        $this->model($admin, 'workspace-post-provider-model');
        $context = app(AiWorkspaceExecutionAccessGuard::class)->directContext(
            $admin,
            requestId: 'workspace-post-provider-race',
        );
        AdminHelpAssistant::fake(['provider response'])->preventStrayPrompts();

        try {
            app(AiWorkspaceModelRuntime::class)->answer(
                'question',
                'context',
                [],
                $context,
                function ($receipt) use ($admin, $context): void {
                    self::assertDatabaseCount('ai_model_usage_events', 0);
                    Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');
                    app(AiWorkspaceExecutionAccessGuard::class)
                        ->assertReceiptCurrent($context, $receipt);
                },
            );
            self::fail('Expected post-provider revocation to reject the result.');
        } catch (AiModelAccessException $exception) {
            self::assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $event = AiModelUsageEvent::query()->sole();
        self::assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        self::assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $event->error_code);
    }

    public function test_persisted_workspace_run_marks_each_attempt_succeeded_only_after_its_state_commit(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-persisted-usage-owner', 'super_admin');
        $this->model($admin, 'workspace-persisted-usage-model');
        AdminHelpAssistant::fake([
            json_encode(['mode' => 'answer', 'intent' => 'answer question'], JSON_THROW_ON_ERROR),
            'durable answer',
        ])->preventStrayPrompts();
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请分析一个需要模型回答的复杂运营问题。',
        );

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        self::assertSame('completed', $run->fresh()->state);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame(
            [AiModelUsageEvent::STATUS_SUCCEEDED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        self::assertSame(AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN, $events[1]->execution_scope);
    }

    public function test_persisted_workspace_answer_is_revoked_when_access_changes_after_provider_response_before_final_state_commit(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-persisted-final-race', 'super_admin');
        $this->model($admin, 'workspace-persisted-final-race-model');
        AdminHelpAssistant::fake([
            json_encode(['mode' => 'answer', 'intent' => 'answer question'], JSON_THROW_ON_ERROR),
            'result that must be revoked',
        ])->preventStrayPrompts();
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请分析另一个需要模型回答的复杂运营问题。',
        );
        $modelCompletions = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($admin, &$modelCompletions, &$revoked): void {
            if ($revoked || ! str_contains(strtolower($query->sql), 'ai_workspace_trace_events')) {
                return;
            }
            if (! collect($query->bindings)->contains('model.completed')) {
                return;
            }
            $modelCompletions++;
            if ($modelCompletions === 2) {
                $revoked = true;
                Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');
            }
        });

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        self::assertTrue($revoked);
        self::assertSame('failed', $run->fresh()->state);
        self::assertSame('authorization_revoked', $run->fresh()->failure_code);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[0]->status);
        self::assertSame(AiModelUsageEvent::STATUS_REVOKED, $events[1]->status);
    }

    public function test_persisted_workspace_cancellation_discards_usage_and_preserves_the_partial_answer(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-persisted-cancel-owner', 'super_admin');
        $this->model($admin, 'workspace-persisted-cancel-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请生成一个允许用户中途取消的回答。',
        );
        $calls = 0;
        AdminHelpAssistant::fake(function () use ($admin, $run, &$calls): string {
            $calls++;
            if ($calls === 1) {
                return json_encode([
                    'mode' => 'answer',
                    'intent' => 'answer cancellable question',
                ], JSON_THROW_ON_ERROR);
            }

            $run->forceFill([
                'answer' => '已经持久化的回答片段',
                'answer_chunk_sequence' => 1,
                'answer_is_partial' => true,
            ])->save();
            app(AiWorkflowEngine::class)->cancel($admin, $run->fresh());

            return '取消后返回的供应商内容';
        })->preventStrayPrompts();

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        $cancelled = $run->fresh();
        self::assertSame(2, $calls);
        self::assertSame('cancelled', $cancelled->state);
        self::assertSame('已经持久化的回答片段', $cancelled->answer);
        self::assertTrue((bool) $cancelled->answer_is_partial);
        self::assertNotSame('authorization_revoked', $cancelled->failure_code);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[0]->status);
        self::assertSame(AiModelUsageEvent::STATUS_DISCARDED, $events[1]->status);
    }

    public function test_workspace_provider_result_is_discarded_when_the_resolution_lease_is_lost(): void
    {
        $admin = $this->admin('workspace-provider-lease-lost', 'super_admin');
        $this->model($admin, 'workspace-provider-lease-lost-model');
        [$run, $context] = $this->answeringRunContext($admin, 'provider-lease-lost');
        AdminHelpAssistant::fake(function () use ($run): string {
            $run->forceFill([
                'resolution_lease_owner' => (string) Str::uuid7(),
                'resolution_lease_expires_at' => now()->addMinute(),
            ])->save();

            return '旧租约取得的供应商结果';
        })->preventStrayPrompts();

        try {
            app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $context);
            self::fail('A stale resolution lease must reject the provider result.');
        } catch (AiWorkspaceRuntimeGuardException $exception) {
            self::assertSame('AI 工作台执行状态或租约已经变化。', $exception->getMessage());
        }

        $event = AiModelUsageEvent::query()->sole();
        self::assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        self::assertSame('ai_result_not_committed', $event->error_code);
        self::assertSame('answering', $run->fresh()->state);
        self::assertNull($run->fresh()->failure_code);
    }

    public function test_workspace_stream_lease_loss_after_the_first_delta_does_not_open_the_provider_circuit(): void
    {
        $admin = $this->admin('workspace-stream-lease-lost', 'super_admin');
        $model = $this->model($admin, 'workspace-stream-lease-lost-model');
        [$run, $context] = $this->answeringRunContext($admin, 'stream-lease-lost');
        AdminHelpAssistant::fake(['第一段 第二段'])->preventStrayPrompts();
        $deltas = [];

        try {
            foreach (app(AiWorkspaceModelRuntime::class)->stream('问题', '上下文', [], $context) as $event) {
                if (($event['type'] ?? null) !== 'delta') {
                    continue;
                }
                $deltas[] = (string) $event['content'];
                if (count($deltas) === 1) {
                    $run->forceFill([
                        'resolution_lease_owner' => (string) Str::uuid7(),
                        'resolution_lease_expires_at' => now()->addMinute(),
                    ])->save();
                }
            }
            self::fail('The second delta must be rejected after the resolution lease changes.');
        } catch (AiWorkspaceRuntimeGuardException $exception) {
            self::assertSame('AI 工作台执行状态或租约已经变化。', $exception->getMessage());
        }

        $fingerprint = hash('sha256', implode('|', [
            (string) $model->id,
            (string) $model->model_id,
            OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url),
        ]));
        self::assertSame(['第一段'], $deltas);
        self::assertFalse(Cache::has('ai-workspace:provider-failures:'.$fingerprint));
        self::assertFalse(Cache::has('ai-workspace:provider-circuit:'.$fingerprint));
        self::assertSame(AiModelUsageEvent::STATUS_DISCARDED, AiModelUsageEvent::query()->sole()->status);
        self::assertSame('answering', $run->fresh()->state);
        self::assertNull($run->fresh()->failure_code);
    }

    public function test_persisted_workspace_plan_usage_is_revoked_when_access_changes_before_prepare_commits(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-persisted-plan-race', 'super_admin');
        $this->model($admin, 'workspace-persisted-plan-race-model');
        AdminHelpAssistant::fake([
            json_encode([
                'mode' => 'workflow',
                'intent' => '生成运营日报',
                'candidate_capabilities' => [[
                    'key' => 'analytics.daily_report',
                    'confidence' => 1,
                    'reason' => '用户需要日报',
                ]],
                'known_parameters' => [],
                'requested_steps' => [[
                    'operation_id' => 'daily-report',
                    'capability' => 'analytics.daily_report',
                    'parameters' => [],
                ]],
                'semantic_confidence' => 1,
                'object_confidence' => 1,
                'completeness_confidence' => 1,
            ], JSON_THROW_ON_ERROR),
            json_encode(['steps' => [[
                'capability' => 'analytics.daily_report',
                'parameters' => [],
            ]]], JSON_THROW_ON_ERROR),
        ])->preventStrayPrompts();
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请生成一份运营日报。',
        );
        $modelCompletions = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($admin, &$modelCompletions, &$revoked): void {
            if ($revoked || ! str_contains(strtolower($query->sql), 'ai_workspace_trace_events')) {
                return;
            }
            if (! collect($query->bindings)->contains('model.completed')) {
                return;
            }
            $modelCompletions++;
            if ($modelCompletions === 2) {
                $revoked = true;
                Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');
            }
        });

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        self::assertTrue($revoked);
        self::assertSame('failed', $run->fresh()->state);
        self::assertSame('authorization_revoked', $run->fresh()->failure_code);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        self::assertCount(2, $events);
        self::assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $events[0]->status);
        self::assertSame(AiModelUsageEvent::STATUS_REVOKED, $events[1]->status);
    }

    public function test_fallback_model_is_registered_before_outbound_and_blocks_update_and_delete(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-fallback-mutation-gate', 'super_admin');
        $this->model($admin, 'workspace-fallback-gate-primary', 1);
        $secondary = $this->model($admin, 'workspace-fallback-gate-secondary', 2);
        [$run, $context] = $this->answeringRunContext($admin, 'fallback-mutation-gate');
        $updated = null;
        $deleted = null;
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls, &$updated, &$deleted, $admin, $secondary): string {
            $calls++;
            if ($calls === 1) {
                throw $this->requestException(429, 'temporary primary failure');
            }
            $mutations = app(AdminAiModelMutationService::class);
            $updated = $mutations->update(
                $admin,
                (int) $secondary->id,
                ['name' => 'must remain locked'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            $deleted = $mutations->delete($admin, (int) $secondary->id);

            return 'secondary answer';
        })->preventStrayPrompts();

        self::assertSame('secondary answer', app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $context));
        self::assertFalse($updated->succeeded());
        self::assertFalse($deleted->succeeded());
        self::assertSame('task', $updated->error);
        self::assertSame('task', $deleted->error);
        self::assertSame((int) $secondary->id, (int) $run->fresh()->resolved_ai_model_id);
    }

    public function test_fallback_claim_uses_the_freshly_locked_model_configuration(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-fallback-fresh-model', 'super_admin');
        $this->model($admin, 'workspace-fallback-fresh-primary', 1);
        $secondary = $this->model($admin, 'workspace-fallback-fresh-secondary', 2);
        [, $context] = $this->answeringRunContext($admin, 'fallback-fresh-model');
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls, $admin, $secondary): string {
            $calls++;
            if ($calls === 1) {
                $updated = app(AdminAiModelMutationService::class)->update(
                    $admin,
                    (int) $secondary->id,
                    ['model_id' => 'workspace-fallback-fresh-secondary-v2'],
                    AiModel::ACCESS_SCOPE_USER_CONTENT,
                );
                self::assertTrue($updated->succeeded());
                throw $this->requestException(429, 'temporary primary failure');
            }

            return 'fresh secondary answer';
        })->preventStrayPrompts();

        self::assertSame('fresh secondary answer', app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $context));
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === 'workspace-fallback-fresh-secondary-v2',
        );
        AdminHelpAssistant::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === 'workspace-fallback-fresh-secondary',
        );
    }

    public function test_direct_answer_blocks_model_update_and_delete_during_the_provider_call(): void
    {
        $admin = $this->admin('workspace-direct-answer-model-lock', 'super_admin');
        $model = $this->model($admin, 'workspace-direct-answer-model');
        $updated = null;
        $deleted = null;
        AdminHelpAssistant::fake(function () use (&$updated, &$deleted, $admin, $model): string {
            $mutations = app(AdminAiModelMutationService::class);
            $updated = $mutations->update(
                $admin,
                (int) $model->id,
                ['name' => 'blocked during direct answer'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            $deleted = $mutations->delete($admin, (int) $model->id);

            return 'direct answer';
        })->preventStrayPrompts();

        self::assertSame('direct answer', app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin));
        self::assertFalse($updated->succeeded());
        self::assertFalse($deleted->succeeded());
        self::assertSame('task', $updated->error);
        self::assertSame('task', $deleted->error);
        self::assertTrue(app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'allowed after direct answer'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        )->succeeded());
    }

    public function test_direct_stream_discards_output_after_an_uncoordinated_model_configuration_change(): void
    {
        $admin = $this->admin('workspace-direct-stream-model-lock', 'super_admin');
        $model = $this->model($admin, 'workspace-direct-stream-model');
        $blocked = null;
        AdminHelpAssistant::fake(function () use (&$blocked, $admin, $model): string {
            $blocked = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                ['name' => 'blocked during direct stream'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );
            AiModel::query()->whereKey($model->id)->update([
                'api_url' => 'https://changed.example.invalid/v1',
            ]);

            return 'stale direct stream';
        })->preventStrayPrompts();

        $stream = app(AiWorkspaceModelRuntime::class)->stream('问题', '上下文', [], $admin);
        try {
            iterator_to_array($stream);
            self::fail('A changed direct-stream model configuration must invalidate the result.');
        } catch (AiModelAccessException $exception) {
            self::assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }
        self::assertFalse($blocked->succeeded());
        self::assertSame('task', $blocked->error);
    }

    public function test_direct_fallback_claim_builds_the_provider_from_the_latest_configuration(): void
    {
        $admin = $this->admin('workspace-direct-fallback-fresh', 'super_admin');
        $this->model($admin, 'workspace-direct-fallback-primary', 1);
        $secondary = $this->model($admin, 'workspace-direct-fallback-secondary', 2);
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls, $admin, $secondary): string {
            $calls++;
            if ($calls === 1) {
                $updated = app(AdminAiModelMutationService::class)->update(
                    $admin,
                    (int) $secondary->id,
                    ['model_id' => 'workspace-direct-fallback-secondary-v2'],
                    AiModel::ACCESS_SCOPE_USER_CONTENT,
                );
                self::assertTrue($updated->succeeded());
                throw $this->requestException(429, 'temporary primary failure');
            }

            return 'fresh direct fallback';
        })->preventStrayPrompts();

        self::assertSame('fresh direct fallback', app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin));
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === 'workspace-direct-fallback-secondary-v2',
        );
        AdminHelpAssistant::assertNotPrompted(
            static fn ($prompt): bool => $prompt->model === 'workspace-direct-fallback-secondary',
        );
    }

    public function test_model_call_renews_a_short_resolution_lease_for_the_outbound_budget(): void
    {
        Queue::fake();
        config()->set('ai-workspace.resolution_lease_minutes', 1);
        config()->set('ai-workspace.model_total_timeout_seconds', 180);
        $admin = $this->admin('workspace-model-call-renewal', 'super_admin');
        $this->model($admin, 'workspace-model-call-renewal-model');
        [, $context] = $this->answeringRunContext($admin, 'model-call-renewal');
        AdminHelpAssistant::fake(function (): string {
            $this->travel(2)->minutes();

            return 'long model answer';
        })->preventStrayPrompts();

        self::assertSame('long model answer', app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $context));
    }

    public function test_recovery_rotates_expired_execution_lease_and_rejects_the_old_worker_context(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-expired-execution-recovery', 'super_admin');
        $this->model($admin, 'workspace-expired-execution-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请恢复过期运行。',
        );
        $oldLease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'running',
            'execution_lease_token' => $oldLease,
            'execution_lease_expires_at' => now()->subMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $oldContext = $guard->contextFromExecutionRun($run->fresh(), $oldLease);

        $this->artisan('geoflow:recover-ai-workspace', ['--limit' => 10])
            ->expectsOutputToContain('Recovered AI workspace runs: 1')
            ->assertSuccessful();

        self::assertSame('queued', $run->fresh()->state);
        self::assertNull($run->fresh()->execution_lease_token);
        $this->expectException(AiWorkspaceRuntimeGuardException::class);
        $guard->assertCurrent($oldContext);
    }

    public function test_access_revoked_after_provider_return_discards_the_assistant_message_and_sanitizes_failure_state(): void
    {
        $admin = $this->admin('workspace-revoked-after-return', 'super_admin');
        $this->model($admin, 'workspace-revocation-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new RevokingWorkspaceResponder($admin));

        $stream = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请回答这个问题。'],
        )->assertOk()->streamedContent();

        self::assertStringContainsString('event: error', $stream);
        self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
        $user = AiConversationMessage::query()->where('role', 'user')->firstOrFail();
        self::assertSame('failed', $user->meta['workspace_generation_state']);
    }

    public function test_model_configuration_change_after_provider_return_discards_the_result(): void
    {
        $admin = $this->admin('workspace-model-changed', 'super_admin');
        $model = $this->model($admin, 'workspace-changing-model');
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls, $model): string {
            $calls++;
            $model->update(['api_url' => 'https://changed.example.invalid/v1']);

            return 'stale provider result';
        })->preventStrayPrompts();

        $this->expectException(AiModelAccessException::class);
        try {
            app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin);
        } finally {
            self::assertSame(1, $calls);
            self::assertSame(0, (int) $model->fresh()->total_used);
        }
    }

    public function test_stream_stops_before_broadcasting_a_delta_after_access_is_revoked(): void
    {
        $admin = $this->admin('workspace-mid-stream-revoked', 'super_admin');
        $model = $this->model($admin, 'workspace-mid-stream-revoked-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new ReceiptAwareWorkspaceResponder(
            app(AiWorkspaceExecutionAccessGuard::class),
            $model,
            static function () use ($admin): void {
                $admin->forceFill([
                    'ai_config_access_version' => (int) $admin->ai_config_access_version + 1,
                ])->save();
            },
        ));

        $stream = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请流式回答。'],
        )->assertOk()->streamedContent();

        self::assertSame(1, substr_count($stream, 'event: delta'));
        self::assertStringContainsString(json_encode('第一段', JSON_THROW_ON_ERROR), $stream);
        self::assertStringNotContainsString(json_encode('第二段', JSON_THROW_ON_ERROR), $stream);
        self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
    }

    public function test_stream_stops_before_broadcasting_a_delta_after_model_configuration_changes(): void
    {
        $admin = $this->admin('workspace-mid-stream-model-change', 'super_admin');
        $model = $this->model($admin, 'workspace-mid-stream-changing-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new ReceiptAwareWorkspaceResponder(
            app(AiWorkspaceExecutionAccessGuard::class),
            $model,
            static function () use ($model): void {
                $model->forceFill(['api_url' => 'https://changed.example.invalid/v1'])->save();
            },
        ));

        $stream = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请流式回答。'],
        )->assertOk()->streamedContent();

        self::assertSame(1, substr_count($stream, 'event: delta'));
        self::assertStringContainsString(json_encode('第一段', JSON_THROW_ON_ERROR), $stream);
        self::assertStringNotContainsString(json_encode('第二段', JSON_THROW_ON_ERROR), $stream);
        self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
    }

    public function test_final_message_transaction_rejects_a_changed_model_receipt(): void
    {
        $admin = $this->admin('workspace-final-receipt-race', 'super_admin');
        $model = $this->model($admin, 'workspace-final-receipt-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new ReceiptAwareWorkspaceResponder(
            app(AiWorkspaceExecutionAccessGuard::class),
            $model,
            static function () use ($model): void {
                $model->forceFill(['api_url' => 'https://changed.example.invalid/v1'])->save();
            },
            emitSecondDelta: false,
        ));

        $stream = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请生成最终回答。'],
        )->assertOk()->streamedContent();

        self::assertStringContainsString('event: error', $stream);
        self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
        self::assertSame('failed', AiConversationMessage::query()->where('role', 'user')->firstOrFail()->meta['workspace_generation_state']);
    }

    public function test_partial_answer_transaction_rechecks_the_model_receipt_before_each_write(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-partial-receipt', 'super_admin');
        $model = $this->model($admin, 'workspace-partial-receipt-model');
        $coordinator = app(AiWorkspaceCoordinator::class);
        $run = $coordinator->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请流式生成回答。',
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'answering',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $context = $guard->contextFromResolutionRun($run->fresh(), $lease);
        $receipt = $guard->receiptFor($context, $model);
        $persist = new \ReflectionMethod($coordinator, 'persistAndBroadcastAnswerChunk');
        $persist->setAccessible(true);

        $persist->invoke($coordinator, $context, $receipt, '第一段');
        $model->forceFill(['model_id' => 'workspace-partial-receipt-model-v2'])->save();

        try {
            $persist->invoke($coordinator, $context, $receipt, '第二段');
            self::fail('A changed model receipt must stop partial answer persistence.');
        } catch (AiModelAccessException) {
            self::assertSame('第一段', $run->fresh()->answer);
            self::assertSame(1, (int) $run->fresh()->answer_chunk_sequence);
        }
    }

    public function test_intent_persistence_transaction_rejects_a_changed_actual_model_receipt(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-intent-receipt-race', 'super_admin');
        $model = $this->model($admin, 'workspace-intent-receipt-model');
        $coordinator = app(AiWorkspaceCoordinator::class);
        $run = $coordinator->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请识别请求意图。',
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'received',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $context = $guard->contextFromResolutionRun($run->fresh(), $lease);
        $receipt = null;
        AdminHelpAssistant::fake([json_encode([
            'mode' => 'answer',
            'intent' => 'stale intent',
        ], JSON_THROW_ON_ERROR)])->preventStrayPrompts();
        app(AiWorkspaceModelRuntime::class)->resolveIntent(
            '请识别请求意图。',
            $context,
            static function (array $telemetry, mixed $resolved) use (&$receipt, $model): void {
                $receipt = $resolved;
                $model->forceFill(['api_url' => 'https://changed.example.invalid/v1'])->save();
            },
        );
        self::assertInstanceOf(AiWorkspaceModelExecutionReceipt::class, $receipt);
        $persist = new \ReflectionMethod($coordinator, 'updateResolutionOwned');
        $persist->setAccessible(true);

        try {
            $persist->invoke($coordinator, (string) $run->id, $lease, [
                'intent' => 'stale intent',
                'resolution_source' => 'model',
            ], null, $receipt);
            self::fail('A changed model receipt must stop intent persistence.');
        } catch (AiModelAccessException) {
            self::assertNull($run->fresh()->intent);
            self::assertNull($run->fresh()->resolution_source);
        }
    }

    public function test_plan_prepare_transaction_rejects_a_changed_actual_model_receipt(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-plan-receipt-race', 'super_admin');
        $model = $this->model($admin, 'workspace-plan-receipt-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请生成受控执行计划。',
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'planning',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $context = $guard->contextFromResolutionRun($run->fresh(), $lease);
        $receipt = null;
        AdminHelpAssistant::fake([json_encode(['steps' => [[
            'capability' => 'analytics.daily_report',
            'parameters' => [],
        ]]], JSON_THROW_ON_ERROR)])->preventStrayPrompts();
        $draft = app(AiWorkspaceModelRuntime::class)->draftPlan(
            '请生成受控执行计划。',
            ['intent' => '生成运营日报'],
            $context,
            static function (array $telemetry, mixed $resolved) use (&$receipt, $model): void {
                $receipt = $resolved;
                $model->forceFill(['model_id' => 'workspace-plan-receipt-model-v2'])->save();
            },
        );
        self::assertInstanceOf(AiWorkspaceModelExecutionReceipt::class, $receipt);
        $plan = app(AiPlanCompiler::class)->compile($admin, '生成运营日报', $draft);

        try {
            app(AiWorkflowEngine::class)->prepare($run->fresh(), $plan, $lease, $receipt);
            self::fail('A changed model receipt must stop plan persistence.');
        } catch (AiModelAccessException) {
            self::assertNull($run->fresh()->plan);
            self::assertSame(0, $run->steps()->count());
        }
    }

    public function test_missing_parameter_clarification_rejects_a_changed_plan_receipt(): void
    {
        [$coordinator, $run, $lease, $receipt, $model] = $this->planningReceiptFixture('missing-parameter');
        $model->forceFill(['api_url' => 'https://changed.example.invalid/v1'])->save();
        $clarify = new \ReflectionMethod($coordinator, 'clarify');
        $clarify->setAccessible(true);

        try {
            $clarify->invoke(
                $coordinator,
                $run->modelAccessAdmin,
                $run,
                ['title'],
                [],
                $lease,
                $receipt,
            );
            self::fail('A changed plan receipt must stop missing-parameter clarification.');
        } catch (AiModelAccessException) {
            self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
            self::assertSame('planning', $run->fresh()->state);
        }
    }

    public function test_compilation_failure_clarification_rejects_a_changed_plan_receipt(): void
    {
        [$coordinator, $run, $lease, $receipt, $model] = $this->planningReceiptFixture('compile-failure');
        $model->forceFill(['model_id' => 'workspace-compile-failure-model-v2'])->save();
        $clarify = new \ReflectionMethod($coordinator, 'clarify');
        $clarify->setAccessible(true);

        try {
            $clarify->invoke(
                $coordinator,
                $run->modelAccessAdmin,
                $run,
                [],
                ['目标对象不存在或已经变化，请补充有效对象。'],
                $lease,
                $receipt,
            );
            self::fail('A changed plan receipt must stop compilation-failure clarification.');
        } catch (AiModelAccessException) {
            self::assertSame(0, AiConversationMessage::query()->where('role', 'assistant')->count());
            self::assertSame('planning', $run->fresh()->state);
        }
    }

    public function test_verified_readiness_fails_closed_on_permanent_personal_failure_before_shared_model(): void
    {
        config()->set('ai-workspace.require_verified_model', true);
        $provider = $this->admin('workspace-readiness-provider', 'super_admin');
        $admin = $this->admin('workspace-readiness-actor', 'admin', $provider);
        $personal = $this->model($admin, 'workspace-readiness-personal', 1);
        $shared = $this->model($provider, 'workspace-readiness-shared', 2);
        $personal->forceFill([
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_failure_code' => 'authentication_failed',
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ])->save();
        $shared->forceFill([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'configuration' => [
                    'fingerprint' => app(AiWorkspaceModelReadiness::class)->configurationFingerprint($shared),
                ],
                'plain_text' => ['status' => 'ready'],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ])->save();

        $status = app(AiWorkspaceModelReadiness::class)->status($admin);

        self::assertFalse($status['ready']);
        self::assertNull($status['model_id']);
        AdminHelpAssistant::fake(['must not call shared'])->preventStrayPrompts();
        try {
            app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin);
            self::fail('Permanent readiness rejection must block the shared candidate.');
        } catch (PermanentAiProviderException) {
            AdminHelpAssistant::assertNotPrompted(static fn (): bool => true);
        }
    }

    public function test_verified_readiness_can_use_shared_after_a_temporary_personal_failure(): void
    {
        config()->set('ai-workspace.require_verified_model', true);
        $provider = $this->admin('workspace-temporary-readiness-provider', 'super_admin');
        $admin = $this->admin('workspace-temporary-readiness-actor', 'admin', $provider);
        $personal = $this->model($admin, 'workspace-temporary-readiness-personal', 1);
        $shared = $this->model($provider, 'workspace-temporary-readiness-shared', 2);
        $personal->forceFill([
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_failure_code' => 'provider_timeout',
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ])->save();
        $shared->forceFill([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'configuration' => [
                    'fingerprint' => app(AiWorkspaceModelReadiness::class)->configurationFingerprint($shared),
                ],
                'plain_text' => ['status' => 'ready'],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ])->save();

        self::assertSame([
            'ready' => true,
            'reason' => null,
            'model_id' => (int) $shared->id,
        ], app(AiWorkspaceModelReadiness::class)->status($admin));
    }

    public function test_provider_metadata_is_reduced_to_the_safe_workspace_allowlist(): void
    {
        $admin = $this->admin('workspace-safe-meta', 'super_admin');
        $this->model($admin, 'workspace-safe-meta-model');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new WorkspaceMetadataResponder);

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '请回答这个问题。'],
        )->assertOk()->streamedContent();

        $meta = AiConversationMessage::query()->where('role', 'assistant')->firstOrFail()->meta;
        self::assertSame('safe-model', data_get($meta, 'generation.model'));
        self::assertArrayNotHasKey('api_key', (array) data_get($meta, 'generation'));
        self::assertArrayNotHasKey('base_url', (array) data_get($meta, 'generation'));
        self::assertArrayNotHasKey('note', (array) data_get($meta, 'generation'));
        self::assertStringNotContainsString('secret-token', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    public function test_historical_run_without_complete_ai_identity_fails_closed_before_any_model_call(): void
    {
        AdminHelpAssistant::fake()->preventStrayPrompts();
        $admin = $this->admin('workspace-legacy-owner', 'super_admin');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $run = AiWorkspaceRun::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'admin_auth_version' => $admin->auth_version,
            'mode' => 'workflow',
            'state' => 'received',
            'prompt' => '历史任务',
            'risk_level' => 'low',
        ]);

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        self::assertSame('failed', $run->fresh()->state);
        self::assertSame('authorization_revoked', $run->fresh()->failure_code);
        self::assertFalse((bool) $run->fresh()->retryable_failure);
        AdminHelpAssistant::assertNotPrompted(static fn (): bool => true);
    }

    public function test_old_resolution_context_is_rejected_after_recovery_rotates_the_lease(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-old-resolution-worker', 'super_admin');
        $model = $this->model($admin, 'workspace-resolution-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请分析当前状态。',
        );
        $oldLease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'received',
            'resolution_lease_owner' => $oldLease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $context = $guard->contextFromResolutionRun($run->fresh(), $oldLease);
        $run->forceFill([
            'resolution_lease_owner' => (string) Str::uuid7(),
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();

        try {
            $guard->recordResolvedModel($context, $model);
            self::fail('The stale resolution worker must lose its commit lease.');
        } catch (AiWorkspaceRuntimeGuardException $exception) {
            self::assertSame('AI 工作台执行状态或租约已经变化。', $exception->getMessage());
        }

        self::assertNull($run->fresh()->resolved_ai_model_id);
    }

    public function test_resolution_context_is_rejected_after_the_run_leaves_a_resolution_state(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-resolution-state', 'super_admin');
        $model = $this->model($admin, 'workspace-resolution-state-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请分析当前状态。',
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'received',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $guard = app(AiWorkspaceExecutionAccessGuard::class);
        $context = $guard->contextFromResolutionRun($run->fresh(), $lease);
        $run->forceFill(['state' => 'failed'])->save();

        $this->expectException(AiWorkspaceRuntimeGuardException::class);
        try {
            $guard->recordResolvedModel($context, $model);
        } finally {
            self::assertNull($run->fresh()->resolved_ai_model_id);
        }
    }

    public function test_permanent_provider_rejection_does_not_fail_over_and_transient_rejection_can_fail_over(): void
    {
        $admin = $this->admin('workspace-provider-errors', 'super_admin');
        $primary = $this->model($admin, 'workspace-provider-primary', 1);
        $this->model($admin, 'workspace-provider-fallback', 2);
        foreach ([400, 401, 402, 403, 422] as $status) {
            Cache::flush();
            $calls = 0;
            AdminHelpAssistant::fake(function () use (&$calls, $status): string {
                $calls++;
                throw $this->requestException($status, 'invalid credential secret-provider-key');
            })->preventStrayPrompts();

            try {
                app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin);
                self::fail('Permanent provider rejection must stop the model chain.');
            } catch (PermanentAiProviderException $exception) {
                self::assertSame(PermanentAiProviderException::ERROR_CODE, $exception->getErrorCode());
            }
            self::assertSame(1, $calls, 'HTTP '.$status.' must not fail over.');
        }

        foreach ([408, 425, 429, 500, 503] as $status) {
            Cache::flush();
            $calls = 0;
            AdminHelpAssistant::fake(function () use (&$calls, $status): string {
                $calls++;
                if ($calls === 1) {
                    throw $this->requestException($status, 'temporary provider failure');
                }

                return 'fallback answer';
            })->preventStrayPrompts();

            self::assertSame(
                'fallback answer',
                app(AiWorkspaceModelRuntime::class)->answer('问题', '上下文', [], $admin),
            );
            self::assertSame(2, $calls, 'HTTP '.$status.' should fail over once.');
        }
        self::assertSame(0, (int) $primary->fresh()->total_used);
    }

    public function test_permanent_provider_rejection_terminalizes_a_resolution_run_without_retry(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-permanent-resolution', 'super_admin');
        $this->model($admin, 'workspace-permanent-resolution-primary', 1);
        $this->model($admin, 'workspace-permanent-resolution-fallback', 2);
        $calls = 0;
        AdminHelpAssistant::fake(function () use (&$calls): string {
            $calls++;
            throw $this->requestException(403, 'Bearer secret-provider-token');
        })->preventStrayPrompts();
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请给出完整的后台运营改进建议。',
        );

        app(AiWorkspaceCoordinator::class)->resolveRun((string) $run->id, (string) Str::uuid7());

        $run->refresh();
        self::assertSame('failed', $run->state);
        self::assertSame(PermanentAiProviderException::ERROR_CODE, $run->failure_code);
        self::assertFalse((bool) $run->retryable_failure);
        self::assertSame(1, $calls);
        self::assertNull($run->resolution_lease_owner);
        self::assertStringNotContainsString('secret-provider-token', (string) $run->failure_message);
    }

    public function test_queued_workflow_with_missing_identity_is_permanently_failed_and_clears_execution_lease(): void
    {
        $admin = $this->admin('workspace-legacy-queued', 'super_admin');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $run = AiWorkspaceRun::query()->create([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversation->id,
            'admin_id' => $admin->id,
            'admin_username_snapshot' => $admin->username,
            'admin_auth_version' => $admin->auth_version,
            'mode' => 'workflow',
            'state' => 'queued',
            'prompt' => '历史队列任务',
            'risk_level' => 'low',
        ]);

        app(AiWorkflowEngine::class)->process((string) $run->id, (string) Str::uuid7());

        $run->refresh();
        self::assertSame('failed', $run->state);
        self::assertSame('authorization_revoked', $run->failure_code);
        self::assertFalse((bool) $run->retryable_failure);
        self::assertNull($run->execution_lease_token);
    }

    public function test_active_workspace_run_blocks_model_mutation_until_the_run_is_terminal(): void
    {
        Queue::fake();
        $admin = $this->admin('workspace-model-dependency', 'super_admin');
        $model = $this->model($admin, 'workspace-model-dependency-model');
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请生成一份运营分析。',
        );

        $updated = app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'mutated workspace model'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        );
        $deleted = app(AdminAiModelMutationService::class)->delete($admin, (int) $model->id);

        self::assertFalse($updated->succeeded());
        self::assertSame('task', $updated->error);
        self::assertFalse($deleted->succeeded());
        self::assertSame('task', $deleted->error);

        $run->forceFill([
            'state' => 'failed',
            'retryable_failure' => false,
        ])->save();
        $updated = app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'terminal workspace model'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        );
        self::assertTrue($updated->succeeded());
    }

    public function test_workspace_identity_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path(
            'migrations/2026_09_02_160000_add_ai_execution_identity_to_ai_workspace_runs_table.php',
        );

        try {
            $migration->down();
            self::assertFalse(Schema::hasColumn('ai_workspace_runs', 'model_access_admin_id'));
            self::assertFalse(Schema::hasColumn('ai_workspace_runs', 'execution_lease_token'));
            self::assertFalse(Schema::hasColumn('ai_workspace_runs', 'retryable_failure'));

            $migration->up();
            self::assertTrue(Schema::hasColumns('ai_workspace_runs', [
                'model_access_admin_id',
                'model_access_admin_role',
                'ai_config_access_version',
                'requested_ai_model_id',
                'resolved_ai_model_id',
                'resolved_model_source',
                'model_resolved_at',
                'resolver_policy_version',
                'execution_lease_token',
                'execution_lease_expires_at',
                'retryable_failure',
            ]));
        } finally {
            if (! Schema::hasColumn('ai_workspace_runs', 'model_access_admin_id')) {
                $migration->up();
            }
        }
    }

    private function admin(string $username, string $role, ?Admin $sharedProvider = null): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
        if ($sharedProvider instanceof Admin) {
            $admin->forceFill([
                'shared_ai_config_owner_id' => $sharedProvider->id,
                'ai_config_access_version' => 1,
            ])->save();
        }

        return $admin->fresh();
    }

    private function model(
        Admin $owner,
        string $modelId,
        int $priority = 10,
        string $scope = AiModel::ACCESS_SCOPE_USER_CONTENT,
    ): AiModel {
        $model = AiModel::query()->create([
            'name' => $modelId,
            'version' => '1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('secret-'.$modelId),
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://example.invalid/v1',
            'status' => 'active',
            'failover_priority' => $priority,
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $scope,
        ])->save();

        return $model->fresh();
    }

    private function requestException(int $status, string $message): RequestException
    {
        return new RequestException(new ClientResponse(new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode(['error' => ['message' => $message]], JSON_THROW_ON_ERROR),
        )));
    }

    /** @return array{AiWorkspaceCoordinator,AiWorkspaceRun,string,AiWorkspaceModelExecutionReceipt,AiModel} */
    private function planningReceiptFixture(string $suffix): array
    {
        Queue::fake();
        $admin = $this->admin('workspace-'.$suffix, 'super_admin');
        $model = $this->model($admin, 'workspace-'.$suffix.'-model');
        $coordinator = app(AiWorkspaceCoordinator::class);
        $run = $coordinator->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请生成受控计划。',
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'planning',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();
        $receipt = app(AiWorkspaceExecutionAccessGuard::class)->receiptFor(
            app(AiWorkspaceExecutionAccessGuard::class)->contextFromResolutionRun($run->fresh(), $lease),
            $model,
        );

        return [$coordinator, $run->fresh(['modelAccessAdmin']), $lease, $receipt, $model];
    }

    /** @return array{AiWorkspaceRun,AiWorkspaceExecutionContext} */
    private function answeringRunContext(Admin $admin, string $suffix): array
    {
        $run = app(AiWorkspaceCoordinator::class)->createRun(
            $admin,
            app(AiConversationRepository::class)->create($admin),
            '请执行模型回答：'.$suffix,
        );
        $lease = (string) Str::uuid7();
        $run->forceFill([
            'state' => 'answering',
            'resolution_lease_owner' => $lease,
            'resolution_lease_expires_at' => now()->addMinute(),
        ])->save();

        return [
            $run,
            app(AiWorkspaceExecutionAccessGuard::class)->contextFromResolutionRun($run->fresh(), $lease),
        ];
    }
}

final class RevokingWorkspaceResponder implements AdminHelpResponder
{
    public function __construct(private readonly Admin $admin) {}

    public function stream(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): Generator
    {
        yield ['type' => 'delta', 'content' => '供应商已返回'];
        $this->admin->forceFill([
            'ai_config_access_version' => (int) $this->admin->ai_config_access_version + 1,
        ])->save();

        return ['answer' => '供应商已返回', 'meta' => [], 'usage' => []];
    }

    public function answer(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): string
    {
        return '供应商已返回';
    }
}

final class WorkspaceMetadataResponder implements AdminHelpResponder
{
    public function stream(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): Generator
    {
        self::assertContext($actor);
        yield ['type' => 'delta', 'content' => '安全回答'];

        return [
            'answer' => '安全回答',
            'meta' => [
                'model' => 'safe-model',
                'provider' => 'https://provider.example/v1?token=secret-token',
                'api_key' => 'secret-token',
                'base_url' => 'https://provider.example/v1?token=secret-token',
                'note' => ['authorization' => 'Bearer secret-token'],
            ],
            'usage' => [],
        ];
    }

    public function answer(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): string
    {
        self::assertContext($actor);

        return '安全回答';
    }

    private static function assertContext(mixed $actor): void
    {
        if (! $actor instanceof AiWorkspaceExecutionContext) {
            throw new \RuntimeException('workspace execution context missing');
        }
    }
}

final class ReceiptAwareWorkspaceResponder implements AdminHelpResponder
{
    /** @param \Closure():void $afterFirstDelta */
    public function __construct(
        private readonly AiWorkspaceExecutionAccessGuard $guard,
        private readonly AiModel $model,
        private readonly \Closure $afterFirstDelta,
        private readonly bool $emitSecondDelta = true,
    ) {}

    public function stream(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): Generator
    {
        if (! $actor instanceof AiWorkspaceExecutionContext) {
            throw new \RuntimeException('workspace execution context missing');
        }
        $receipt = $this->guard->receiptFor($actor, $this->model);
        yield ['type' => 'delta', 'content' => '第一段', 'completion_receipt' => $receipt];
        ($this->afterFirstDelta)();
        if ($this->emitSecondDelta) {
            yield ['type' => 'delta', 'content' => '第二段', 'completion_receipt' => $receipt];
        }

        return [
            'answer' => $this->emitSecondDelta ? '第一段第二段' : '第一段',
            'meta' => [],
            'usage' => [],
            'completion_receipt' => $receipt,
        ];
    }

    public function answer(string $prompt, string $knowledgeContext, iterable $messages = [], mixed $actor = null): string
    {
        return '第一段';
    }
}
