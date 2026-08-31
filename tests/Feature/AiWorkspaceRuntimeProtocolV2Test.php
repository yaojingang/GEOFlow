<?php

namespace Tests\Feature;

use App\Ai\Agents\AdminHelpAssistant;
use App\Contracts\AiWorkspace\AdminHelpResponder;
use App\Models\Admin;
use App\Models\AiConversationMessage;
use App\Models\AiModel;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Services\AiWorkspace\AiWorkspaceModelRuntime;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Generator;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\Support\InterruptedStreamingFakeTextGateway;
use Tests\TestCase;

final class AiWorkspaceRuntimeProtocolV2Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.admin_ui_v3_enabled', true);
        config()->set('ai-workspace.runtime_enabled', true);
        config()->set('ai-workspace.require_verified_model', true);
    }

    public function test_plain_text_readiness_accepts_a_degraded_streaming_profile(): void
    {
        $model = $this->model([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'plain_text' => ['status' => 'ready'],
                'streaming' => ['status' => 'degraded', 'fallback' => 'non_streaming'],
                'structured_output' => ['status' => 'not_required'],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
            'ai_workspace_structured_output_status' => null,
        ]);

        self::assertTrue(app(AiWorkspaceModelReadiness::class)->status()['ready']);
        self::assertSame($model->id, app(AiWorkspaceModelReadiness::class)->status()['model_id']);
    }

    public function test_unverified_chat_model_is_skipped_when_verification_is_required(): void
    {
        $model = $this->model([
            'failover_priority' => 1,
            'ai_workspace_readiness_status' => null,
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);
        $fallback = $this->model([
            'name' => 'Verified Fallback Model',
            'model_id' => 'verified-fallback-model',
            'failover_priority' => 2,
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => ['plain_text' => ['status' => 'ready']],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);

        $status = app(AiWorkspaceModelReadiness::class)->status();

        self::assertTrue($status['ready']);
        self::assertSame($fallback->id, $status['model_id']);
    }

    public function test_stale_runtime_success_cannot_reactivate_a_changed_model_endpoint(): void
    {
        $model = $this->model([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => ['plain_text' => ['status' => 'ready']],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);
        $requestSnapshot = $model->fresh();

        $model->update(['api_url' => 'https://attacker.example/v1']);
        app(AiWorkspaceModelReadiness::class)->recordRuntimeSuccess($requestSnapshot, true);
        $model->refresh();

        self::assertSame('stale', $model->ai_workspace_readiness_status);
        self::assertNull($model->ai_workspace_readiness_profile);
        self::assertFalse(app(AiWorkspaceModelReadiness::class)->canAttempt($model));
    }

    public function test_first_successful_answer_marks_an_unverified_model_ready(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['后台帮助回答。'])->preventStrayPrompts();
        $model = $this->model([
            'ai_workspace_readiness_status' => null,
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。');
        $model->refresh();

        self::assertSame('后台帮助回答。', $answer);
        self::assertSame('ready', $model->ai_workspace_readiness_status);
        self::assertSame('ready', data_get($model->ai_workspace_readiness_profile, 'plain_text.status'));
        self::assertSame('unknown', data_get($model->ai_workspace_readiness_profile, 'streaming.status'));
        self::assertFalse(data_get($model->ai_workspace_readiness_profile, 'streaming.observed'));
        self::assertTrue($model->ai_workspace_readiness_expires_at->isFuture());
    }

    public function test_runtime_success_preserves_atomic_fact_structured_output_readiness(): void
    {
        $model = $this->model([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'plain_text' => ['status' => 'ready'],
                'knowledge_fact_structured_output' => ['status' => 'ready', 'observed' => true],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);

        app(AiWorkspaceModelReadiness::class)->recordRuntimeSuccess($model->fresh(), false);

        self::assertSame('ready', data_get($model->fresh()->ai_workspace_readiness_profile, 'knowledge_fact_structured_output.status'));
    }

    public function test_first_successful_stream_marks_streaming_ready(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['流式后台帮助回答。'])->preventStrayPrompts();
        $model = $this->model([
            'ai_workspace_readiness_status' => null,
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);

        $stream = app(AiWorkspaceModelRuntime::class)->stream('文章在哪里？', '文章管理帮助。');
        $events = iterator_to_array($stream);
        $answer = collect($events)
            ->where('type', 'delta')
            ->pluck('content')
            ->implode('');
        $result = $stream->getReturn();
        $model->refresh();

        self::assertSame('流式后台帮助回答。', $answer);
        self::assertSame('流式后台帮助回答。', $result['answer']);
        self::assertSame(1, $result['meta']['attempts']);
        self::assertSame('openai-compatible', $result['meta']['provider']);
        self::assertFalse(collect($events)->contains(
            static fn (array $event): bool => str_starts_with((string) ($event['provider'] ?? ''), 'runtime_'),
        ));
        self::assertArrayHasKey('ttft_ms', $result['meta']);
        self::assertSame('ready', $model->ai_workspace_readiness_status);
        self::assertSame('ready', data_get($model->ai_workspace_readiness_profile, 'streaming.status'));
        self::assertTrue(data_get($model->ai_workspace_readiness_profile, 'streaming.observed'));
    }

    public function test_unobserved_degraded_profile_attempts_real_streaming_and_recovers_to_ready(): void
    {
        AdminHelpAssistant::fake(['流式恢复回答。'])->preventStrayPrompts();
        $model = $this->model([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'plain_text' => ['status' => 'ready', 'observed' => true],
                'streaming' => ['status' => 'degraded', 'observed' => false, 'fallback' => 'non_streaming'],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);

        $stream = app(AiWorkspaceModelRuntime::class)->stream('任务在哪里？', '任务管理帮助。');
        $events = iterator_to_array($stream);
        $result = $stream->getReturn();
        $model->refresh();

        self::assertSame('流式恢复回答。', collect($events)->where('type', 'delta')->pluck('content')->implode(''));
        self::assertSame(0, $result['meta']['fallback_count']);
        self::assertSame(0, $result['meta']['degraded_count']);
        self::assertSame('ready', data_get($model->ai_workspace_readiness_profile, 'streaming.status'));
        self::assertTrue(data_get($model->ai_workspace_readiness_profile, 'streaming.observed'));
    }

    public function test_incomplete_stream_is_not_persisted_or_marked_ready(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('ai-workspace.model_attempt_timeout_seconds', 7);
        $gateway = InterruptedStreamingFakeTextGateway::install(AdminHelpAssistant::class, 'error_end');
        $model = $this->model([
            'ai_workspace_readiness_status' => null,
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);

        $stream = app(AiWorkspaceModelRuntime::class)->stream('任务在哪里？', '任务管理帮助。');
        $failed = false;

        try {
            iterator_to_array($stream);
        } catch (RuntimeException $exception) {
            $failed = true;
            self::assertStringContainsString('流式响应未正常完成', $exception->getMessage());
        }

        self::assertTrue($failed, 'Incomplete stream should fail instead of persisting a partial answer.');
        $model->refresh();
        self::assertNull($model->ai_workspace_readiness_status);
        self::assertNull($model->ai_workspace_readiness_profile);
        self::assertSame(7, $gateway->streamTimeout);
    }

    public function test_readiness_metadata_write_failure_does_not_discard_a_successful_answer(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['回答仍然可用。'])->preventStrayPrompts();
        $this->model([
            'ai_workspace_readiness_status' => null,
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);
        AiModel::saving(static function (AiModel $model): void {
            if ($model->isDirty('ai_workspace_readiness_status')) {
                throw new RuntimeException('readiness persistence unavailable');
            }
        });

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。');

        self::assertSame('回答仍然可用。', $answer);
    }

    public function test_explicit_degraded_streaming_profile_uses_one_plain_text_fallback_inside_the_shared_budget(): void
    {
        AdminHelpAssistant::fake(['普通文本降级回答。'])->preventStrayPrompts();
        $model = $this->model([
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'plain_text' => ['status' => 'ready'],
                'streaming' => ['status' => 'degraded', 'observed' => true, 'fallback' => 'non_streaming'],
            ],
            'ai_workspace_readiness_expires_at' => now()->addDay(),
        ]);

        $stream = app(AiWorkspaceModelRuntime::class)->stream('任务在哪里？', '任务管理帮助。');
        $events = iterator_to_array($stream);
        $result = $stream->getReturn();

        self::assertSame('普通文本降级回答。', collect($events)->where('type', 'delta')->pluck('content')->implode(''));
        self::assertSame(1, $result['meta']['attempts']);
        self::assertSame(1, $result['meta']['fallback_count']);
        self::assertSame(1, $result['meta']['degraded_count']);
        self::assertSame('普通文本降级回答。', $result['answer']);
        self::assertSame('degraded', data_get($model->fresh()->ai_workspace_readiness_profile, 'streaming.status'));
        self::assertTrue(data_get($model->fresh()->ai_workspace_readiness_profile, 'streaming.observed'));
    }

    public function test_structured_output_status_does_not_replace_plain_text_readiness(): void
    {
        $this->model([
            'ai_workspace_structured_output_status' => 'ready',
            'ai_workspace_structured_output_verified_at' => now(),
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_expires_at' => null,
        ]);

        $status = app(AiWorkspaceModelReadiness::class)->status();
        self::assertFalse($status['ready']);
        self::assertStringContainsString('普通文本检测', $status['reason']);
    }

    public function test_readiness_failure_reason_uses_the_active_locale(): void
    {
        app()->setLocale('en');
        config()->set('ai.conversations.connection', 'separate-ai-database');

        $status = app(AiWorkspaceModelReadiness::class)->status();

        self::assertFalse($status['ready']);
        self::assertSame(__('admin.ai_workspace.readiness_database_mismatch'), $status['reason']);
        self::assertStringNotContainsString('必须使用同一数据库连接', (string) $status['reason']);
    }

    public function test_admin_budget_rejection_does_not_quarantine_a_healthy_primary_model(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        config()->set('ai-workspace.admin_daily_model_calls', 1);
        AdminHelpAssistant::fake(['主模型回答。'])->preventStrayPrompts();
        $primary = $this->model([
            'name' => 'Primary Model',
            'model_id' => 'primary-model',
            'failover_priority' => 1,
        ]);
        $this->model([
            'name' => 'Fallback Model',
            'model_id' => 'fallback-model',
            'failover_priority' => 2,
        ]);
        $blockedAdminId = 987654;
        $availableAdminId = 987655;
        RateLimiter::hit(
            'ai-workspace:model-budget:'.$blockedAdminId.':'.now()->toDateString(),
            3600,
        );

        try {
            app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。', [], $blockedAdminId);
            self::fail('The exhausted administrator budget should reject the request.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('今日', $exception->getMessage());
        }

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。', [], $availableAdminId);

        self::assertSame('主模型回答。', $answer);
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $primary->model_id,
        );
    }

    public function test_exhausted_model_quota_falls_back_without_opening_the_provider_circuit(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['备用模型回答。'])->preventStrayPrompts();
        $primary = $this->model([
            'name' => 'Exhausted Primary Model',
            'model_id' => 'exhausted-primary',
            'failover_priority' => 1,
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);
        $fallback = $this->model([
            'name' => 'Available Fallback Model',
            'model_id' => 'available-fallback',
            'failover_priority' => 2,
        ]);

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。');
        $fingerprint = hash('sha256', implode('|', [
            (string) $primary->id,
            (string) $primary->model_id,
            OpenAiRuntimeProvider::resolveChatBaseUrl((string) $primary->api_url),
        ]));

        self::assertSame('备用模型回答。', $answer);
        self::assertFalse(Cache::has('ai-workspace:provider-circuit:'.$fingerprint));
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $fallback->model_id,
        );
    }

    public function test_incomplete_model_configuration_falls_back_without_opening_the_provider_circuit(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['配置完整的备用模型回答。'])->preventStrayPrompts();
        $primary = $this->model([
            'name' => 'Incomplete Primary Model',
            'model_id' => 'incomplete-primary',
            'api_url' => '',
            'failover_priority' => 1,
        ]);
        $fallback = $this->model([
            'name' => 'Configured Fallback Model',
            'model_id' => 'configured-fallback',
            'failover_priority' => 2,
        ]);

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。');
        $fingerprint = hash('sha256', implode('|', [
            (string) $primary->id,
            (string) $primary->model_id,
            OpenAiRuntimeProvider::resolveChatBaseUrl((string) $primary->api_url),
        ]));

        self::assertSame('配置完整的备用模型回答。', $answer);
        self::assertFalse(Cache::has('ai-workspace:provider-circuit:'.$fingerprint));
        AdminHelpAssistant::assertPrompted(
            static fn ($prompt): bool => $prompt->model === (string) $fallback->model_id,
        );
    }

    public function test_empty_plain_text_response_fails_over_to_the_next_model(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['', '备用模型回答。'])->preventStrayPrompts();
        $primary = $this->model(['name' => 'Primary Help Model', 'failover_priority' => 1]);
        $fallback = $this->model(['name' => 'Fallback Help Model', 'model_id' => 'fallback-help-model', 'failover_priority' => 2]);

        $answer = app(AiWorkspaceModelRuntime::class)->answer('任务在哪里？', '任务管理帮助。');

        self::assertSame('备用模型回答。', $answer);
        self::assertSame(0, (int) $primary->fresh()->total_used);
        self::assertSame(1, (int) $fallback->fresh()->total_used);
    }

    public function test_empty_stream_fails_over_to_the_next_model_before_any_text_is_emitted(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        AdminHelpAssistant::fake(['', '备用流式模型回答。'])->preventStrayPrompts();
        $primary = $this->model(['name' => 'Primary Streaming Model', 'failover_priority' => 1]);
        $fallback = $this->model([
            'name' => 'Fallback Streaming Model',
            'model_id' => 'fallback-streaming-model',
            'failover_priority' => 2,
        ]);

        $stream = app(AiWorkspaceModelRuntime::class)->stream('任务在哪里？', '任务管理帮助。');
        $events = iterator_to_array($stream);
        $result = $stream->getReturn();

        self::assertSame('备用流式模型回答。', collect($events)->where('type', 'delta')->pluck('content')->implode(''));
        self::assertSame('备用流式模型回答。', $result['answer']);
        self::assertSame(2, $result['meta']['attempts']);
        self::assertSame(1, $result['meta']['fallback_count']);
        self::assertSame(0, (int) $primary->fresh()->total_used);
        self::assertSame(1, (int) $fallback->fresh()->total_used);
    }

    public function test_sse_response_disables_proxy_buffering_and_uses_named_events(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        $this->model();
        $admin = $this->admin('protocol-owner');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->app->instance(AdminHelpResponder::class, new ProtocolFakeResponder(['回答']));

        $response = $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '任务在哪里？'],
        );

        $response->assertOk()
            ->assertHeader('X-Accel-Buffering', 'no')
            ->assertHeader('Cache-Control', 'no-cache, private');
        $stream = $response->streamedContent();
        self::assertSame(1, substr_count($stream, 'event: status'));
        self::assertSame(1, substr_count($stream, 'event: delta'));
        self::assertSame(1, substr_count($stream, 'event: done'));
        self::assertStringNotContainsString('event: update', $stream);
    }

    public function test_history_replays_persisted_features_and_suggestions_without_regeneration(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        $this->model();
        $admin = $this->admin('history-owner');
        $conversation = app(AiConversationRepository::class)->create($admin);
        $fake = new ProtocolFakeResponder(['请打开任务管理。']);
        $this->app->instance(AdminHelpResponder::class, $fake);

        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '怎样创建任务？'],
        )->streamedContent();

        $assistant = AiConversationMessage::query()->where('role', 'assistant')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.show', ['conversation' => $conversation->id]))
            ->assertOk()
            ->assertJsonPath('data.messages.1.id', (string) $assistant->id)
            ->assertJsonPath('data.messages.1.meta.related_features.0.id', 'tasks')
            ->assertJsonCount(3, 'data.messages.1.meta.suggestions');

        self::assertSame(1, $fake->streamCalls);
    }

    public function test_message_generation_has_a_separate_per_admin_rate_limit(): void
    {
        config()->set('ai-workspace.require_verified_model', false);
        $this->model();
        $admin = $this->admin('throttle-owner');
        $this->app->instance(AdminHelpResponder::class, new ProtocolFakeResponder(['回答']));

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $conversation = app(AiConversationRepository::class)->create($admin);
            $this->actingAs($admin, 'admin')->postJson(
                route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
                ['prompt' => '第 '.$attempt.' 个问题'],
            )->assertOk()->streamedContent();
        }

        $conversation = app(AiConversationRepository::class)->create($admin);
        $this->actingAs($admin, 'admin')->postJson(
            route('admin.ai-workspace.messages.store', ['conversation' => $conversation->id]),
            ['prompt' => '第七个问题'],
        )->assertTooManyRequests();

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.ai-workspace.conversations.index'))
            ->assertOk();
    }

    /** @param array<string, mixed> $attributes */
    private function model(array $attributes = []): AiModel
    {
        $model = AiModel::query()->create($attributes + [
            'name' => 'Help Model',
            'version' => '1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('unused'),
            'model_id' => 'help-model',
            'model_type' => 'chat',
            'api_url' => 'https://example.invalid/v1',
            'status' => 'active',
        ]);
        if (is_array($model->ai_workspace_readiness_profile)) {
            $profile = $model->ai_workspace_readiness_profile;
            data_set($profile, 'configuration.fingerprint', app(AiWorkspaceModelReadiness::class)->configurationFingerprint($model));
            $model->forceFill(['ai_workspace_readiness_profile' => $profile])->save();
        }

        return $model;
    }

    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}

final class ProtocolFakeResponder implements AdminHelpResponder
{
    public int $streamCalls = 0;

    /** @param list<string> $deltas */
    public function __construct(private readonly array $deltas) {}

    public function stream(string $prompt, string $knowledgeContext, iterable $messages = [], ?int $adminId = null): Generator
    {
        $this->streamCalls++;
        $answer = '';
        foreach ($this->deltas as $delta) {
            $answer .= $delta;
            yield ['type' => 'delta', 'content' => $delta];
        }

        return ['answer' => $answer, 'meta' => [], 'usage' => []];
    }

    public function answer(string $prompt, string $knowledgeContext, iterable $messages = [], ?int $adminId = null): string
    {
        return implode('', $this->deltas);
    }
}
