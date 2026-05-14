<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\SiteSetting;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KnowledgeChunkEmbeddingSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_uses_active_embedding_model_when_default_is_automatic(): void
    {
        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]),
        ]);

        $model = $this->createEmbeddingModel();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'GEOFlow 知识库',
            'description' => '',
            'content' => 'GEOFlow 是面向 GEO 内容工程的系统。',
            'character_count' => 24,
            'file_type' => 'markdown',
            'word_count' => 24,
        ]);

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            'GEOFlow 是面向 GEO 内容工程的系统，支持知识库、关键词库和标题库协同生成内容。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame((int) $model->id, (int) $chunk->embedding_model_id);
        $this->assertSame(3, (int) $chunk->embedding_dimensions);
        $this->assertSame('ai.test', (string) $chunk->embedding_provider);
        $this->assertSame([0.1, 0.2, 0.3], json_decode((string) $chunk->embedding_json, true));
        $this->assertNull($chunk->embedding_vector);

        $model->refresh();
        $this->assertSame(1, (int) $model->used_today);
        $this->assertSame(1, (int) $model->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/embeddings'
            && $request['model'] === 'test-embedding-model'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_sync_falls_back_without_embedding_model(): void
    {
        Http::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Fallback 知识库',
            'description' => '',
            'content' => '没有 embedding 模型时仍然应该写入 fallback 向量。',
            'character_count' => 30,
            'file_type' => 'markdown',
            'word_count' => 30,
        ]);

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            '没有 embedding 模型时仍然应该写入 fallback 向量，避免知识库上传失败。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertNull($chunk->embedding_model_id);
        $this->assertSame(0, (int) $chunk->embedding_dimensions);
        $this->assertCount(256, json_decode((string) $chunk->embedding_json, true));
        Http::assertNothingSent();
    }

    public function test_sync_skips_invalid_default_embedding_model_and_uses_next_active_model(): void
    {
        Http::fake([
            'https://fallback.test/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.4, 0.5, 0.6]],
                ],
            ]),
        ]);

        $invalidDefault = $this->createEmbeddingModel([
            'name' => 'Invalid Default Embedding',
            'api_key' => '',
            'api_url' => 'https://invalid.test',
            'failover_priority' => 1,
        ]);
        $fallbackModel = $this->createEmbeddingModel([
            'name' => 'Fallback Embedding',
            'api_url' => 'https://fallback.test',
            'failover_priority' => 10,
        ]);

        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $invalidDefault->id,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Fallback Model 知识库',
            'description' => '',
            'content' => '默认 embedding 模型无效时应该自动选择下一个可用模型。',
            'character_count' => 32,
            'file_type' => 'markdown',
            'word_count' => 32,
        ]);

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            '默认 embedding 模型无效时应该自动选择下一个可用模型。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame((int) $fallbackModel->id, (int) $chunk->embedding_model_id);
        $this->assertSame([0.4, 0.5, 0.6], json_decode((string) $chunk->embedding_json, true));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://fallback.test/v1/embeddings');
    }

    private function createEmbeddingModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Test Embedding',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-embedding-model',
            'model_type' => 'embedding',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
