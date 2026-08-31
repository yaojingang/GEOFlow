<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AtomicFirstEvidenceStrategy;
use App\Services\GeoFlow\ChunkEvidenceStrategy;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use App\Support\GeoFlow\AiQualityRetrievalResult;
use Mockery;
use Tests\TestCase;

class AtomicFirstEvidenceStrategyTest extends TestCase
{
    public function test_every_material_candidate_reaches_chunk_fallback_across_all_article_fields(): void
    {
        $snapshot = [
            'title' => 'Acme 事实核验',
            'excerpt' => 'Acme 员工人数为 999 人。',
            'content' => 'Acme员工人数为128人，同时2026年营收为999亿元。',
            'keywords' => 'Acme，2026年收入999亿元',
            'meta_description' => 'Acme 拥有 500 项专利。',
        ];
        $candidates = [
            ['id' => 'F1', 'field' => 'excerpt', 'quote' => $snapshot['excerpt'], 'materiality' => 'medium'],
            ['id' => 'F2', 'field' => 'content', 'quote' => $snapshot['content'], 'materiality' => 'high'],
            ['id' => 'F3', 'field' => 'keywords', 'quote' => $snapshot['keywords'], 'materiality' => 'high'],
            ['id' => 'F4', 'field' => 'meta_description', 'quote' => $snapshot['meta_description'], 'materiality' => 'medium'],
        ];

        $inspector = Mockery::mock(ArticleAtomicFactInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->withArgs(function (string $content, array $knowledgeBaseIds) use ($snapshot): bool {
                return $knowledgeBaseIds === [7]
                    && str_contains($content, $snapshot['excerpt'])
                    && str_contains($content, $snapshot['keywords'])
                    && str_contains($content, $snapshot['meta_description']);
            })
            ->andReturn([
                'fallback_content' => '',
                'results' => [[
                    'status' => 'supported',
                    'article_claim' => $snapshot['content'],
                    'stable_key' => 'company.employee_count',
                ]],
            ]);
        $chunk = Mockery::mock(ChunkEvidenceStrategy::class);
        $chunk->shouldReceive('build')
            ->once()
            ->withArgs(function (array $knowledgeBaseIds, array $fallbackSnapshot, array $facts): bool {
                return $knowledgeBaseIds === [7]
                    && $fallbackSnapshot['title'] === ''
                    && $fallbackSnapshot['excerpt'] === ''
                    && $fallbackSnapshot['content'] === ''
                    && $fallbackSnapshot['keywords'] === ''
                    && $fallbackSnapshot['meta_description'] === ''
                    && array_column($facts, 'id') === ['F1', 'F2', 'F3', 'F4'];
            })
            ->andReturn(new AiQualityRetrievalResult([
                'evidence' => [],
                'fact_candidates' => array_map(
                    static fn (array $candidate): array => array_replace($candidate, [
                        'knowledge_refs' => [],
                        'coverage_status' => 'insufficient',
                        'retrieval_status' => 'no_evidence',
                    ]),
                    $candidates,
                ),
                'knowledge_coverage' => 'insufficient',
            ]));

        $result = (new AtomicFirstEvidenceStrategy($inspector, $chunk))
            ->build([7], $snapshot, $candidates)
            ->toArray();

        $this->assertSame(['F1', 'F2', 'F3', 'F4'], array_column($result['fact_candidates'], 'id'));
        $this->assertSame('insufficient', $result['knowledge_coverage']);
        $this->assertSame(4, data_get($result, 'retrieval_meta.fallback_claim_count'));
        $this->assertSame(['atomic', 'chunk_fallback'], data_get($result, 'retrieval_meta.path'));
    }

    public function test_atomic_supported_fact_skips_chunk_fallback_and_keeps_reviewed_evidence(): void
    {
        $snapshot = ['content' => 'Acme 员工人数为 128 人。'];
        $candidates = [[
            'id' => 'F1',
            'field' => 'content',
            'quote' => '员工人数为 128 人',
            'normalized_claim' => '员工人数为 128 人',
            'materiality' => 'high',
        ]];
        $inspector = Mockery::mock(ArticleAtomicFactInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn([
            'fallback_content' => '',
            'results' => [[
                'status' => 'supported',
                'article_claim' => 'Acme 员工人数为 128 人。',
                'stable_key' => 'company.employee_count',
                'label' => '员工人数',
                'standard_answer' => '128 人',
                'revision_id' => 9,
                'revision_version' => 3,
            ]],
        ]);
        $chunk = Mockery::mock(ChunkEvidenceStrategy::class);
        $chunk->shouldReceive('build')
            ->once()
            ->withArgs(static fn (array $_ids, array $_snapshot, array $facts): bool => $facts === [])
            ->andReturn(new AiQualityRetrievalResult([
                'evidence' => [],
                'fact_candidates' => [],
                'knowledge_coverage' => 'sufficient',
            ]));

        $result = (new AtomicFirstEvidenceStrategy($inspector, $chunk))
            ->build([7], $snapshot, $candidates)
            ->toArray();

        $this->assertSame('A1', $result['evidence'][0]['id']);
        $this->assertSame('reviewed', data_get($result, 'evidence.0.metadata.review_status'));
        $this->assertSame(['A1'], $result['fact_candidates'][0]['knowledge_refs']);
        $this->assertSame('sufficient', $result['knowledge_coverage']);
        $this->assertSame(0, data_get($result, 'retrieval_meta.fallback_claim_count'));
    }
}
