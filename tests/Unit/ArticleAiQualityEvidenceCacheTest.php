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

            return ['evidence' => [[
                'id' => 'K1',
                'knowledge_base_id' => 1,
                'stable_key' => '1:2:hash',
                'content_hash' => str_repeat('a', 64),
                'source_hash' => str_repeat('b', 64),
            ]], 'fact_candidates' => [], 'knowledge_coverage' => 'sufficient'];
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

    public function test_corrupted_cache_envelopes_are_deleted_and_recomputed(): void
    {
        Cache::clear();
        config()->set('geoflow.ai_quality_evidence_cache_enabled', true);
        $calls = 0;
        $cache = new ArticleAiQualityEvidenceCache;
        $context = ['article' => 'integrity-check'];
        $resolver = function () use (&$calls): array {
            $calls++;

            return [
                'evidence' => [[
                    'id' => 'K1',
                    'knowledge_base_id' => 9,
                    'stable_key' => '9:1:hash',
                    'content' => 'Verified evidence.',
                    'content_hash' => hash('sha256', 'Verified evidence.'),
                    'source_hash' => str_repeat('a', 64),
                ]],
                'fact_candidates' => [],
                'knowledge_coverage' => 'sufficient',
            ];
        };

        $first = $cache->remember($context, $resolver);
        Cache::put($first['key'], [
            'format_version' => 1,
            'context_hash' => str_repeat('b', 64),
            'value_hash' => str_repeat('c', 64),
            'byte_size' => 10,
            'value' => $first['value'],
        ]);
        $second = $cache->remember($context, $resolver);

        $this->assertFalse($second['hit']);
        $this->assertSame(2, $calls);
        $this->assertSame($first['value'], $second['value']);
    }

    public function test_invalid_resolver_payloads_are_returned_without_entering_the_cache(): void
    {
        Cache::clear();
        config()->set('geoflow.ai_quality_evidence_cache_enabled', true);
        $calls = 0;
        $cache = new ArticleAiQualityEvidenceCache;
        $resolver = function () use (&$calls): array {
            $calls++;

            return ['evidence' => [], 'fact_candidates' => []];
        };

        $first = $cache->remember(['article' => 'invalid-payload'], $resolver);
        $second = $cache->remember(['article' => 'invalid-payload'], $resolver);

        $this->assertFalse($first['hit']);
        $this->assertFalse($second['hit']);
        $this->assertSame(2, $calls);
        $this->assertNull(Cache::get($first['key']));
    }
}
