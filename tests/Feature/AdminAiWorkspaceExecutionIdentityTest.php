<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiConversationMessage;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use App\Services\AiWorkspace\AiWorkspaceExecutionAccessGuard;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Support\GeoFlow\ApiKeyCrypto;
use Generator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
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
        } catch (AiModelAccessException $exception) {
            self::assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
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

        $this->expectException(AiModelAccessException::class);
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
