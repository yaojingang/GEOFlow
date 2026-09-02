<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame('system.test', (string) $knowledgeBase->chunk_embedding_provider);
        $this->assertSame(
            app(KnowledgeEmbeddingModelFingerprint::class)->forModel($systemModel),
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

    public function test_transient_personal_embedding_failure_can_fall_through_to_a_compatible_shared_model(): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-key')
            ? Http::response(['error' => ['message' => 'temporarily unavailable']], 503)
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

    public function test_permanent_personal_embedding_rejection_does_not_call_the_shared_model(): void
    {
        Http::fake(fn ($request) => $request->hasHeader('Authorization', 'Bearer personal-key')
            ? Http::response(['error' => ['message' => 'invalid credentials']], 401)
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

    public function test_embedding_profile_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_09_02_153000_add_embedding_fingerprint_to_knowledge_index.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('knowledge_bases', 'chunk_embedding_fingerprint'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint'));
        $this->assertFalse(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_fingerprint'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'chunk_embedding_fingerprint',
            'chunk_embedding_dimensions',
            'chunk_embedding_provider',
        ]));
        $this->assertTrue(Schema::hasColumn('knowledge_chunks', 'embedding_fingerprint'));
        $this->assertTrue(Schema::hasColumn('knowledge_chunk_sync_rows', 'embedding_fingerprint'));
    }

    private function indexedKnowledgeBase(AiModel $model, array $vector): KnowledgeBase
    {
        $knowledgeBase = $this->knowledgeBase([
            'chunk_sync_status' => 'ready',
            'chunk_serving_generation' => 'serving-generation',
        ]);
        $knowledgeBase->forceFill([
            'chunk_embedding_fingerprint' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model),
            'chunk_embedding_dimensions' => count($vector),
            'chunk_embedding_provider' => (string) parse_url((string) $model->api_url, PHP_URL_HOST),
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
            'embedding_provider' => (string) parse_url((string) $model->api_url, PHP_URL_HOST),
            'embedding_fingerprint' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model),
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
