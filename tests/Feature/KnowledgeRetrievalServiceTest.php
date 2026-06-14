<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeRetrievalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retrieval_context_includes_evidence_metadata_and_citation_ids(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => 'GEOFlow 白皮书',
            'source_name' => 'GEOFlow 官方文档',
            'source_url' => 'https://example.com/geoflow-whitepaper',
            'business_line' => 'GEO 内容工程',
            'effective_date' => '2026-05-01',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'GEOFlow 负责把知识库、标题库和分发渠道串联为内容工程流程。',
            'content_hash' => hash('sha256', 'chunk-0'),
            'chunk_title' => '平台定位',
            'section_path' => 'GEOFlow 白皮书 > 平台定位',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-0'),
            'token_count' => 30,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContext(
            (int) $knowledgeBase->id,
            'GEOFlow 内容工程 知识库 分发渠道',
            1,
            1600
        );

        $this->assertStringContainsString('【知识库证据】', $context);
        $this->assertStringContainsString('【证据 K1】', $context);
        $this->assertStringContainsString('标题：平台定位', $context);
        $this->assertStringContainsString('章节：GEOFlow 白皮书 > 平台定位', $context);
        $this->assertStringContainsString('来源：GEOFlow 官方文档', $context);
        $this->assertStringContainsString('链接：https://example.com/geoflow-whitepaper', $context);
        $this->assertStringContainsString('业务线：GEO 内容工程', $context);
        $this->assertStringContainsString('治理：风险=low 审核=reviewed', $context);
        $this->assertStringContainsString('GEOFlow 负责把知识库', $context);
    }

    public function test_hybrid_retrieval_ranks_keyword_and_section_match_first(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '业务知识库',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '咖啡豆烘焙流程主要关注产地、水洗处理和烘焙曲线。',
            'content_hash' => hash('sha256', 'chunk-0'),
            'chunk_title' => '咖啡工艺',
            'section_path' => '资料 > 咖啡工艺',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-0'),
            'token_count' => 20,
            'embedding_json' => '[]',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 1,
            'content' => '品牌 GEO 诊断需要先识别问题定位，再输出内容、技术和声誉三类优化建议。',
            'content_hash' => hash('sha256', 'chunk-1'),
            'chunk_title' => '品牌 GEO 诊断流程',
            'section_path' => 'GEO 服务 > 诊断流程',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-1'),
            'token_count' => 32,
            'embedding_json' => '[]',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            (int) $knowledgeBase->id,
            '品牌 GEO 诊断 优化建议',
            2
        );

        $this->assertSame(1, (int) ($evidence[0]['chunk_index'] ?? -1));
        $this->assertGreaterThan(
            (float) ($evidence[1]['score'] ?? 0.0),
            (float) ($evidence[0]['score'] ?? 0.0)
        );
    }

    public function test_high_risk_unreviewed_knowledge_is_excluded_from_retrieval(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '未审核高风险知识库',
            'risk_level' => 'high',
            'review_status' => 'unreviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '这段高风险内容即使命中关键词，也不能进入生成上下文。',
            'content_hash' => hash('sha256', 'chunk-0'),
            'chunk_title' => '高风险内容',
            'section_path' => '治理 > 高风险',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-0'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContext(
            (int) $knowledgeBase->id,
            '高风险内容',
            1,
            1200
        );

        $this->assertSame('', $context);
    }

    public function test_high_risk_reviewed_knowledge_can_be_used_as_evidence(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '已审核高风险知识库',
            'risk_level' => 'high',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '已审核的高风险资料可以作为证据，但生成时仍要保留治理标记。',
            'content_hash' => hash('sha256', 'chunk-0'),
            'chunk_title' => '已审核风险说明',
            'section_path' => '治理 > 已审核',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-0'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContext(
            (int) $knowledgeBase->id,
            '已审核 高风险 证据',
            1,
            1200
        );

        $this->assertStringContainsString('【证据 K1】', $context);
        $this->assertStringContainsString('治理：风险=high 审核=reviewed', $context);
    }

    public function test_large_knowledge_base_uses_keyword_prefilter_and_avoids_unrelated_evidence(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '大型知识库',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        for ($index = 0; $index < 520; $index++) {
            KnowledgeChunk::query()->create([
                'knowledge_base_id' => (int) $knowledgeBase->id,
                'chunk_index' => $index,
                'content' => '咖啡豆烘焙流程关注产地、水洗处理和烘焙曲线。编号 '.$index,
                'content_hash' => hash('sha256', 'large-unrelated-'.$index),
                'chunk_title' => '咖啡工艺 '.$index,
                'section_path' => '资料 > 咖啡工艺',
                'chunk_strategy' => 'structured_rule',
                'metadata_json' => '{}',
                'source_hash' => hash('sha256', 'source-large-unrelated-'.$index),
                'token_count' => 24,
                'embedding_json' => '[]',
            ]);
        }

        $this->assertSame([], app(KnowledgeRetrievalService::class)->retrieveEvidence(
            (int) $knowledgeBase->id,
            '品牌 GEO 诊断 优化建议',
            3
        ));

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 520,
            'content' => '品牌 GEO 诊断需要先识别问题定位，再输出内容、技术和声誉三类优化建议。',
            'content_hash' => hash('sha256', 'large-relevant'),
            'chunk_title' => '品牌 GEO 诊断流程',
            'section_path' => 'GEO 服务 > 诊断流程',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-large-relevant'),
            'token_count' => 32,
            'embedding_json' => '[]',
        ]);

        $evidence = app(KnowledgeRetrievalService::class)->retrieveEvidence(
            (int) $knowledgeBase->id,
            '品牌 GEO 诊断 优化建议',
            3
        );

        $this->assertSame(520, (int) ($evidence[0]['chunk_index'] ?? -1));
    }

    public function test_conflicting_same_topic_evidence_prefers_newer_reviewed_content(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '产品知识库',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '标准版套餐价格是每年 100 元，适合旧版测试场景。',
            'content_hash' => hash('sha256', 'old-price'),
            'chunk_title' => '标准版套餐价格',
            'section_path' => '产品资料 > 价格政策',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => json_encode([
                'effective_date' => '2024-01-01',
                'review_status' => 'reviewed',
                'risk_level' => 'low',
            ], JSON_UNESCAPED_UNICODE),
            'source_hash' => hash('sha256', 'source-old-price'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 1,
            'content' => '标准版套餐价格是每年 200 元，适合当前正式报价。',
            'content_hash' => hash('sha256', 'new-price'),
            'chunk_title' => '标准版套餐价格',
            'section_path' => '产品资料 > 价格政策',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => json_encode([
                'effective_date' => '2026-05-01',
                'review_status' => 'reviewed',
                'risk_level' => 'low',
            ], JSON_UNESCAPED_UNICODE),
            'source_hash' => hash('sha256', 'source-new-price'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContext(
            (int) $knowledgeBase->id,
            '标准版 套餐 价格',
            2,
            1600
        );

        $this->assertStringContainsString('标准版套餐价格是每年 200 元', $context);
        $this->assertStringNotContainsString('标准版套餐价格是每年 100 元', $context);
        $this->assertStringContainsString('冲突处理：已优先采用较新或已审核资料', $context);
    }

    public function test_same_topic_complementary_chunks_are_not_merged_without_version_signal(): void
    {
        $knowledgeBase = $this->createKnowledgeBase([
            'name' => '流程知识库',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'GEO 诊断第一步是收集品牌、业务线、关键词和现有内容。',
            'content_hash' => hash('sha256', 'process-step-1'),
            'chunk_title' => 'GEO 诊断流程',
            'section_path' => '服务资料 > GEO 诊断流程',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-process-step-1'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'chunk_index' => 1,
            'content' => 'GEO 诊断第二步是分析 AI 搜索可见性、引用场景和内容缺口。',
            'content_hash' => hash('sha256', 'process-step-2'),
            'chunk_title' => 'GEO 诊断流程',
            'section_path' => '服务资料 > GEO 诊断流程',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-process-step-2'),
            'token_count' => 22,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContext(
            (int) $knowledgeBase->id,
            'GEO 诊断 流程',
            2,
            1600
        );

        $this->assertStringContainsString('GEO 诊断第一步', $context);
        $this->assertStringContainsString('GEO 诊断第二步', $context);
        $this->assertStringNotContainsString('冲突处理：', $context);
    }

    public function test_multi_knowledge_base_retrieval_merges_evidence_with_unique_citations(): void
    {
        $strategyKnowledgeBase = $this->createKnowledgeBase([
            'name' => 'GEO 策略知识库',
            'source_name' => '策略文档',
            'business_line' => '策略',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);
        $distributionKnowledgeBase = $this->createKnowledgeBase([
            'name' => '分发执行知识库',
            'source_name' => '分发手册',
            'business_line' => '分发',
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);

        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $strategyKnowledgeBase->id,
            'chunk_index' => 0,
            'content' => '品牌 GEO 策略需要先明确业务线、目标关键词和可信证据来源。',
            'content_hash' => hash('sha256', 'multi-kb-strategy'),
            'chunk_title' => '策略准备',
            'section_path' => 'GEO 策略 > 准备',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-multi-kb-strategy'),
            'token_count' => 28,
            'embedding_json' => '[]',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => (int) $distributionKnowledgeBase->id,
            'chunk_index' => 0,
            'content' => '多站分发执行需要记录目标渠道、发布范围和远端同步状态。',
            'content_hash' => hash('sha256', 'multi-kb-distribution'),
            'chunk_title' => '分发执行',
            'section_path' => '内容分发 > 执行',
            'chunk_strategy' => 'structured_rule',
            'metadata_json' => '{}',
            'source_hash' => hash('sha256', 'source-multi-kb-distribution'),
            'token_count' => 26,
            'embedding_json' => '[]',
        ]);

        $context = app(KnowledgeRetrievalService::class)->retrieveContextFromMany(
            [(int) $strategyKnowledgeBase->id, (int) $distributionKnowledgeBase->id],
            '品牌 GEO 策略 多站 分发 同步 状态',
            4,
            2400
        );

        $this->assertStringContainsString('【证据 K1】', $context);
        $this->assertStringContainsString('【证据 K2】', $context);
        $this->assertStringContainsString('知识库：GEO 策略知识库', $context);
        $this->assertStringContainsString('知识库：分发执行知识库', $context);
        $this->assertStringContainsString('品牌 GEO 策略需要先明确业务线', $context);
        $this->assertStringContainsString('多站分发执行需要记录目标渠道', $context);
    }

    private function createKnowledgeBase(array $overrides = []): KnowledgeBase
    {
        return KnowledgeBase::query()->create(array_merge([
            'name' => '测试知识库',
            'description' => '',
            'content' => '测试内容',
            'character_count' => 4,
            'file_type' => 'markdown',
            'word_count' => 4,
            'source_name' => '',
            'source_url' => '',
            'source_type' => 'document',
            'business_line' => '',
            'effective_date' => null,
            'risk_level' => 'medium',
            'review_status' => 'unreviewed',
        ], $overrides));
    }
}
