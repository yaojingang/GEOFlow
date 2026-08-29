<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityEvidenceCache;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class ArticleAiQualityEvidenceCacheTest extends TestCase
{
    public function test_exact_fingerprints_reuse_evidence_and_knowledge_changes_miss_the_cache(): void
    {
        Cache::clear();
        config()->set('geoflow.ai_quality_evidence_cache_enabled', true);
        $calls = 0;
        $cache = new ArticleAiQualityEvidenceCache;
        $context = [
            'article_content_hash' => 'article-v1',
            'knowledge_hash' => 'knowledge-v1',
            'claim_hashes' => ['claim-a'],
            'retrieval_version' => 2,
        ];
        $resolver = function () use (&$calls): array {
            $calls++;

            return ['evidence' => [['stable_key' => '1:2:hash']], 'fact_candidates' => [], 'knowledge_coverage' => 'sufficient'];
        };

        $first = $cache->remember($context, $resolver);
        $second = $cache->remember($context, $resolver);
        $changed = $cache->remember(array_replace($context, ['knowledge_hash' => 'knowledge-v2']), $resolver);

        $this->assertFalse($first['hit']);
        $this->assertTrue($second['hit']);
        $this->assertFalse($changed['hit']);
        $this->assertSame(2, $calls);
        $this->assertSame($first['value'], $second['value']);
        $this->assertNotSame($first['key'], $changed['key']);
    }

    public function test_resolver_failures_are_not_retried_inside_the_cache_boundary(): void
    {
        Cache::clear();
        config()->set('geoflow.ai_quality_evidence_cache_enabled', true);
        $calls = 0;

        $this->expectException(RuntimeException::class);
        try {
            (new ArticleAiQualityEvidenceCache)->remember(['article' => 'broken'], function () use (&$calls): array {
                $calls++;

                throw new RuntimeException('retrieval failed');
            });
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
