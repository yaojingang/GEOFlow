<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\EmbedKnowledgeChunkBatchJob;
use App\Jobs\PrepareKnowledgeChunkSyncJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\SiteSetting;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Services\GeoFlow\KnowledgeEmbeddingModelFingerprint;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class KnowledgeEmbeddingIdentityIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_index_uses_only_the_bound_system_model_owned_by_an_active_super_admin(): void
    {
        Http::fake([
            'https://system.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ]),
        ]);

        $superAdmin = $this->admin('system-owner', 'super_admin');
        $ordinaryAdmin = $this->admin('ordinary-owner', 'admin');
        $systemModel = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        $this->model($ordinaryAdmin, 'peer-key', [
            'api_url' => 'https://peer.test',
            'failover_priority' => 1,
        ]);
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $systemModel->id],
        );
        $knowledgeBase = $this->knowledgeBase();

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            '系统索引只使用系统绑定的 Embedding 模型。',
            SystemAiIdentity::knowledgeIndex(),
            true,
        );

        $knowledgeBase->refresh();
        $chunk = $knowledgeBase->chunks()->firstOrFail();
        $this->assertSame($systemModel->id, (int) $chunk->embedding_model_id);
        $this->assertSame(3, (int) $knowledgeBase->chunk_embedding_dimensions);
        $this->assertSame('https://system.test/v1', (string) $knowledgeBase->chunk_embedding_provider);
        $this->assertSame(
            app(KnowledgeEmbeddingModelFingerprint::class)->forModel($systemModel, 3),
            (string) $knowledgeBase->chunk_embedding_fingerprint,
        );
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer system-key'));
    }

    public function test_realtime_query_uses_personal_before_shared_and_never_calls_peer_or_system_models(): void
    {
        Http::fake([
            'https://personal.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.3, 0.2, 0.1]]],
            ]),
            '*' => Http::response(['message' => 'unexpected'], 500),
        ]);

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $peer = $this->admin('peer', 'admin');
        $personal = $this->model($consumer, 'personal-key', ['api_url' => 'https://personal.test']);
        $this->model($provider, 'shared-key', ['api_url' => 'https://shared.test']);
        $this->model($peer, 'peer-key', ['api_url' => 'https://peer.test']);
        $this->model($provider, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.3, 0.2, 0.1]);

        $bundle = app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
            [(int) $knowledgeBase->id],
            'GEOFlow 检索身份',
            identity: $consumer,
        );

        $this->assertSame('compatible_vector', $bundle['retrieval_meta']['embedding_mode']);
        $this->assertSame($personal->id, $bundle['retrieval_meta']['embedding_model_id']);
        $this->assertSame('personal', $bundle['retrieval_meta']['embedding_model_source']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer personal-key'));
    }

    public function test_missing_or_incompatible_admin_embedding_identity_falls_back_to_keywords_without_global_model_call(): void
    {
        Http::fake();

        $systemOwner = $this->admin('system-owner', 'super_admin');
        $systemModel = $this->model($systemOwner, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'model_id' => 'system-embedding',
            'api_url' => 'https://system.test',
        ]);
        $knowledgeBase = $this->indexedKnowledgeBase($systemModel, [0.3, 0.2, 0.1]);

        $bundle = app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
            [(int) $knowledgeBase->id],
            '关键词降级',
        );

        $this->assertSame('keyword_fallback', $bundle['retrieval_meta']['embedding_mode']);
        $this->assertSame(AiModelAccessException::AI_EMBEDDING_INCOMPATIBLE, $bundle['retrieval_meta']['error_code']);
        $this->assertSame('missing_execution_identity', $bundle['retrieval_meta']['reason']);
        $this->assertStringContainsString('关键词降级', $bundle['context']);
        Http::assertNothingSent();
    }

    public function test_realtime_query_uses_a_compatible_shared_model_after_the_personal_pool_is_incompatible(): void
    {
        Http::fake([
            'https://shared.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.4, 0.5, 0.6]]],
            ]),
        ]);

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', [
            'shared_ai_config_owner_id' => $provider->id,
        ]);
        $this->model($consumer, 'personal-key', [
            'model_id' => 'incompatible-embedding',
            'api_url' => 'https://personal.test',
        ]);
        $shared = $this->model($provider, 'shared-key', ['api_url' => 'https://shared.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($shared, [0.4, 0.5, 0.6]);

        $result = app(KnowledgeChunkSyncService::class)->generateCompatibleQueryEmbedding(
            '共享兼容模型',
            $knowledgeBase,
            $consumer,
        );

        $this->assertNull($result->reason, (string) $result->reason);
        $this->assertSame($shared->id, $result->modelId);
        $this->assertSame('shared', $result->modelSource);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer shared-key'));
    }

    #[DataProvider('transientProviderStatusProvider')]
    public function test_transient_personal_embedding_failure_can_fall_through_to_a_compatible_shared_model(int $status): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-key')
            ? Http::response(['error' => ['message' => 'temporarily unavailable']], $status)
            : Http::response(['data' => [['embedding' => [0.4, 0.5, 0.6]]]]));

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personal = $this->model($consumer, 'personal-key', ['api_url' => 'https://compatible.test']);
        $shared = $this->model($provider, 'shared-key', ['api_url' => 'https://compatible.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.4, 0.5, 0.6]);

        $result = app(KnowledgeChunkSyncService::class)->generateCompatibleQueryEmbedding(
            '临时失败切换',
            $knowledgeBase,
            $consumer,
        );

        $this->assertSame($shared->id, $result->modelId);
        $this->assertSame('shared', $result->modelSource);
        Http::assertSentCount(2);
    }

    #[DataProvider('permanentProviderStatusProvider')]
    public function test_permanent_personal_embedding_rejection_does_not_call_the_shared_model(int $status): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-key')
            ? Http::response(['error' => ['message' => 'request rejected']], $status)
            : Http::response(['data' => [['embedding' => [0.4, 0.5, 0.6]]]]));

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personal = $this->model($consumer, 'personal-key', ['api_url' => 'https://compatible.test']);
        $this->model($provider, 'shared-key', ['api_url' => 'https://compatible.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.4, 0.5, 0.6]);

        $result = app(KnowledgeChunkSyncService::class)->generateCompatibleQueryEmbedding(
            '永久失败停止切换',
            $knowledgeBase,
            $consumer,
        );

        $this->assertFalse($result->successful());
        $this->assertSame('compatible_embedding_unavailable', $result->reason);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer personal-key'));
    }

    /** @return array<string,array{int}> */
    public static function permanentProviderStatusProvider(): array
    {
        return [
            '400 invalid request' => [400],
            '401 invalid credentials' => [401],
            '402 payment required' => [402],
            '403 forbidden' => [403],
            '422 capability mismatch' => [422],
        ];
    }

    /** @return array<string,array{int}> */
    public static function transientProviderStatusProvider(): array
    {
        return [
            '408 timeout' => [408],
            '425 too early' => [425],
            '429 rate limited' => [429],
            '500 provider error' => [500],
            '503 unavailable' => [503],
        ];
    }

    public function test_personal_embedding_with_missing_credentials_does_not_call_the_shared_model(): void
    {
        Http::fake([
            'https://compatible.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.4, 0.5, 0.6]]],
            ]),
        ]);

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personal = $this->model($consumer, '', ['api_url' => 'https://compatible.test']);
        $this->model($provider, 'shared-key', ['api_url' => 'https://compatible.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.4, 0.5, 0.6]);

        $result = app(KnowledgeChunkSyncService::class)->generateCompatibleQueryEmbedding(
            '凭据缺失停止切换',
            $knowledgeBase,
            $consumer,
        );

        $this->assertFalse($result->successful());
        $this->assertSame('embedding_model_configuration_invalid', $result->reason);
        Http::assertNothingSent();
    }

    public function test_personal_embedding_dimension_mismatch_does_not_call_the_shared_model(): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-key')
            ? Http::response(['data' => [['embedding' => [0.4, 0.5]]]])
            : Http::response(['data' => [['embedding' => [0.4, 0.5, 0.6]]]]));

        $provider = $this->admin('provider', 'super_admin');
        $consumer = $this->admin('consumer', 'admin', ['shared_ai_config_owner_id' => $provider->id]);
        $personal = $this->model($consumer, 'personal-key', ['api_url' => 'https://compatible.test']);
        $this->model($provider, 'shared-key', ['api_url' => 'https://compatible.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.4, 0.5, 0.6]);

        $result = app(KnowledgeChunkSyncService::class)->generateCompatibleQueryEmbedding(
            '向量维度不兼容',
            $knowledgeBase,
            $consumer,
        );

        $this->assertFalse($result->successful());
        $this->assertSame('embedding_dimensions_mismatch', $result->reason);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer personal-key'));
    }

    public function test_index_job_payload_contains_only_pipeline_identifiers_and_system_purpose(): void
    {
        Queue::fake();
        $knowledgeBase = $this->knowledgeBase();

        $this->assertTrue(app(KnowledgeChunkSyncCoordinator::class)->request(
            (int) $knowledgeBase->id,
            SystemAiIdentity::knowledgeIndex(),
        ));

        Queue::assertPushed(PrepareKnowledgeChunkSyncJob::class, function (PrepareKnowledgeChunkSyncJob $job): bool {
            $serialized = serialize($job);

            return $job->systemPurpose === 'knowledge_index'
                && ! str_contains($serialized, 'api_key')
                && ! str_contains($serialized, 'system-key')
                && ! str_contains($serialized, 'https://');
        });
    }

    public function test_recovery_cli_requeues_with_an_explicit_system_purpose(): void
    {
        Queue::fake();
        $knowledgeBase = $this->knowledgeBase([
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'stale-token',
            'chunk_sync_require_real_embedding' => true,
        ]);
        KnowledgeBase::query()->whereKey($knowledgeBase->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('geoflow:recover-knowledge-syncs', ['--stale' => 600])
            ->assertSuccessful();

        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->systemPurpose === 'knowledge_index'
                && $job->requireRealEmbedding,
        );
    }

    public function test_access_version_changed_during_query_discards_embedding_response(): void
    {
        $consumer = $this->admin('consumer', 'admin');
        $personal = $this->model($consumer, 'personal-key', ['api_url' => 'https://personal.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($personal, [0.3, 0.2, 0.1]);
        Http::fake([
            'https://personal.test/v1/embeddings' => function () use ($consumer) {
                Admin::query()->whereKey($consumer->id)->increment('ai_config_access_version');

                return Http::response(['data' => [['embedding' => [0.3, 0.2, 0.1]]]]);
            },
        ]);

        try {
            app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
                [(int) $knowledgeBase->id],
                '撤权时丢弃向量',
                identity: $consumer,
            );
            $this->fail('Expected the revoked query identity to be rejected.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        Http::assertSentCount(1);
    }

    public function test_peer_and_system_models_are_excluded_even_when_their_index_fingerprint_matches(): void
    {
        Http::fake();
        $consumer = $this->admin('consumer', 'admin');
        $peer = $this->admin('peer', 'admin');
        $peerModel = $this->model($peer, 'peer-key', ['api_url' => 'https://index.test']);
        $systemOwner = $this->admin('system-owner', 'super_admin');
        $this->model($systemOwner, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://index.test',
        ]);
        $knowledgeBase = $this->indexedKnowledgeBase($peerModel, [0.3, 0.2, 0.1]);

        $bundle = app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
            [(int) $knowledgeBase->id],
            '同指纹越权模型',
            identity: $consumer,
        );

        $this->assertSame('keyword_fallback', $bundle['retrieval_meta']['embedding_mode']);
        $this->assertSame('no_accessible_embedding_model', $bundle['retrieval_meta']['reason']);
        Http::assertNothingSent();
    }

    public function test_system_binding_revoked_during_embedding_does_not_publish_the_index_generation(): void
    {
        $superAdmin = $this->admin('system-owner', 'super_admin');
        $systemModel = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $systemModel->id],
        );
        $knowledgeBase = $this->knowledgeBase();
        Http::fake([
            'https://system.test/v1/embeddings' => function () {
                SiteSetting::query()
                    ->where('setting_key', 'default_embedding_model_id')
                    ->update(['setting_value' => '0']);

                return Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]]);
            },
        ]);

        $this->expectException(AiModelAccessException::class);
        try {
            app(KnowledgeChunkSyncService::class)->sync(
                (int) $knowledgeBase->id,
                '撤销系统绑定后不可发布索引。',
                SystemAiIdentity::knowledgeIndex(),
                true,
            );
        } finally {
            $this->assertSame('failed', (string) $knowledgeBase->fresh()->chunk_sync_status);
            $this->assertSame(0, $knowledgeBase->chunks()->count());
        }
    }

    public function test_in_place_system_model_change_cannot_mix_a_new_batch_into_the_frozen_pipeline(): void
    {
        config(['geoflow.knowledge_embedding_job_size' => 1]);
        Queue::fake();
        $superAdmin = $this->admin('system-owner', 'super_admin');
        $systemModel = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $systemModel->id],
        );
        $content = "# 第一节\n\n".str_repeat('第一批。', 300)."\n\n# 第二节\n\n".str_repeat('第二批。', 300);
        $knowledgeBase = $this->knowledgeBase(['content' => $content]);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $this->assertTrue($coordinator->request((int) $knowledgeBase->id, SystemAiIdentity::knowledgeIndex(), true));
        $token = (string) $knowledgeBase->fresh()->chunk_sync_token;
        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync((int) $knowledgeBase->id, $content, $token, SystemAiIdentity::knowledgeIndex());
        Http::fake(['https://system.test/v1/embeddings' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);

        $first = $service->embedStagingBatch((int) $knowledgeBase->id, $token, 0, SystemAiIdentity::knowledgeIndex(), true);
        $this->assertFalse((bool) ($first['done'] ?? true));
        $systemModel->forceFill([
            'api_url' => 'https://changed.test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('changed-key'),
            'model_id' => 'changed-embedding',
        ])->save();

        $this->expectException(PermanentAiProviderException::class);
        try {
            $service->embedStagingBatch(
                (int) $knowledgeBase->id,
                $token,
                (int) ($first['last_id'] ?? 0),
                SystemAiIdentity::knowledgeIndex(),
                true,
            );
        } finally {
            Http::assertSentCount(1);
            $this->assertSame(1, DB::table('knowledge_chunk_sync_rows')->whereNotNull('embedding_model_id')->count());
        }
    }

    public function test_legacy_serving_profile_falls_back_to_keywords_without_a_remote_call(): void
    {
        Http::fake();
        $admin = $this->admin('consumer', 'admin');
        $model = $this->model($admin, 'personal-key', ['api_url' => 'https://personal.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($model, [0.3, 0.2, 0.1]);
        $knowledgeBase->forceFill(['chunk_embedding_profile_version' => null])->save();

        $bundle = app(KnowledgeRetrievalService::class)->retrieveContextBundleFromMany(
            [(int) $knowledgeBase->id],
            '关键词降级',
            identity: $admin,
        );

        $this->assertSame('keyword_fallback', $bundle['retrieval_meta']['embedding_mode']);
        $this->assertSame('index_embedding_profile_incompatible', $bundle['retrieval_meta']['reason']);
        Http::assertNothingSent();
    }

    public function test_local_vector_scoring_ignores_rows_outside_the_serving_profile(): void
    {
        $admin = $this->admin('consumer', 'admin');
        $model = $this->model($admin, 'personal-key', ['api_url' => 'https://personal.test']);
        $knowledgeBase = $this->indexedKnowledgeBase($model, [0.0, 1.0, 0.0]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'generation_key' => 'serving-generation',
            'chunk_index' => 1,
            'content' => '混合 profile 行',
            'content_hash' => hash('sha256', 'mixed-profile-chunk'),
            'source_hash' => hash('sha256', 'mixed-profile-source'),
            'embedding_json' => json_encode([1.0, 0.0, 0.0]),
            'embedding_model_id' => $model->id,
            'embedding_dimensions' => 3,
            'embedding_provider' => app(KnowledgeEmbeddingModelFingerprint::class)->provider($model),
            'embedding_fingerprint' => str_repeat('a', 64),
            'embedding_profile_version' => app(KnowledgeEmbeddingModelFingerprint::class)->profileVersion(),
            'embedding_profile_digest' => str_repeat('a', 64),
        ]);
        Http::fake(['https://personal.test/v1/embeddings' => Http::response(['data' => [['embedding' => [1.0, 0.0, 0.0]]]])]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            (int) $knowledgeBase->id,
            '混合 profile 行',
            identity: $admin,
        );
        $mixed = collect($evidence)->firstWhere('chunk_index', 1);

        $this->assertIsArray($mixed);
        $this->assertSame(0.0, (float) $mixed['vector_score']);
    }

    public function test_finalize_rejects_mixed_staging_profiles(): void
    {
        Queue::fake();
        $superAdmin = $this->admin('system-owner', 'super_admin');
        $model = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'default_embedding_model_id'], ['setting_value' => (string) $model->id]);
        $content = "# 第一节\n\n".str_repeat('甲', 1000)."\n\n# 第二节\n\n".str_repeat('乙', 1000);
        $knowledgeBase = $this->knowledgeBase(['content' => $content]);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $coordinator->request((int) $knowledgeBase->id, SystemAiIdentity::knowledgeIndex(), true);
        $knowledgeBase->refresh();
        $token = (string) $knowledgeBase->chunk_sync_token;
        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync((int) $knowledgeBase->id, $content, $token, SystemAiIdentity::knowledgeIndex());
        $digest = app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, 3);
        DB::table('knowledge_chunk_sync_rows')->where('sync_token', $token)->update([
            'embedding_json' => '[0.1,0.2,0.3]',
            'embedding_model_id' => $model->id,
            'embedding_dimensions' => 3,
            'embedding_provider' => app(KnowledgeEmbeddingModelFingerprint::class)->provider($model),
            'embedding_fingerprint' => $digest,
            'embedding_profile_version' => app(KnowledgeEmbeddingModelFingerprint::class)->profileVersion(),
            'embedding_profile_digest' => $digest,
            'embedding_config_revision' => $knowledgeBase->chunk_sync_embedding_config_revision,
        ]);
        $lastId = (int) DB::table('knowledge_chunk_sync_rows')->where('sync_token', $token)->max('id');
        DB::table('knowledge_chunk_sync_rows')->where('id', $lastId)->update([
            'embedding_fingerprint' => str_repeat('b', 64),
            'embedding_profile_digest' => str_repeat('b', 64),
        ]);

        $this->expectException(PermanentAiProviderException::class);
        try {
            $service->finalizeStagingSync((int) $knowledgeBase->id, $token, SystemAiIdentity::knowledgeIndex());
        } finally {
            $this->assertSame(0, $knowledgeBase->chunks()->count());
            $this->assertSame('processing', (string) $knowledgeBase->fresh()->chunk_sync_status);
        }
    }

    public function test_permanent_embedding_job_failure_is_terminal_on_the_first_attempt(): void
    {
        Queue::fake();
        $superAdmin = $this->admin('system-owner', 'super_admin');
        $model = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'default_embedding_model_id'], ['setting_value' => (string) $model->id]);
        $knowledgeBase = $this->knowledgeBase(['content' => '永久错误。']);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $coordinator->request((int) $knowledgeBase->id, SystemAiIdentity::knowledgeIndex(), true);
        $token = (string) $knowledgeBase->fresh()->chunk_sync_token;
        app(KnowledgeChunkSyncService::class)->prepareStagingSync(
            (int) $knowledgeBase->id,
            (string) $knowledgeBase->content,
            $token,
            SystemAiIdentity::knowledgeIndex(),
        );
        Http::fake(['https://system.test/v1/embeddings' => Http::response(['error' => ['message' => 'payment required']], 402)]);

        (new EmbedKnowledgeChunkBatchJob(
            (int) $knowledgeBase->id,
            $token,
            0,
            'knowledge_index',
            true,
        ))->handle($coordinator, app(KnowledgeChunkSyncService::class));

        $this->assertSame('failed', (string) $knowledgeBase->fresh()->chunk_sync_status);
        Http::assertSentCount(1);
        Queue::assertNotPushed(EmbedKnowledgeChunkBatchJob::class);
    }

    public function test_transient_embedding_job_failure_remains_retryable(): void
    {
        Queue::fake();
        $superAdmin = $this->admin('system-owner', 'super_admin');
        $model = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => 'https://system.test',
        ]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'default_embedding_model_id'], ['setting_value' => (string) $model->id]);
        $knowledgeBase = $this->knowledgeBase(['content' => '临时错误。']);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $coordinator->request((int) $knowledgeBase->id, SystemAiIdentity::knowledgeIndex(), true);
        $token = (string) $knowledgeBase->fresh()->chunk_sync_token;
        app(KnowledgeChunkSyncService::class)->prepareStagingSync((int) $knowledgeBase->id, '临时错误。', $token, SystemAiIdentity::knowledgeIndex());
        Http::fake(['https://system.test/v1/embeddings' => Http::response(['error' => ['message' => 'unavailable']], 503)]);

        $this->expectException(\RuntimeException::class);
        try {
            (new EmbedKnowledgeChunkBatchJob(
                (int) $knowledgeBase->id,
                $token,
                0,
                'knowledge_index',
                true,
            ))->handle($coordinator, app(KnowledgeChunkSyncService::class));
        } finally {
            $this->assertSame('processing', (string) $knowledgeBase->fresh()->chunk_sync_status);
            Http::assertSentCount(1);
        }
    }

    public function test_pgvector_serving_profile_filter_is_a_postgresql_release_gate(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL pgvector serving-profile filtering runs in the PostgreSQL release gate.');
        }

        $this->assertTrue(Schema::hasColumns('knowledge_chunks', [
            'embedding_profile_version',
            'embedding_profile_digest',
        ]));
    }

    public function test_legacy_serialized_index_job_fails_closed_without_a_frozen_profile(): void
    {
        $knowledgeBase = $this->knowledgeBase([
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => 'legacy-token',
        ]);

        (new EmbedKnowledgeChunkBatchJob(
            (int) $knowledgeBase->id,
            'legacy-token',
            0,
            'knowledge_index',
            true,
        ))->handle(app(KnowledgeChunkSyncCoordinator::class), app(KnowledgeChunkSyncService::class));

        $this->assertSame('failed', (string) $knowledgeBase->fresh()->chunk_sync_status);
        $this->assertSame('knowledge_embedding_profile_incompatible', (string) $knowledgeBase->fresh()->chunk_sync_error);
    }

    public function test_embedding_profile_migration_rolls_back_and_reapplies(): void
    {
        $providerWidthMigration = require database_path('migrations/2026_09_02_155000_expand_knowledge_embedding_provider_columns.php');
        $profileMigration = require database_path('migrations/2026_09_02_154000_harden_knowledge_embedding_profiles.php');
        $migration = require database_path('migrations/2026_09_02_153000_add_embedding_fingerprint_to_knowledge_index.php');

        $providerWidthMigration->down();
        $profileMigration->down();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('knowledge_bases', 'chunk_embedding_fingerprint'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_fingerprint'));

        $migration->up();
        $profileMigration->up();
        $providerWidthMigration->up();
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'chunk_embedding_fingerprint',
            'chunk_embedding_dimensions',
            'chunk_embedding_provider',
        ]));
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_fingerprint'));
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'chunk_sync_embedding_config_revision',
            'chunk_embedding_profile_version',
            'chunk_embedding_profile_digest',
        ]));
        $this->assertTrue(Schema::hasColumns('knowledge_chunks', [
            'embedding_profile_version',
            'embedding_profile_digest',
        ]));
    }

    public function test_provider_width_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_09_02_155000_expand_knowledge_embedding_provider_columns.php');

        $migration->down();
        $this->assertTrue(Schema::hasColumn('knowledge_bases', 'chunk_embedding_provider'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'embedding_provider'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_provider'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('knowledge_bases', 'chunk_embedding_provider'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'embedding_provider'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_provider'));
    }

    public function test_five_hundred_character_provider_url_survives_staging_and_serving(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['data' => [['embedding' => [0.1, 0.2, 0.3]]]])]);
        $providerUrl = 'https://system.test/'.str_repeat('A', 480);
        $this->assertSame(500, strlen($providerUrl));

        $superAdmin = $this->admin('long-provider-owner', 'super_admin');
        $model = $this->model($superAdmin, 'system-key', [
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
            'api_url' => $providerUrl,
        ]);
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $model->id],
        );
        $knowledgeBase = $this->knowledgeBase(['content' => '长 Provider URL 索引。']);
        $coordinator = app(KnowledgeChunkSyncCoordinator::class);
        $this->assertTrue($coordinator->request((int) $knowledgeBase->id, SystemAiIdentity::knowledgeIndex(), true));
        $token = (string) $knowledgeBase->fresh()->chunk_sync_token;
        $service = app(KnowledgeChunkSyncService::class);
        $service->prepareStagingSync(
            (int) $knowledgeBase->id,
            (string) $knowledgeBase->content,
            $token,
            SystemAiIdentity::knowledgeIndex(),
        );
        $service->embedStagingBatch(
            (int) $knowledgeBase->id,
            $token,
            0,
            SystemAiIdentity::knowledgeIndex(),
            true,
        );

        $this->assertSame(
            $providerUrl,
            (string) DB::table('knowledge_chunk_sync_rows')->where('sync_token', $token)->value('embedding_provider'),
        );
        $this->assertTrue($service->finalizeStagingSync(
            (int) $knowledgeBase->id,
            $token,
            SystemAiIdentity::knowledgeIndex(),
        ));
        $this->assertSame($providerUrl, (string) $knowledgeBase->fresh()->chunk_embedding_provider);
        $this->assertSame($providerUrl, (string) $knowledgeBase->chunks()->firstOrFail()->embedding_provider);
    }

    private function indexedKnowledgeBase(AiModel $model, array $vector): KnowledgeBase
    {
        $knowledgeBase = $this->knowledgeBase([
            'chunk_sync_status' => 'ready',
            'chunk_serving_generation' => 'serving-generation',
        ]);
        $knowledgeBase->forceFill([
            'chunk_embedding_fingerprint' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, count($vector)),
            'chunk_embedding_dimensions' => count($vector),
            'chunk_embedding_provider' => app(KnowledgeEmbeddingModelFingerprint::class)->provider($model),
            'chunk_embedding_model_id' => $model->id,
            'chunk_embedding_profile_version' => app(KnowledgeEmbeddingModelFingerprint::class)->profileVersion(),
            'chunk_embedding_profile_digest' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, count($vector)),
        ])->save();
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'generation_key' => 'serving-generation',
            'chunk_index' => 0,
            'content' => '关键词降级与 GEOFlow 检索身份均来自该知识片段。',
            'content_hash' => hash('sha256', 'identity-chunk'),
            'source_hash' => hash('sha256', 'identity-source'),
            'embedding_json' => json_encode($vector),
            'embedding_model_id' => $model->id,
            'embedding_dimensions' => count($vector),
            'embedding_provider' => app(KnowledgeEmbeddingModelFingerprint::class)->provider($model),
            'embedding_fingerprint' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, count($vector)),
            'embedding_profile_version' => app(KnowledgeEmbeddingModelFingerprint::class)->profileVersion(),
            'embedding_profile_digest' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, count($vector)),
        ]);

        return $knowledgeBase;
    }

    private function admin(string $username, string $role, array $overrides = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'display_name' => $username,
            'password' => 'secret',
            'role' => $role,
            'status' => 'active',
        ]);
        if ($overrides !== []) {
            $admin->forceFill($overrides)->save();
        }

        return $admin->refresh();
    }

    private function model(Admin $owner, string $key, array $overrides = []): AiModel
    {
        $model = new AiModel;
        $model->forceFill(array_merge([
            'owner_admin_id' => $owner->id,
            'name' => $owner->username.' embedding',
            'version' => '1',
            'api_key' => app(ApiKeyCrypto::class)->encrypt($key),
            'model_id' => 'compatible-embedding',
            'model_type' => 'embedding',
            'api_url' => 'https://'.$owner->username.'.test',
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
        $model->save();

        return $model;
    }

    private function knowledgeBase(array $overrides = []): KnowledgeBase
    {
        return KnowledgeBase::query()->create(array_merge([
            'name' => '身份隔离知识库',
            'description' => '',
            'content' => '关键词降级与 GEOFlow 检索身份均来自该知识片段。',
            'character_count' => 30,
            'file_type' => 'markdown',
            'word_count' => 30,
        ], $overrides));
    }
}
