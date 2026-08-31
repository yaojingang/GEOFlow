<?php

namespace Tests\Unit;

use App\Support\GeoFlow\AiQualityRetrievalMode;
use PHPUnit\Framework\TestCase;

class AiQualityRetrievalModeTest extends TestCase
{
    public function test_modes_have_a_stable_precision_order_and_labels(): void
    {
        $this->assertSame([
            AiQualityRetrievalMode::ATOMIC_FIRST,
            AiQualityRetrievalMode::CHUNK,
            AiQualityRetrievalMode::KNOWLEDGE_BROAD,
        ], AiQualityRetrievalMode::values());

        $this->assertSame('原子质检', AiQualityRetrievalMode::label(AiQualityRetrievalMode::ATOMIC_FIRST));
        $this->assertSame('切片质检', AiQualityRetrievalMode::label(AiQualityRetrievalMode::CHUNK));
        $this->assertSame('知识库质检', AiQualityRetrievalMode::label(AiQualityRetrievalMode::KNOWLEDGE_BROAD));
        $this->assertSame(AiQualityRetrievalMode::CHUNK, AiQualityRetrievalMode::legacyDefault());
    }
}
