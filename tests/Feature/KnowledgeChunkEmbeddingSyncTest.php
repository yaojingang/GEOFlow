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

    public function test_structured_rule_chunking_keeps_markdown_sections_and_metadata(): void
    {
        Http::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '结构化切片知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            "# GEOFlow 总览\n\nGEOFlow 是面向 GEO 内容工程的系统。\n\n## 多站分发\n\n分发管理负责把文章同步到多个目标站点。\n\n## 素材库\n\n素材库负责沉淀知识、关键词、标题和图片。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('# GEOFlow 总览', (string) $chunks[0]->content);
        $this->assertStringContainsString('## 多站分发', (string) $chunks[1]->content);
        $this->assertStringContainsString('## 素材库', (string) $chunks[2]->content);
        $this->assertSame('structured_rule', (string) $chunks[0]->getAttribute('chunk_strategy'));
        $this->assertSame('GEOFlow 总览', (string) $chunks[0]->getAttribute('chunk_title'));
        $this->assertSame('GEOFlow 总览 > 多站分发', (string) $chunks[1]->getAttribute('section_path'));
        $this->assertNotSame('', (string) $chunks[0]->getAttribute('source_hash'));
        $this->assertSame([0, 1], json_decode((string) $chunks[0]->getAttribute('metadata_json'), true)['block_indexes'] ?? []);
        Http::assertNothingSent();
    }

    public function test_semantic_chunking_uses_llm_plan_without_rewriting_original_text(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'chunks' => [
                                ['title' => '平台定位', 'block_indexes' => [0, 1]],
                                ['title' => '分发与素材', 'block_indexes' => [2, 3, 4, 5]],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $model = $this->createChatModel();
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_strategy',
            'setting_value' => 'semantic_llm',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $model->id,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '语义切片知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        app(KnowledgeChunkSyncService::class)->sync(
            (int) $knowledgeBase->id,
            "# 平台定位\n\nGEOFlow 负责内容工程后台。\n\n## 分发能力\n\n分发管理同步文章到渠道站点。\n\n## 素材能力\n\n素材库沉淀业务事实。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertCount(2, $chunks);
        $this->assertSame("# 平台定位\n\nGEOFlow 负责内容工程后台。", (string) $chunks[0]->content);
        $this->assertStringContainsString('## 分发能力', (string) $chunks[1]->content);
        $this->assertStringContainsString('## 素材能力', (string) $chunks[1]->content);
        $this->assertSame('semantic_llm', (string) $chunks[0]->getAttribute('chunk_strategy'));
        $this->assertSame('平台定位', (string) $chunks[0]->getAttribute('chunk_title'));
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_sync_uses_gemini_embedding_document_prefix_without_task_type(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents' => Http::response([
                'embeddings' => [
                    ['values' => [0.11, 0.22, 0.33]],
                ],
            ]),
        ]);

        $model = $this->createEmbeddingModel([
            'name' => 'Gemini Embedding 2',
            'model_id' => 'gemini-embedding-2',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'GEOFlow Guide',
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
        $this->assertSame([0.11, 0.22, 0.33], json_decode((string) $chunk->embedding_json, true));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ($request['requests'][0]['content']['parts'][0]['text'] ?? '') === 'title: GEOFlow Guide | text: GEOFlow 是面向 GEO 内容工程的系统，支持知识库、关键词库和标题库协同生成内容。'
            && ! isset($request['requests'][0]['taskType'])
            && ! isset($request['taskType']));
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

    private function createChatModel(array $overrides = []): AiModel
    {
        return AiModel::query()->create(array_merge([
            'name' => 'Test Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
    }
}
