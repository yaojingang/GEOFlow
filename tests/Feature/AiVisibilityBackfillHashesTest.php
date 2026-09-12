<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilityTopic;
use App\Models\AiVisibilityTopicKeyword;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiVisibilityBackfillHashesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_keyword_hashes_for_existing_runs(): void
    {
        $variantA = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 介绍',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);
        $variantB = AiVisibilityRun::create([
            'keyword' => 'geoflow  介绍',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);
        $distinct = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 定价',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);

        $this->artisan('geoflow:ai-visibility:backfill-keyword-hashes')
            ->expectsOutputToContain('3 runs updated, 0 auto-classified out of 3 scanned')
            ->assertSuccessful();

        $this->assertSame(AiVisibilityKeywordNormalizer::hash('GEOFlow 介绍'), $variantA->refresh()->keyword_hash);
        $this->assertSame(AiVisibilityKeywordNormalizer::hash('GEOFlow 定价'), $distinct->refresh()->keyword_hash);
        $this->assertSame(
            $variantA->refresh()->keyword_hash,
            $variantB->refresh()->keyword_hash,
            'Wording variants of the same keyword must share the identical hash.',
        );
        $this->assertNull($variantA->ai_visibility_topic_id);
        $this->assertNull($variantB->ai_visibility_topic_id);
        $this->assertNull($distinct->ai_visibility_topic_id);
    }

    public function test_auto_classifies_runs_matching_registered_topic_bindings(): void
    {
        $topic = AiVisibilityTopic::create([
            'name' => '回填归属主题',
        ]);
        AiVisibilityTopicKeyword::create([
            'ai_visibility_topic_id' => $topic->id,
            'keyword' => 'GEOFlow 教程',
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow 教程'),
        ]);

        $matched = AiVisibilityRun::create([
            'keyword' => 'geoflow 教程',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);
        $unmatched = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 案例',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);

        $this->artisan('geoflow:ai-visibility:backfill-keyword-hashes')
            ->expectsOutputToContain('2 runs updated, 1 auto-classified out of 2 scanned')
            ->assertSuccessful();

        $this->assertSame($topic->id, $matched->refresh()->ai_visibility_topic_id);
        $this->assertNull($unmatched->refresh()->ai_visibility_topic_id);
    }

    public function test_is_idempotent_when_rerun(): void
    {
        AiVisibilityRun::create([
            'keyword' => 'GEOFlow 定价',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);

        $this->artisan('geoflow:ai-visibility:backfill-keyword-hashes')
            ->expectsOutputToContain('1 runs updated, 0 auto-classified out of 1 scanned')
            ->assertSuccessful();

        $this->artisan('geoflow:ai-visibility:backfill-keyword-hashes')
            ->expectsOutputToContain('0 runs updated, 0 auto-classified out of 0 scanned')
            ->assertSuccessful();

        $this->assertSame(
            1,
            AiVisibilityRun::query()->whereNotNull('keyword_hash')->count(),
        );
    }

    public function test_punctuation_only_keyword_records_sentinel_hash_without_topic_attach(): void
    {
        $run = AiVisibilityRun::create([
            'keyword' => '!!!',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
        ]);

        $this->artisan('geoflow:ai-visibility:backfill-keyword-hashes')
            ->expectsOutputToContain('1 runs updated, 0 auto-classified out of 1 scanned')
            ->assertSuccessful();

        $this->assertSame(hash('sha256', ''), $run->refresh()->keyword_hash);
        $this->assertNull($run->ai_visibility_topic_id);
    }
}
