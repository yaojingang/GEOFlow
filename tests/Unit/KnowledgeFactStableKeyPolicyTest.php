<?php

namespace Tests\Unit;

use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactStableKeyPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KnowledgeFactStableKeyPolicyTest extends TestCase
{
    #[DataProvider('genericKeys')]
    public function test_generic_sequence_keys_are_replaced_with_a_semantic_hash(string $key): void
    {
        $policy = new KnowledgeFactStableKeyPolicy;

        $normalized = $policy->normalize($key, 'GEOFlow 模型接入', '支持的 Provider 类型包括');

        self::assertMatchesRegularExpression('/\Afact\.[a-f0-9]{24}\z/', $normalized);
        self::assertSame($normalized, $policy->normalize('fact-999', 'GEOFlow 模型接入', '支持的 Provider 类型包括'));
    }

    public static function genericKeys(): array
    {
        return [['fact-1'], ['fact_002'], ['item.8'], ['atomic10']];
    }

    public function test_meaningful_domain_key_is_preserved(): void
    {
        $policy = new KnowledgeFactStableKeyPolicy;

        self::assertSame('product.public_version', $policy->normalize('product.public_version', 'GEOFlow', '当前公开版本为'));
    }

    public function test_different_labels_under_the_same_subject_and_predicate_do_not_collide(): void
    {
        $policy = new KnowledgeFactStableKeyPolicy;

        self::assertNotSame(
            $policy->normalize('fact-1', 'GEOFlow', '包括', '支持渠道'),
            $policy->normalize('fact-2', 'GEOFlow', '包括', '支持格式'),
        );
    }
}
