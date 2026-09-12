<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use PHPUnit\Framework\TestCase;

class AiVisibilityKeywordNormalizerTest extends TestCase
{
    public function test_it_normalizes_case_whitespace_and_punctuation(): void
    {
        $this->assertSame('新东方怎么样', AiVisibilityKeywordNormalizer::normalize('新东方 怎么样？'));
        $this->assertSame('xdf怎么样', AiVisibilityKeywordNormalizer::normalize('XDF 怎么样！'));
        $this->assertSame('新东方怎么样', AiVisibilityKeywordNormalizer::normalize('  新东方 怎么样？  '));
        $this->assertSame('新东方怎么样', AiVisibilityKeywordNormalizer::normalize('新东方，怎么样！'));
    }

    public function test_it_hashes_stably(): void
    {
        $hash = AiVisibilityKeywordNormalizer::hash('新东方 怎么样？');
        $this->assertSame($hash, AiVisibilityKeywordNormalizer::hash('新东方怎么样'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }
}
