<?php

namespace Tests\Unit;

use App\Support\GeoFlow\AiQualityRetrievalBasis;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use PHPUnit\Framework\TestCase;

class AiQualityRetrievalBasisTest extends TestCase
{
    public function test_hash_is_stable_for_equivalent_associative_input_and_sensitive_to_mode(): void
    {
        $first = AiQualityRetrievalBasis::make(
            AiQualityRetrievalMode::CHUNK,
            4,
            [['id' => 9, 'generation' => 'g1', 'source_hash' => 'abc']],
            ['epoch' => 3, 'atomic_fact_frozen' => false],
        );
        $same = AiQualityRetrievalBasis::make(
            AiQualityRetrievalMode::CHUNK,
            4,
            [['source_hash' => 'abc', 'generation' => 'g1', 'id' => 9]],
            ['atomic_fact_frozen' => false, 'epoch' => 3],
        );
        $different = AiQualityRetrievalBasis::make(
            AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            4,
            [['id' => 9, 'generation' => 'g1', 'source_hash' => 'abc']],
            ['epoch' => 3, 'atomic_fact_frozen' => false],
        );

        $this->assertSame($first->hash(), $same->hash());
        $this->assertNotSame($first->hash(), $different->hash());
        $this->assertSame(AiQualityRetrievalMode::CHUNK, $first->toArray()['retrieval_mode']);
    }

    public function test_hash_changes_with_strategy_version_and_execution_budget(): void
    {
        $first = AiQualityRetrievalBasis::make('chunk', 1, [], ['epoch' => 1], 'chunk-1', ['max_evidence' => 12]);
        $newStrategy = AiQualityRetrievalBasis::make('chunk', 1, [], ['epoch' => 1], 'chunk-2', ['max_evidence' => 12]);
        $newBudget = AiQualityRetrievalBasis::make('chunk', 1, [], ['epoch' => 1], 'chunk-1', ['max_evidence' => 16]);

        $this->assertNotSame($first->hash(), $newStrategy->hash());
        $this->assertNotSame($first->hash(), $newBudget->hash());
    }
}
