<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use App\Services\GeoFlow\AiVisibility\AiVisibilityConfigurationResolver;
use App\Services\GeoFlow\AiVisibility\AiVisibilityService;
use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use App\Services\GeoFlow\AiVisibility\DeepSeekAnalysisClient;
use App\Services\GeoFlow\AiVisibility\DoubaoArkResponsesClient;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class AiVisibilitySystemModelUsageTelemetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_visibility_analytics_identity_is_rejected_before_run_or_outbound_work(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $owner = $this->superAdmin('visibility-wrong-identity-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        foreach ([
            SystemAiIdentity::forVisibilityAnalytics(),
            SystemAiIdentity::knowledgeIndex(),
        ] as $wrongIdentity) {
            try {
                app(AiVisibilityCollectionService::class)->collect(
                    $wrongIdentity,
                    'private-keyword',
                );
                $this->fail('An unrelated system identity must not collect visibility.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('cannot collect AI visibility', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_visibility_runs', 0);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_quota_failure_happens_before_provider_and_records_no_event(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $owner = $this->superAdmin('visibility-quota-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
            'daily_limit' => 1,
            'used_today' => 1,
            'usage_date' => now()->toDateString(),
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'quota-keyword',
            );
            $this->fail('Quota exhaustion must fail before provider dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_model_quota_exhausted', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertSame(1, (int) $model->fresh()->used_today);
    }

    public function test_invalid_encrypted_credential_fails_before_provider_and_records_no_event(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $owner = $this->superAdmin('visibility-invalid-credential-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        AiModel::query()->whereKey($model->id)->update(['api_key' => 'not-an-encrypted-credential']);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'credential-keyword',
            );
            $this->fail('An invalid credential must fail before provider dispatch.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertSame(0, (int) $model->fresh()->used_today);
    }

    public function test_non_super_model_owner_is_excluded_before_run_or_provider_work(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $owner = Admin::query()->create([
            'username' => 'visibility-ordinary-owner',
            'display_name' => 'Visibility Ordinary Owner',
            'password' => 'secret',
            'role' => 'admin',
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'owner-keyword',
            );
            $this->fail('A non-super owner must be excluded.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('没有可用', $exception->getMessage());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_visibility_runs', 0);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_provider_failure_records_one_failed_attempt_and_a_safe_run_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'error' => ['message' => 'visibility-secret-key at https://private.example.test'],
            ], 401),
        ]);
        $owner = $this->superAdmin('visibility-provider-failure-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'sensitive-keyword',
            );
            $this->fail('Provider failure must escape with a stable code.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_provider_auth_failed', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->sole();
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('ai_provider_auth_failed', $run->error_message);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $event->status);
        $this->assertSame('ai_provider_auth_failed', $event->error_code);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $serialized = json_encode([$run->error_message, $event->getAttributes()], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('visibility-secret-key', $serialized);
        $this->assertStringNotContainsString('private.example.test', $serialized);
        $this->assertStringNotContainsString('sensitive-keyword', $serialized);
        Http::assertSentCount(1);
        $mutationLock = app(AiModelInvocationLock::class)->acquireForMutation((int) $model->id);
        $this->assertNotNull($mutationLock);
        app(AiModelInvocationLock::class)->release($mutationLock);
    }

    public function test_provider_server_failures_record_one_failed_event_and_quota_per_real_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                'error' => ['message' => 'internal response body'],
            ], 500),
        ]);
        $owner = $this->superAdmin('visibility-provider-500-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'server-failure-keyword',
            );
            $this->fail('Provider server failure must escape with a stable code.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_provider_request_failed', $exception->getMessage());
        }

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame(['ark.p1', 'ark.p2'], $events->pluck('call_key')->all());
        $this->assertSame([
            AiModelUsageEvent::STATUS_FAILED,
            AiModelUsageEvent::STATUS_FAILED,
        ], $events->pluck('status')->all());
        $this->assertSame(2, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        Http::assertSentCount(2);
    }

    public function test_transient_ark_failure_then_success_records_two_attempts_and_commits_only_the_winner(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence('https://ark.cn-beijing.volces.com/api/v3/responses')
            ->push(['error' => ['message' => 'temporary']], 500)
            ->push($this->arkResponse(), 200);
        $owner = $this->superAdmin('visibility-provider-retry-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        $runs = app(AiVisibilityCollectionService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            'retry-keyword',
        );

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $runs['ark_run']->status);
        $this->assertSame(['ark.p1', 'ark.p2'], $events->pluck('call_key')->all());
        $this->assertSame([
            AiModelUsageEvent::STATUS_FAILED,
            AiModelUsageEvent::STATUS_SUCCEEDED,
        ], $events->pluck('status')->all());
        $this->assertSame(2, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        Http::assertSentCount(2);
    }

    public function test_transient_retry_revalidates_binding_before_a_second_real_request(): void
    {
        Http::preventStrayRequests();
        $owner = $this->superAdmin('visibility-retry-revoke-owner');
        $model = $this->systemModel($owner, [
            'name' => 'Ark Retry Original',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $replacement = $this->systemModel($owner, [
            'name' => 'Ark Retry Replacement',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => function () use ($replacement) {
                $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $replacement);

                return Http::response(['error' => ['message' => 'temporary']], 500);
            },
        ]);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'retry-revoked-keyword',
            );
            $this->fail('The second attempt must stop after binding revocation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('ark.p1', $event->call_key);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, $event->status);
        Http::assertSentCount(1);
    }

    public function test_binding_change_after_provider_return_marks_attempt_revoked_and_does_not_commit_result(): void
    {
        Http::preventStrayRequests();
        $owner = $this->superAdmin('visibility-binding-owner');
        $model = $this->systemModel($owner, [
            'name' => 'Ark Original',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $replacement = $this->systemModel($owner, [
            'name' => 'Ark Replacement',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => function () use ($replacement) {
                $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $replacement);

                return Http::response($this->arkResponse());
            },
        ]);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'revoked-keyword',
            );
            $this->fail('A changed binding must revoke the returned result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->sole();
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertNull($run->answer_text);
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_model_configuration_change_after_provider_return_marks_attempt_revoked(): void
    {
        Http::preventStrayRequests();
        $owner = $this->superAdmin('visibility-config-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => function () use ($model) {
                AiModel::query()->whereKey($model->id)->update([
                    'api_key' => app(ApiKeyCrypto::class)->encrypt('rotated-visibility-secret'),
                ]);

                return Http::response($this->arkResponse());
            },
        ]);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'config-revision-keyword',
            );
            $this->fail('A changed runtime configuration must revoke the returned result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_owner_deactivation_after_provider_return_marks_attempt_revoked(): void
    {
        Http::preventStrayRequests();
        $owner = $this->superAdmin('visibility-owner-revoked');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => function () use ($owner) {
                Admin::query()->whereKey($owner->id)->update(['status' => 'disabled']);

                return Http::response($this->arkResponse());
            },
        ]);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'owner-revoked-keyword',
            );
            $this->fail('An inactive owner must revoke the returned result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getMessage());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_run_cas_loss_after_provider_return_discards_attempt_without_overwriting_new_state(): void
    {
        Http::preventStrayRequests();
        $owner = $this->superAdmin('visibility-cas-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => function () {
                AiVisibilityRun::query()->where('status', AiVisibilityRun::STATUS_RUNNING)->update([
                    'status' => AiVisibilityRun::STATUS_FAILED,
                    'error_message' => 'ai_run_cancelled',
                ]);

                return Http::response($this->arkResponse());
            },
        ]);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'cancelled-keyword',
            );
            $this->fail('A lost run CAS must discard the returned result.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_result_discarded', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->sole();
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('ai_run_cancelled', $run->error_message);
        $this->assertNull($run->answer_text);
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_complete_run_transaction_rollback_discards_attempt_and_does_not_count_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response([
                ...$this->arkResponse(),
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => 'Will roll back',
                        'annotations' => [[
                            'title' => 'Rollback source',
                            'url' => 'https://source.example.test',
                        ]],
                    ]],
                ]],
            ]),
        ]);
        $owner = $this->superAdmin('visibility-rollback-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        DB::statement(<<<'SQL'
            CREATE TRIGGER fail_visibility_source_insert
            BEFORE INSERT ON ai_visibility_sources
            BEGIN
                SELECT RAISE(ABORT, 'https://database-secret.example.test');
            END
            SQL);

        try {
            app(AiVisibilityCollectionService::class)->collect(
                SystemAiIdentity::visibilityCollection(),
                'rollback-keyword',
            );
            $this->fail('The persistence failure must escape safely.');
        } catch (RuntimeException $exception) {
            $this->assertSame('ai_result_discarded', $exception->getMessage());
        }

        $run = AiVisibilityRun::query()->sole();
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiVisibilityRun::STATUS_FAILED, $run->status);
        $this->assertSame('ai_result_discarded', $run->error_message);
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_search_then_deepseek_records_only_the_deepseek_model_attempt(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://open.feedcoopapi.com/search_api/web_search' => Http::response([
                'LogId' => 'visibility-search-log',
                'Result' => ['WebResults' => []],
            ]),
        ]);
        MarkdownContentWriterAgent::fake(['Search analysis'])->preventStrayPrompts();
        $owner = $this->superAdmin('visibility-search-analysis-owner');
        $model = $this->systemModel($owner);
        $this->bindModel(AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY, $model);
        AiSourceProvider::query()->create([
            'name' => 'Search Source',
            'provider_key' => AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'endpoint_url' => 'https://open.feedcoopapi.com/search_api/web_search',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('search-source-secret'),
            'status' => 'active',
            'daily_limit' => 100,
        ]);

        $runs = app(AiVisibilityCollectionService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            'combined-keyword',
        );

        $this->assertArrayHasKey('search_run', $runs);
        $this->assertArrayHasKey('analysis_run', $runs);
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame((int) $runs['analysis_run']->id, (int) $event->source_id);
        $this->assertSame('deepseek.p1', $event->call_key);
    }

    public function test_telemetry_storage_failure_does_not_change_a_committed_visibility_result(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response($this->arkResponse()),
        ]);
        $owner = $this->superAdmin('visibility-telemetry-failure-owner');
        $model = $this->systemModel($owner, [
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);
        Schema::drop('ai_model_usage_events');

        $runs = app(AiVisibilityCollectionService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            'telemetry-failure-keyword',
        );

        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $runs['ark_run']->status);
        $this->assertSame(1, (int) $model->fresh()->total_used);
    }

    public function test_deepseek_collection_records_one_system_model_attempt_after_the_run_commits(): void
    {
        MarkdownContentWriterAgent::fake(['DeepSeek visibility analysis'])->preventStrayPrompts();
        $owner = $this->superAdmin('visibility-deepseek-owner');
        $model = $this->systemModel($owner, [
            'name' => 'DeepSeek Visibility',
            'model_id' => 'deepseek-chat',
            'api_url' => 'https://api.deepseek.com',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY, $model);

        $runs = app(AiVisibilityCollectionService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            'GEOFlow',
        );

        $run = $runs['analysis_run'];
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame((string) $run->uuid, $event->request_id);
        $this->assertSame('deepseek.p1', $event->call_key);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM, $event->execution_scope);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $event->model_source);
        $this->assertSame((int) $model->id, (int) $event->ai_model_id);
        $this->assertSame((int) $owner->id, (int) $event->config_owner_admin_id);
        $this->assertNull($event->execution_admin_id);
        $this->assertSame('ai_visibility.collect.deepseek', $event->operation);
        $this->assertSame('ai_visibility_collection', $event->business_source);
        $this->assertSame(AiVisibilityRun::class, $event->source_type);
        $this->assertSame((string) $run->id, (string) $event->source_id);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $serialized = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('visibility-secret-key', $serialized);
        $this->assertStringNotContainsString('GEOFlow', $serialized);
        $this->assertStringNotContainsString('api.deepseek.com', $serialized);
    }

    public function test_deepseek_digest_uses_the_exact_full_prompt_including_sources(): void
    {
        MarkdownContentWriterAgent::fake(['First analysis', 'Second analysis'])->preventStrayPrompts();
        $owner = $this->superAdmin('visibility-deepseek-digest-owner');
        $model = $this->systemModel($owner, [
            'model_id' => 'deepseek-chat',
            'api_url' => 'https://api.deepseek.com',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY, $model);
        $basePrompt = 'Same base prompt';
        $firstSources = [new AiVisibilitySourceData(
            sourceType: 'web',
            citationKey: 'S1',
            title: 'First source',
            url: 'https://first.example.test',
            summary: 'first private context',
        )];
        $secondSources = [new AiVisibilitySourceData(
            sourceType: 'web',
            citationKey: 'S2',
            title: 'Second source',
            url: 'https://second.example.test',
            summary: 'second private context',
        )];
        $service = app(AiVisibilityService::class);
        $service->runDeepSeekAnalysis(
            SystemAiIdentity::visibilityCollection(),
            $model,
            'GEOFlow',
            $basePrompt,
            $firstSources,
        );
        $service->runDeepSeekAnalysis(
            SystemAiIdentity::visibilityCollection(),
            $model,
            'GEOFlow',
            $basePrompt,
            $secondSources,
        );

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $client = app(DeepSeekAnalysisClient::class);
        $this->assertSame(
            hash('sha256', $client->fullPrompt($basePrompt, $firstSources)),
            $events[0]->request_payload_digest,
        );
        $this->assertSame(
            hash('sha256', $client->fullPrompt($basePrompt, $secondSources)),
            $events[1]->request_payload_digest,
        );
        $this->assertNotSame($events[0]->request_payload_digest, $events[1]->request_payload_digest);
        $serialized = json_encode($events->map->getAttributes()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('first private context', $serialized);
        $this->assertStringNotContainsString('second private context', $serialized);
        $this->assertStringNotContainsString('first.example.test', $serialized);
        $this->assertStringNotContainsString('second.example.test', $serialized);
    }

    public function test_ark_collection_records_one_system_model_attempt_after_the_run_commits(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/responses' => Http::response($this->arkResponse()),
        ]);
        $owner = $this->superAdmin('visibility-ark-owner');
        $model = $this->systemModel($owner, [
            'name' => 'Ark Visibility',
            'model_id' => 'doubao-visibility',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $this->bindModel(AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY, $model);

        $runs = app(AiVisibilityCollectionService::class)->collect(
            SystemAiIdentity::visibilityCollection(),
            'GEOFlow',
        );

        $run = $runs['ark_run'];
        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiVisibilityRun::STATUS_COMPLETED, $run->status);
        $this->assertSame((string) $run->uuid, $event->request_id);
        $this->assertSame('ark.p1', $event->call_key);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM, $event->execution_scope);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $event->model_source);
        $this->assertSame((int) $model->id, (int) $event->ai_model_id);
        $this->assertSame((int) $owner->id, (int) $event->config_owner_admin_id);
        $this->assertNull($event->execution_admin_id);
        $this->assertSame('ai_visibility.collect.ark', $event->operation);
        $this->assertSame('ai_visibility_collection', $event->business_source);
        $this->assertSame(AiVisibilityRun::class, $event->source_type);
        $this->assertSame((string) $run->id, (string) $event->source_id);
        $this->assertSame(15, (int) $event->total_tokens);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $serialized = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('visibility-secret-key', $serialized);
        $this->assertStringNotContainsString('GEOFlow', $serialized);
        $this->assertStringNotContainsString('ark.cn-beijing', $serialized);
        $prepared = app(DoubaoArkResponsesClient::class)->prepareRequest(
            $model,
            '请基于公开网络信息回答目标关键词「GEOFlow」相关问题，并在可用时保留引用来源。请重点说明主流 AI 可能会如何理解这个关键词。',
        );
        $this->assertSame(
            hash('sha256', $prepared->digestPayload),
            $event->request_payload_digest,
        );
    }

    private function superAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'display_name' => $username,
            'password' => 'secret',
            'role' => 'super_admin',
            'status' => 'active',
            'ai_config_access_version' => 1,
        ]);
    }

    /** @param array<string,mixed> $attributes */
    private function systemModel(Admin $owner, array $attributes = []): AiModel
    {
        $model = new AiModel(array_merge([
            'name' => 'Visibility System Model',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('visibility-secret-key'),
            'model_id' => 'visibility-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.deepseek.com',
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $attributes));
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ])->save();

        return $model;
    }

    private function bindModel(string $settingKey, AiModel $model): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $settingKey],
            ['setting_value' => (string) $model->id],
        );
    }

    /** @return array<string,mixed> */
    private function arkResponse(): array
    {
        return [
            'id' => 'ark-visibility-response',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => 'GEOFlow 可见性回答。',
                ]],
            ]],
            'usage' => [
                'input_tokens' => 6,
                'output_tokens' => 9,
                'total_tokens' => 15,
            ],
        ];
    }
}
