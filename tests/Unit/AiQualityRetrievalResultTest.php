<?php

namespace Tests\Unit;

use App\Support\GeoFlow\AiQualityRetrievalResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AiQualityRetrievalResultTest extends TestCase
{
    public function test_it_rejects_evidence_that_does_not_follow_the_shared_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ai_quality_retrieval_evidence_contract_invalid');

        new AiQualityRetrievalResult([
            'evidence' => [['id' => 'K1', 'content' => '证据']],
            'fact_candidates' => [],
            'knowledge_coverage' => 'sufficient',
            'effective_retrieval_mode' => 'chunk',
            'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
            'retrieval_meta' => ['path' => ['chunk']],
        ]);
    }

    public function test_it_accepts_the_complete_shared_evidence_contract(): void
    {
        $result = new AiQualityRetrievalResult([
            'evidence' => [[
                'id' => 'K1',
                'evidence_id' => 'K1',
                'knowledge_base_id' => 1,
                'content' => '证据',
                'content_hash' => hash('sha256', '证据'),
                'source_hash' => str_repeat('a', 64),
                'section_path' => '产品/价格',
                'source_offset_start' => null,
                'source_offset_end' => null,
                'retrieval_strategy' => 'chunk',
                'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
                'governance_status' => 'reviewed',
                'coverage_meta' => ['provider' => 'chunk'],
            ]],
            'fact_candidates' => [],
            'knowledge_coverage' => 'sufficient',
            'effective_retrieval_mode' => 'chunk',
            'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
            'retrieval_meta' => ['path' => ['chunk']],
        ]);

        $this->assertSame('K1', $result->toArray()['evidence'][0]['evidence_id']);
    }

    public function test_it_rejects_evidence_without_traceable_source_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ai_quality_retrieval_evidence_contract_invalid');

        new AiQualityRetrievalResult([
            'evidence' => [[
                'id' => 'A1',
                'evidence_id' => 'A1',
                'knowledge_base_id' => 0,
                'content' => '原子事实',
                'content_hash' => hash('sha256', '原子事实'),
                'source_hash' => '',
                'section_path' => 'atomic_facts',
                'source_offset_start' => null,
                'source_offset_end' => null,
                'retrieval_strategy' => 'atomic_first',
                'retrieval_strategy_version' => 'atomic-first-1.3.0',
                'governance_status' => 'reviewed',
                'coverage_meta' => ['provider' => 'atomic'],
            ]],
            'fact_candidates' => [],
            'knowledge_coverage' => 'sufficient',
            'effective_retrieval_mode' => 'atomic_first',
            'retrieval_strategy_version' => 'atomic-first-1.3.0',
            'retrieval_meta' => ['path' => ['atomic']],
        ]);
    }

    public function test_it_rejects_mismatched_content_hashes_and_invalid_offsets(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ai_quality_retrieval_evidence_contract_invalid');

        new AiQualityRetrievalResult([
            'evidence' => [[
                'id' => 'K1',
                'evidence_id' => 'K1',
                'knowledge_base_id' => 1,
                'content' => '证据',
                'content_hash' => str_repeat('a', 64),
                'source_hash' => str_repeat('b', 64),
                'section_path' => '产品/价格',
                'source_offset_start' => 20,
                'source_offset_end' => 10,
                'retrieval_strategy' => 'chunk',
                'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
                'governance_status' => 'reviewed',
                'coverage_meta' => ['provider' => 'chunk'],
            ]],
            'fact_candidates' => [],
            'knowledge_coverage' => 'sufficient',
            'effective_retrieval_mode' => 'chunk',
            'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
            'retrieval_meta' => ['path' => ['chunk']],
        ]);
    }

    public function test_it_rejects_duplicate_evidence_ids(): void
    {
        $evidence = [
            'id' => 'K1',
            'evidence_id' => 'K1',
            'knowledge_base_id' => 1,
            'content' => '证据',
            'content_hash' => hash('sha256', '证据'),
            'source_hash' => str_repeat('b', 64),
            'section_path' => '产品/价格',
            'source_offset_start' => null,
            'source_offset_end' => null,
            'retrieval_strategy' => 'chunk',
            'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
            'governance_status' => 'reviewed',
            'coverage_meta' => ['provider' => 'chunk'],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ai_quality_retrieval_evidence_contract_invalid');

        new AiQualityRetrievalResult([
            'evidence' => [$evidence, $evidence],
            'fact_candidates' => [],
            'knowledge_coverage' => 'sufficient',
            'effective_retrieval_mode' => 'chunk',
            'retrieval_strategy_version' => 'chunk-evidence-1.1.0',
            'retrieval_meta' => ['path' => ['chunk']],
        ]);
    }
}
