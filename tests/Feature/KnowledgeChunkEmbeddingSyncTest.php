<?php

namespace Tests\Feature;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\KnowledgeBase;
use App\Models\SiteSetting;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use App\Services\GeoFlow\KnowledgeEmbeddingModelFingerprint;
use App\Services\GeoFlow\SystemAiModelAccessResolver;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            'GEOFlow 是面向 GEO 内容工程的系统，支持知识库、关键词库和标题库协同生成内容。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame((int) $model->id, (int) $chunk->embedding_model_id);
        $this->assertSame(3, (int) $chunk->embedding_dimensions);
        $this->assertSame('https://ai.test/v1', (string) $chunk->embedding_provider);
        $this->assertSame([0.1, 0.2, 0.3], json_decode((string) $chunk->embedding_json, true));
        $this->assertNull($chunk->embedding_vector);

        $model->refresh();
        $this->assertSame(1, (int) $model->used_today);
        $this->assertSame(1, (int) $model->total_used);

        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usageEvent->status);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $usageEvent->model_source);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM, $usageEvent->execution_scope);
        $this->assertNull($usageEvent->execution_admin_id);
        $this->assertSame($model->owner_admin_id, $usageEvent->config_owner_admin_id);

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

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            '没有 embedding 模型时仍然应该写入 fallback 向量，避免知识库上传失败。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertNull($chunk->embedding_model_id);
        $this->assertSame(0, (int) $chunk->embedding_dimensions);
        $this->assertCount(256, json_decode((string) $chunk->embedding_json, true));
        Http::assertNothingSent();
    }

    public function test_sync_writes_knowledge_base_evidence_metadata_to_chunks(): void
    {
        Http::fake();

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '证据化知识库',
            'description' => '用于验证来源和治理元数据',
            'content' => 'GEOFlow 知识库需要保留来源、业务线和审核状态。',
            'character_count' => 28,
            'file_type' => 'markdown',
            'word_count' => 28,
            'source_name' => 'GEOFlow 官方文档',
            'source_url' => 'https://example.com/geoflow',
            'source_type' => 'document',
            'business_line' => 'GEO 内容工程',
            'effective_date' => '2026-05-01',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 证据化知识库\n\nGEOFlow 知识库需要保留来源、业务线和审核状态。"
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();
        $metadata = json_decode((string) $chunk->metadata_json, true);

        $this->assertSame((int) $knowledgeBase->id, (int) ($metadata['knowledge_base_id'] ?? 0));
        $this->assertSame('证据化知识库', (string) ($metadata['knowledge_base_name'] ?? ''));
        $this->assertSame('GEOFlow 官方文档', (string) ($metadata['source_name'] ?? ''));
        $this->assertSame('https://example.com/geoflow', (string) ($metadata['source_url'] ?? ''));
        $this->assertSame('GEO 内容工程', (string) ($metadata['business_line'] ?? ''));
        $this->assertSame('2026-05-01', (string) ($metadata['effective_date'] ?? ''));
        $this->assertSame('low', (string) ($metadata['risk_level'] ?? ''));
        $this->assertSame('reviewed', (string) ($metadata['review_status'] ?? ''));
        Http::assertNothingSent();
    }

    public function test_structured_rule_chunking_keeps_markdown_sections_separate(): void
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

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# GEOFlow 总览\n\nGEOFlow 是面向 GEO 内容工程的系统。\n\n## 多站分发\n\n分发管理负责把文章同步到多个目标站点。\n\n## 素材库\n\n素材库负责沉淀知识、关键词、标题和图片。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->pluck('content')->all();
        $firstChunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('# GEOFlow 总览', $chunks[0]);
        $this->assertStringContainsString('## 多站分发', $chunks[1]);
        $this->assertStringContainsString('## 素材库', $chunks[2]);
        $this->assertSame('structured_rule', (string) $firstChunk->getAttribute('chunk_strategy'));
        $this->assertSame('GEOFlow 总览', (string) $firstChunk->getAttribute('chunk_title'));
        Http::assertNothingSent();
    }

    public function test_structured_rule_chunking_splits_oversized_single_blocks(): void
    {
        Http::fake();

        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_max_chars',
            'setting_value' => '300',
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '超长段落知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);
        $longParagraph = str_repeat('GEOFlow 语义切片需要稳定处理超长段落。', 30);

        $this->syncKnowledge((int) $knowledgeBase->id, $longParagraph);

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertGreaterThan(1, $chunks->count());
        $chunks->each(function ($chunk): void {
            $this->assertLessThanOrEqual(300, mb_strlen((string) $chunk->content, 'UTF-8'));
            $this->assertSame('structured_rule', (string) $chunk->chunk_strategy);
        });
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

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 平台定位\n\nGEOFlow 负责内容工程后台。\n\n## 分发能力\n\n分发管理同步文章到渠道站点。\n\n## 素材能力\n\n素材库沉淀业务事实。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->pluck('content')->all();
        $firstChunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertCount(2, $chunks);
        $this->assertSame("# 平台定位\n\nGEOFlow 负责内容工程后台。", $chunks[0]);
        $this->assertStringContainsString('## 分发能力', $chunks[1]);
        $this->assertStringContainsString('## 素材能力', $chunks[1]);
        $this->assertSame('semantic_llm', (string) $firstChunk->getAttribute('chunk_strategy'));
        $this->assertSame('平台定位', (string) $firstChunk->getAttribute('chunk_title'));
        $this->assertSame([0, 1], json_decode((string) $firstChunk->getAttribute('metadata_json'), true)['block_indexes'] ?? []);
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usageEvent->status);
        $this->assertSame('knowledge.semantic_chunking', $usageEvent->operation);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SYSTEM, $usageEvent->model_source);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_semantic_chunking_falls_back_to_structured_rules_when_plan_is_invalid(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '不是合法 JSON'],
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
            'name' => '语义回退知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 总览\n\n总览内容。\n\n## 细节\n\n细节内容。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->pluck('content')->all();
        $firstChunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('# 总览', $chunks[0]);
        $this->assertStringContainsString('## 细节', $chunks[1]);
        $this->assertSame('semantic_fallback', (string) $firstChunk->getAttribute('chunk_strategy'));
        $this->assertSame(
            AiModelUsageEvent::STATUS_DISCARDED,
            AiModelUsageEvent::query()->sole()->status,
        );
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions');
    }

    public function test_semantic_chunking_records_revoked_when_binding_changes_after_provider_return(): void
    {
        $model = $this->createChatModel();
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_strategy',
            'setting_value' => 'semantic_llm',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $model->id,
        ]);
        Http::fake([
            'https://ai.test/v1/chat/completions' => function () {
                SiteSetting::query()
                    ->where('setting_key', 'knowledge_chunking_model_id')
                    ->update(['setting_value' => '0']);

                return Http::response([
                    'choices' => [[
                        'message' => ['content' => json_encode([
                            'chunks' => [['title' => '总览', 'block_indexes' => [0, 1]]],
                        ], JSON_UNESCAPED_UNICODE)],
                    ]],
                ]);
            },
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '语义撤权知识库',
            'content' => '',
            'file_type' => 'markdown',
        ]);

        try {
            $this->syncKnowledge((int) $knowledgeBase->id, "# 总览\n\n撤权后的规划不可写入 staging。");
            $this->fail('Expected the revoked semantic model binding to stop persistence.');
        } catch (AiModelAccessException) {
            $event = AiModelUsageEvent::query()->sole();
            $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
            $this->assertSame(0, DB::table('knowledge_chunk_sync_rows')->count());
        }
    }

    public function test_semantic_chunking_records_failed_when_provider_request_fails(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'error' => ['message' => 'temporarily unavailable'],
            ], 503),
        ]);
        $model = $this->createChatModel();
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunk_strategy', 'setting_value' => 'semantic_llm']);
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunking_model_id', 'setting_value' => (string) $model->id]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '语义失败知识库',
            'content' => '',
            'file_type' => 'markdown',
        ]);

        $this->syncKnowledge((int) $knowledgeBase->id, "# 总览\n\n服务失败时使用规则切片。");

        $this->assertSame('semantic_fallback', (string) $knowledgeBase->chunks()->firstOrFail()->chunk_strategy);
        $this->assertSame(AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::query()->sole()->status);
    }

    public function test_stale_semantic_prepare_stops_before_provider_and_usage_capture(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"chunks":[]}'],
                ]],
            ]),
        ]);
        $model = $this->createChatModel();
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunk_strategy', 'setting_value' => 'semantic_llm']);
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunking_model_id', 'setting_value' => (string) $model->id]);
        $content = "# 平台定位\n\nGEOFlow 负责内容工程后台。\n\n## 分发能力\n\n旧 token 不得调用语义规划模型。";
        $currentToken = '9cb1ef40-b81e-48a1-a101-bb2b3141fe20';
        $rotatedToken = '50dfda20-ffeb-43f8-b025-843c433b465f';
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '过期语义切片知识库',
            'content' => $content,
            'file_type' => 'markdown',
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => $currentToken,
            'chunk_source_hash' => hash('sha256', $content),
        ]);
        $this->assertSame('semantic_llm', SiteSetting::query()->where('setting_key', 'knowledge_chunk_strategy')->value('setting_value'));
        $this->assertSame($model->id, app(SystemAiModelAccessResolver::class)
            ->resolveSemanticChunking(SystemAiIdentity::knowledgeIndex())?->id);
        $rotated = false;
        AiModel::updated(function (AiModel $updatedModel) use ($knowledgeBase, $model, $rotatedToken, &$rotated): void {
            if ($rotated || (int) $updatedModel->getKey() !== (int) $model->getKey() || ! $updatedModel->wasChanged('used_today')) {
                return;
            }

            $rotated = true;
            KnowledgeBase::query()->whereKey($knowledgeBase->id)->update([
                'chunk_sync_token' => $rotatedToken,
            ]);
        });

        $count = app(KnowledgeChunkSyncService::class)->prepareStagingSync(
            (int) $knowledgeBase->id,
            $content,
            $currentToken,
            SystemAiIdentity::knowledgeIndex(),
        );

        $this->assertSame(0, $count);
        $this->assertTrue($rotated);
        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        $this->assertDatabaseMissing('knowledge_chunk_sync_rows', [
            'knowledge_base_id' => $knowledgeBase->id,
            'sync_token' => $currentToken,
        ]);
    }

    public function test_semantic_chunking_discards_usage_and_releases_lock_when_staging_rolls_back(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'chunks' => [['title' => '总览', 'block_indexes' => [0, 1]]],
                    ], JSON_UNESCAPED_UNICODE)],
                ]],
            ]),
        ]);
        $model = $this->createChatModel();
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunk_strategy', 'setting_value' => 'semantic_llm']);
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunking_model_id', 'setting_value' => (string) $model->id]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '语义回滚知识库',
            'content' => '',
            'file_type' => 'markdown',
        ]);
        DB::listen(static function ($query): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'insert into') && str_contains($sql, 'knowledge_chunk_sync_rows')) {
                throw new \RuntimeException('forced_semantic_staging_rollback');
            }
        });

        try {
            $this->syncKnowledge((int) $knowledgeBase->id, "# 总览\n\n事务回滚不可记成功。");
            $this->fail('Expected staging persistence to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced_semantic_staging_rollback', $exception->getMessage());
            $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, AiModelUsageEvent::query()->sole()->status);
            $lock = app(AiModelInvocationLock::class)->acquireForMutation((int) $model->id);
            $this->assertNotNull($lock);
            app(AiModelInvocationLock::class)->release($lock);
        }
    }

    public function test_semantic_chunking_falls_back_when_plan_reorders_blocks(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'chunks' => [
                                ['title' => '后文', 'block_indexes' => [2, 3]],
                                ['title' => '前文', 'block_indexes' => [0, 1]],
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
            'name' => '乱序规划知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 前文\n\n前文内容。\n\n## 后文\n\n后文内容。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('# 前文', (string) $chunks[0]->content);
        $this->assertSame('semantic_fallback', (string) $chunks[0]->chunk_strategy);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions');
    }

    public function test_semantic_chunking_falls_back_when_plan_contains_invalid_index_values(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'chunks' => [
                                ['title' => '坏索引', 'block_indexes' => [0, 'bad']],
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
            'name' => '坏索引知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge((int) $knowledgeBase->id, '只有一个短段落。');

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame('semantic_fallback', (string) $chunk->chunk_strategy);
        $this->assertSame('只有一个短段落。', (string) $chunk->content);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions');
    }

    public function test_auto_semantic_chunking_uses_rule_chunks_without_llm_for_large_inputs(): void
    {
        Http::fake();

        $model = $this->createChatModel();
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_strategy',
            'setting_value' => 'auto',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $model->id,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '大输入知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);
        $content = collect(range(1, 130))
            ->map(static fn (int $index): string => "## 第 {$index} 节\n\n第 {$index} 节内容。")
            ->implode("\n\n");

        $this->syncKnowledge((int) $knowledgeBase->id, $content);

        $firstChunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertSame('structured_rule', (string) $firstChunk->chunk_strategy);
        Http::assertNothingSent();
    }

    public function test_semantic_chunking_does_not_leave_the_bound_system_model(): void
    {
        Http::fake([
            'https://bad.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '不是合法规划'],
                ]],
            ]),
            'https://good.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'chunks' => [
                                ['title' => '总览', 'block_indexes' => [0, 1]],
                                ['title' => '细节', 'block_indexes' => [2, 3]],
                            ],
                        ], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ]),
        ]);

        $badModel = $this->createChatModel([
            'name' => 'Bad Semantic Planner',
            'api_url' => 'https://bad.test',
            'failover_priority' => 1,
        ]);
        $goodModel = $this->createChatModel([
            'name' => 'Good Semantic Planner',
            'api_url' => 'https://good.test',
            'failover_priority' => 2,
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunk_strategy',
            'setting_value' => 'semantic_llm',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $badModel->id,
        ]);

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '语义模型切换知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 总览\n\n总览内容。\n\n## 细节\n\n细节内容。"
        );

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertCount(2, $chunks);
        $this->assertSame('semantic_fallback', (string) $chunks[0]->chunk_strategy);

        $badModel->refresh();
        $goodModel->refresh();
        $this->assertSame(0, (int) $badModel->used_today);
        $this->assertSame(0, (int) $badModel->total_used);
        $this->assertSame(0, (int) $goodModel->used_today);
        $this->assertSame(0, (int) $goodModel->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://bad.test/v1/chat/completions');
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://good.test/v1/chat/completions');
    }

    public function test_semantic_chunking_counts_usage_only_after_valid_plan(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '不是合法 JSON'],
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
            'name' => '语义计数知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 总览\n\n总览内容。\n\n## 细节\n\n细节内容。"
        );

        $chunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertSame('semantic_fallback', (string) $chunk->chunk_strategy);

        $model->refresh();
        $this->assertSame(0, (int) $model->used_today);
        $this->assertSame(0, (int) $model->total_used);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions');
    }

    public function test_semantic_chunking_prompt_limit_is_configurable(): void
    {
        config(['geoflow.semantic_chunking_max_chars' => 10]);
        Http::fake();

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
            'name' => '可配置上限知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 总览\n\n总览内容。\n\n## 细节\n\n细节内容。"
        );

        $chunk = $knowledgeBase->chunks()->orderBy('chunk_index')->firstOrFail();

        $this->assertSame('semantic_fallback', (string) $chunk->chunk_strategy);
        Http::assertNothingSent();
    }

    public function test_sync_does_not_leave_an_invalid_bound_system_embedding_model(): void
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

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $invalidDefault->id],
        );

        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Fallback Model 知识库',
            'description' => '',
            'content' => '默认 embedding 模型无效时应该自动选择下一个可用模型。',
            'character_count' => 32,
            'file_type' => 'markdown',
            'word_count' => 32,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            '默认 embedding 模型无效时应该自动选择下一个可用模型。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertNull($chunk->embedding_model_id);
        $this->assertCount(256, json_decode((string) $chunk->embedding_json, true));
        $this->assertSame(0, (int) $fallbackModel->fresh()->used_today);
        Http::assertNothingSent();
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

        $this->syncKnowledge(
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

    public function test_sync_uses_volcengine_doubao_embedding_as_openai_compatible_provider(): void
    {
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.21, 0.32, 0.43]],
                ],
            ]),
        ]);

        $model = $this->createEmbeddingModel([
            'name' => 'Doubao Embedding',
            'model_id' => 'doubao-embedding-text-240515',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '火山向量知识库',
            'description' => '',
            'content' => 'GEOFlow 支持火山方舟 Doubao Embedding。',
            'character_count' => 35,
            'file_type' => 'markdown',
            'word_count' => 35,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            'GEOFlow 支持火山方舟 Doubao Embedding，适合国内环境下的知识库向量化。'
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame((int) $model->id, (int) $chunk->embedding_model_id);
        $this->assertSame('https://ark.cn-beijing.volces.com/api/v3', (string) $chunk->embedding_provider);
        $this->assertSame([0.21, 0.32, 0.43], json_decode((string) $chunk->embedding_json, true));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/embeddings'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['model'] === 'doubao-embedding-text-240515'
            && ($request['input'][0] ?? '') === 'GEOFlow 支持火山方舟 Doubao Embedding，适合国内环境下的知识库向量化。'
            && ! array_key_exists('dimensions', (array) $request->data()));
    }

    public function test_sync_omits_dimensions_parameter_for_openai_compatible_embedding(): void
    {
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/embeddings' => function ($request) {
                // 模拟 doubao-embedding-text 对 dimensions 参数返回 InvalidParameter，
                // 验证我们的请求体不含该字段，从而不会触发该 400。
                if (array_key_exists('dimensions', (array) $request->data())) {
                    return Http::response([
                        'error' => [
                            'code' => 'InvalidParameter',
                            'message' => 'One or more parameters specified in the request are not valid.',
                        ],
                    ], 400);
                }

                return Http::response([
                    'data' => [
                        ['embedding' => [0.51, 0.52, 0.53]],
                    ],
                ]);
            },
        ]);

        $model = $this->createEmbeddingModel([
            'name' => 'Doubao Embedding',
            'model_id' => 'doubao-embedding-text-240515',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Doubao 维度参数知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            'GEOFlow 校验 Doubao Embedding 不发送 dimensions 参数。',
            true
        );

        $chunk = $knowledgeBase->chunks()->firstOrFail();

        $this->assertSame((int) $model->id, (int) $chunk->embedding_model_id);
        $this->assertSame([0.51, 0.52, 0.53], json_decode((string) $chunk->embedding_json, true));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/embeddings'
            && ! array_key_exists('dimensions', (array) $request->data()));
    }

    public function test_sync_maps_openai_compatible_embedding_response_by_index(): void
    {
        config(['geoflow.embedding_batch_size' => 3]);

        Http::fake([
            'https://ai.test/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 2, 'embedding' => [0.31, 0.32, 0.33]],
                    ['index' => 0, 'embedding' => [0.11, 0.12, 0.13]],
                    ['index' => 1, 'embedding' => [0.21, 0.22, 0.23]],
                ],
            ]),
        ]);

        $model = $this->createEmbeddingModel();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '响应顺序知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);

        $this->syncKnowledge(
            (int) $knowledgeBase->id,
            "# 第一节\n\n第一节内容。\n\n## 第二节\n\n第二节内容。\n\n## 第三节\n\n第三节内容。",
            true
        );

        $vectors = $knowledgeBase->chunks()
            ->orderBy('chunk_index')
            ->get()
            ->map(fn ($chunk): array => json_decode((string) $chunk->embedding_json, true))
            ->all();

        $this->assertSame([
            [0.11, 0.12, 0.13],
            [0.21, 0.22, 0.23],
            [0.31, 0.32, 0.33],
        ], $vectors);

        $model->refresh();
        $this->assertSame(1, (int) $model->used_today);
        $this->assertSame(1, (int) $model->total_used);
    }

    public function test_sync_splits_embedding_requests_into_configured_batch_size(): void
    {
        config(['geoflow.embedding_batch_size' => 3]);

        Http::fake([
            'https://ai.test/v1/embeddings' => function ($request) {
                $inputs = $request['input'] ?? [];
                $this->assertIsArray($inputs);
                $this->assertLessThanOrEqual(3, count($inputs));

                return Http::response([
                    'data' => array_map(
                        static fn (int $index): array => ['embedding' => [0.1 + $index, 0.2, 0.3]],
                        array_keys($inputs)
                    ),
                ]);
            },
        ]);

        $model = $this->createEmbeddingModel();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '批量向量知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);
        $content = collect(range(1, 8))
            ->map(static fn (int $index): string => "## 第 {$index} 节\n\n第 {$index} 节内容。")
            ->implode("\n\n");

        $this->syncKnowledge((int) $knowledgeBase->id, $content, true);

        $chunks = $knowledgeBase->chunks()->orderBy('chunk_index')->get();

        $this->assertCount(8, $chunks);
        $chunks->each(function ($chunk) use ($model): void {
            $this->assertSame((int) $model->id, (int) $chunk->embedding_model_id);
            $this->assertSame(3, (int) $chunk->embedding_dimensions);
        });

        Http::assertSentCount(3);

        $model->refresh();
        $this->assertSame(3, (int) $model->used_today);
        $this->assertSame(3, (int) $model->total_used);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertCount(1, $events->pluck('request_id')->unique());
        $this->assertCount(3, $events->pluck('call_key')->unique());
        $this->assertTrue($events->every(
            static fn (AiModelUsageEvent $event): bool => $event->status === AiModelUsageEvent::STATUS_SUCCEEDED,
        ));
    }

    public function test_sync_treats_provider_batch_parameter_rejection_as_permanent(): void
    {
        config(['geoflow.embedding_batch_size' => 4]);

        Http::fake([
            'https://ai.test/v1/embeddings' => function ($request) {
                $inputs = $request['input'] ?? [];
                $this->assertIsArray($inputs);

                if (count($inputs) > 1) {
                    return Http::response([
                        'error' => [
                            'message' => '<400> InternalError.Algo.InvalidParameter: Value error, batch size is invalid, it should not be larger than 1',
                        ],
                    ], 400);
                }

                return Http::response([
                    'data' => [
                        ['embedding' => [0.4, 0.5, 0.6]],
                    ],
                ]);
            },
        ]);

        $model = $this->createEmbeddingModel();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '单条降级向量知识库',
            'description' => '',
            'content' => '',
            'character_count' => 0,
            'file_type' => 'markdown',
            'word_count' => 0,
        ]);
        $content = collect(range(1, 4))
            ->map(static fn (int $index): string => "## 第 {$index} 节\n\n第 {$index} 节内容。")
            ->implode("\n\n");

        $this->expectException(PermanentAiProviderException::class);
        try {
            $this->syncKnowledge((int) $knowledgeBase->id, $content, true);
        } finally {
            Http::assertSentCount(1);
            $this->assertSame('failed', (string) $knowledgeBase->fresh()->chunk_sync_status);
            $this->assertSame(0, $knowledgeBase->chunks()->count());
            $this->assertSame(0, (int) $model->fresh()->used_today);
            $this->assertSame(
                AiModelUsageEvent::STATUS_FAILED,
                AiModelUsageEvent::query()->sole()->status,
            );
        }
    }

    public function test_query_embedding_uses_gemini_search_result_prefix_without_task_type(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents' => Http::response([
                'embeddings' => [
                    ['values' => [0.7, 0.8, 0.9]],
                ],
            ]),
        ]);

        $this->createEmbeddingModel([
            'name' => 'Gemini Embedding 2',
            'model_id' => 'gemini-embedding-2',
            'api_url' => 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent',
        ]);

        $vector = $this->queryEmbeddingVector('如何使用 GEOFlow?');

        $this->assertSame([0.7, 0.8, 0.9], $vector);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:batchEmbedContents'
            && $request->hasHeader('x-goog-api-key', 'test-api-key')
            && ($request['requests'][0]['content']['parts'][0]['text'] ?? '') === 'task: search result | query: 如何使用 GEOFlow?'
            && ! isset($request['requests'][0]['taskType'])
            && ! isset($request['taskType']));
    }

    public function test_query_embedding_omits_dimensions_parameter_for_openai_compatible_embedding(): void
    {
        Http::fake([
            'https://ark.cn-beijing.volces.com/api/v3/embeddings' => function ($request) {
                if (array_key_exists('dimensions', (array) $request->data())) {
                    return Http::response([
                        'error' => [
                            'code' => 'InvalidParameter',
                            'message' => 'dimensions is not supported',
                        ],
                    ], 400);
                }

                return Http::response([
                    'data' => [
                        ['index' => 0, 'embedding' => [0.61, 0.62, 0.63]],
                    ],
                ]);
            },
        ]);

        $this->createEmbeddingModel([
            'name' => 'Doubao Embedding',
            'model_id' => 'doubao-embedding-text-240515',
            'api_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        ]);

        $vector = $this->queryEmbeddingVector('GEOFlow 知识库如何向量化？');

        $this->assertSame([0.61, 0.62, 0.63], $vector);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ark.cn-beijing.volces.com/api/v3/embeddings'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request['model'] === 'doubao-embedding-text-240515'
            && ($request['input'][0] ?? '') === 'GEOFlow 知识库如何向量化？'
            && ! array_key_exists('dimensions', (array) $request->data()));
    }

    private function syncKnowledge(int $knowledgeBaseId, string $content, bool $requireRealEmbedding = false): int
    {
        KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
        ]);

        return app(KnowledgeChunkSyncService::class)->sync(
            $knowledgeBaseId,
            $content,
            SystemAiIdentity::knowledgeIndex(),
            $requireRealEmbedding,
        );
    }

    /** @return list<float> */
    private function queryEmbeddingVector(string $query): array
    {
        $model = AiModel::query()->latest('id')->firstOrFail();
        $admin = Admin::query()->create([
            'username' => 'query-admin',
            'display_name' => 'Query Admin',
            'password' => 'secret',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $admin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $knowledgeBase = new KnowledgeBase;
        $knowledgeBase->forceFill([
            'chunk_embedding_fingerprint' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, 3),
            'chunk_embedding_dimensions' => 3,
            'chunk_embedding_provider' => app(KnowledgeEmbeddingModelFingerprint::class)->provider($model),
            'chunk_embedding_model_id' => $model->id,
            'chunk_embedding_profile_version' => app(KnowledgeEmbeddingModelFingerprint::class)->profileVersion(),
            'chunk_embedding_profile_digest' => app(KnowledgeEmbeddingModelFingerprint::class)->forModel($model, 3),
        ]);

        return app(KnowledgeChunkSyncService::class)
            ->generateCompatibleQueryEmbedding($query, $knowledgeBase, $admin)
            ->vector;
    }

    private function createEmbeddingModel(array $overrides = []): AiModel
    {
        $model = new AiModel;
        $model->forceFill(array_merge([
            'owner_admin_id' => $this->systemOwner()->id,
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
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ], $overrides));
        $model->save();
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'default_embedding_model_id'],
            ['setting_value' => (string) $model->id],
        );

        return $model;
    }

    private function createChatModel(array $overrides = []): AiModel
    {
        $model = new AiModel;
        $model->forceFill(array_merge([
            'owner_admin_id' => $this->systemOwner()->id,
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
            'access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY,
        ], $overrides));
        $model->save();

        return $model;
    }

    private function systemOwner(): Admin
    {
        return Admin::query()->firstOrCreate(
            ['username' => 'knowledge-system-owner'],
            [
                'display_name' => 'Knowledge System Owner',
                'password' => 'secret',
                'role' => 'super_admin',
                'status' => 'active',
            ],
        );
    }
}
