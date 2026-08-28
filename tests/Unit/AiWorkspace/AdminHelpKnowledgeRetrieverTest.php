<?php

namespace Tests\Unit\AiWorkspace;

use App\Models\Admin;
use App\Models\KnowledgeChunk;
use App\Services\AiWorkspace\AdminHelpKnowledgeCatalog;
use App\Services\AiWorkspace\AdminHelpKnowledgeRetriever;
use App\Services\AiWorkspace\SystemKnowledgeBaseManager;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdminHelpKnowledgeRetrieverTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_bundled_markdown_is_available_when_the_system_record_is_missing(): void
    {
        Cache::flush();
        $admin = $this->admin();
        $catalog = app(AdminHelpKnowledgeCatalog::class);

        $result = app(AdminHelpKnowledgeRetriever::class)->retrieve(
            $admin,
            '如何创建一个内容任务？',
            $catalog->search($admin, '如何创建一个内容任务？'),
        );

        self::assertSame('markdown_fallback', $result['retrieval_mode']);
        self::assertSame('fallback', $result['knowledge_health']);
        self::assertStringContainsString('任务', $result['context']);
        self::assertNotSame([], $result['sources']);
        self::assertLessThanOrEqual(8, $result['evidence_count']);
        self::assertLessThanOrEqual(11_000, mb_strlen($result['context'], 'UTF-8'));
    }

    public function test_exact_feature_match_uses_the_local_index_without_calling_embedding_retrieval(): void
    {
        Queue::fake();
        Cache::flush();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $contentHash = hash('sha256', (string) $knowledgeBase->content);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'chunk_index' => 0,
            'content' => '创建任务时，进入任务创建页，选择模型、标题库、知识库和分发渠道。[[route:admin.tasks.create|创建任务]]',
            'content_hash' => hash('sha256', 'task chunk'),
            'source_hash' => hash('sha256', 'task source'),
            'chunk_title' => '任务创建',
            'section_path' => '内容生产与任务 > 创建任务',
            'chunk_strategy' => 'structured_rule',
            'token_count' => 40,
        ]);
        $knowledgeBase->forceFill([
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $contentHash,
            'chunk_sync_error' => null,
        ])->save();
        $admin = $this->admin();
        $catalog = app(AdminHelpKnowledgeCatalog::class);
        $hybridRetrieval = $this->mock(KnowledgeRetrievalService::class);
        $hybridRetrieval->shouldNotReceive('retrieveEvidence');

        $result = app(AdminHelpKnowledgeRetriever::class)->retrieve(
            $admin,
            '怎么创建任务？',
            $catalog->search($admin, '怎么创建任务？'),
        );

        self::assertSame('local_index', $result['retrieval_mode']);
        self::assertNull($result['sources'][0]['chunk_id']);
        self::assertSame('2026.08.28.1', $result['sources'][0]['official_version']);
        self::assertContains('tasks', collect($result['sources'])->pluck('feature_id')->filter()->all());
        self::assertContains('admin.tasks.create', $result['related_route_names']);
        self::assertStringNotContainsString('[[route:', $result['context']);
        self::assertStringContainsString('创建任务', $result['context']);
    }

    public function test_irrelevant_hybrid_candidates_are_rejected_before_context_is_built(): void
    {
        Queue::fake();
        Cache::flush();
        $knowledgeBase = app(SystemKnowledgeBaseManager::class)->sync()['knowledge_base'];
        $contentHash = hash('sha256', (string) $knowledgeBase->content);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->getKey(),
            'chunk_index' => 0,
            'content' => '任务管理使用队列异步生成文章。',
            'content_hash' => hash('sha256', 'irrelevant chunk'),
            'source_hash' => hash('sha256', 'irrelevant source'),
            'chunk_title' => '任务管理',
            'section_path' => '任务管理 > 运行原理',
            'chunk_strategy' => 'structured_rule',
            'token_count' => 20,
        ]);
        $knowledgeBase->forceFill([
            'chunk_sync_status' => 'ready',
            'chunk_source_hash' => $contentHash,
            'chunk_sync_error' => null,
        ])->save();
        $admin = $this->admin();
        $catalog = app(AdminHelpKnowledgeCatalog::class);
        $hybridRetrieval = $this->mock(KnowledgeRetrievalService::class);
        $hybridRetrieval->shouldReceive('retrieveEvidence')->once()->andReturn([[
            'chunk_id' => 1,
            'chunk_index' => 0,
            'content' => '任务管理使用队列异步生成文章。',
            'section_path' => '任务管理 > 运行原理',
            'score' => 0.12,
            'vector_score' => 0.10,
            'keyword_score' => 0.0,
            'title_score' => 0.0,
        ]]);

        $result = app(AdminHelpKnowledgeRetriever::class)->retrieve(
            $admin,
            '请帮我查询明天上海的天气。',
            $catalog->search($admin, '请帮我查询明天上海的天气。'),
        );

        self::assertSame('markdown_fallback', $result['retrieval_mode']);
        self::assertSame('ready_index_no_match', $result['fallback_reason']);
        self::assertStringNotContainsString('任务管理使用队列', $result['context']);
        self::assertSame([], collect($result['sources'])->pluck('feature_id')->filter()->all());
    }

    private function admin(): Admin
    {
        return new Admin([
            'id' => 71,
            'username' => 'retrieval-admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
