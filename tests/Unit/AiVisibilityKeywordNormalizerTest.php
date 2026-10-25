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
        $this->assertSame('xdf怎么样', AiVisibilityKeywordNormalizer::normalize('ＸＤＦ 怎么样！'));
        $this->assertSame(AiVisibilityKeywordNormalizer::hash('XDF 怎么样！'), AiVisibilityKeywordNormalizer::hash('ＸＤＦ 怎么样！'));
    }

    public function test_it_hashes_stably(): void
    {
        $hash = AiVisibilityKeywordNormalizer::hash('新东方 怎么样？');
        $this->assertSame($hash, AiVisibilityKeywordNormalizer::hash('新东方怎么样'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_it_collapses_empty_or_symbol_only_keywords_to_empty_string(): void
    {
        $this->assertSame('', AiVisibilityKeywordNormalizer::normalize(' ？？？ '));
        $this->assertSame(AiVisibilityKeywordNormalizer::hash('  '), AiVisibilityKeywordNormalizer::hash(''));
        $this->assertSame(hash('sha256', ''), AiVisibilityKeywordNormalizer::hash(''));
    }
}
