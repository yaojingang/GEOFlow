<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityEvidenceBuilder;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use PHPUnit\Framework\TestCase;

class ArticleAiQualityEvidenceBuilderTest extends TestCase
{
    public function test_it_assigns_stable_evidence_ids_and_preserves_coverage_for_every_fact(): void
    {
        $retrieval = $this->createMock(KnowledgeRetrievalService::class);
        $retrieval->expects($this->exactly(3))
            ->method('retrieveEvidenceFromMany')
            ->willReturnOnConsecutiveCalls(
                [$this->evidence(1, 2, '企业说明', 'reviewed')],
                [$this->evidence(1, 2, '企业说明', 'reviewed')],
                [],
            );

        $result = (new ArticleAiQualityEvidenceBuilder($retrieval))->build(
            [1],
            ['title' => '企业介绍', 'content' => '增长率 48%，获得行业认证。'],
            [
                ['id' => 'F1', 'quote' => '增长率 48%', 'materiality' => 'high'],
                ['id' => 'F2', 'quote' => '获得行业认证', 'materiality' => 'high'],
            ],
            8,
            4000,
        );

        $this->assertSame('K1', $result['evidence'][0]['id']);
        $this->assertCount(1, $result['evidence']);
        $this->assertSame('sufficient', $result['fact_candidates'][0]['coverage_status']);
        $this->assertSame(['K1'], $result['fact_candidates'][0]['knowledge_refs']);
        $this->assertSame('insufficient', $result['fact_candidates'][1]['coverage_status']);
        $this->assertSame('insufficient', $result['knowledge_coverage']);
    }

    public function test_it_requires_manual_review_when_the_knowledge_base_returns_no_usable_evidence(): void
    {
        $retrieval = $this->createMock(KnowledgeRetrievalService::class);
        $retrieval->expects($this->once())
            ->method('retrieveEvidenceFromMany')
            ->willReturn([]);

        $result = (new ArticleAiQualityEvidenceBuilder($retrieval))->build(
            [1],
            ['title' => '企业介绍', 'content' => '这是一篇待核查的企业介绍。'],
            [],
            8,
            4000,
        );

        $this->assertSame([], $result['evidence']);
        $this->assertSame('insufficient', $result['knowledge_coverage']);
    }

    public function test_it_marks_fact_candidates_beyond_the_retrieval_budget_as_uncovered(): void
    {
        $retrieval = $this->createMock(KnowledgeRetrievalService::class);
        $retrieval->expects($this->exactly(2))
            ->method('retrieveEvidenceFromMany')
            ->willReturnOnConsecutiveCalls(
                [$this->evidence(1, 2, '企业说明', 'reviewed')],
                [$this->evidence(1, 2, '企业说明', 'reviewed')],
            );

        $result = (new ArticleAiQualityEvidenceBuilder($retrieval))->build(
            [1],
            ['title' => '企业介绍', 'content' => '增长率 48%，标准价格 980 元。'],
            [
                ['id' => 'F1', 'quote' => '增长率 48%', 'materiality' => 'high'],
                ['id' => 'F2', 'quote' => '标准价格 980 元', 'materiality' => 'high'],
            ],
            8,
            4000,
            1,
        );

        $this->assertSame('sufficient', $result['fact_candidates'][0]['coverage_status']);
        $this->assertSame('budget_exceeded', $result['fact_candidates'][1]['retrieval_status']);
        $this->assertSame('insufficient', $result['fact_candidates'][1]['coverage_status']);
        $this->assertSame('insufficient', $result['knowledge_coverage']);
    }

    /** @return array<string, mixed> */
    private function evidence(int $knowledgeBaseId, int $chunkIndex, string $content, string $reviewStatus): array
    {
        return [
            'knowledge_base_id' => $knowledgeBaseId,
            'chunk_index' => $chunkIndex,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'source_hash' => 'source-v1',
            'chunk_title' => '说明',
            'section_path' => '数据',
            'metadata' => [
                'knowledge_base_id' => $knowledgeBaseId,
                'knowledge_base_name' => '测试知识库',
                'review_status' => $reviewStatus,
            ],
            'score' => 0.95,
        ];
    }
}
