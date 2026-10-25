<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilityTopic;
use App\Models\AiVisibilityTopicKeyword;
use App\Services\GeoFlow\AiVisibility\AiVisibilityKeywordNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiVisibilityTopicModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_topics_and_keyword_bindings_persist_with_unique_hash(): void
    {
        $topic = AiVisibilityTopic::create([
            'name' => 'AI 可见性',
            'description' => 'GEOFlow AI 可见性主题',
            'brand_aliases' => ['GEOFlow', 'Geo Flow'],
        ]);

        $this->assertSame(['GEOFlow', 'Geo Flow'], $topic->brand_aliases);

        $binding = AiVisibilityTopicKeyword::create([
            'ai_visibility_topic_id' => $topic->id,
            'keyword' => 'GEOFlow AI 可见性',
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow AI 可见性'),
        ]);

        $this->assertTrue($topic->keywords->first()->is($binding));
        $this->assertTrue($binding->topic->is($topic));

        $this->expectException(QueryException::class);

        AiVisibilityTopicKeyword::create([
            'ai_visibility_topic_id' => $topic->id,
            'keyword' => 'GEOFlow AI 可见性 重复',
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow AI 可见性'),
        ]);
    }

    public function test_runs_can_belong_to_a_topic(): void
    {
        $topic = AiVisibilityTopic::create([
            'name' => '运行归属主题',
        ]);

        $run = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 介绍',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'ai_visibility_topic_id' => $topic->id,
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow 介绍'),
        ]);

        $this->assertTrue($run->topic->is($topic));
        $this->assertTrue($topic->runs->first()->is($run));
    }

    public function test_run_without_topic_keeps_null_topic_id_and_hash(): void
    {
        $run = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 定价',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow 定价'),
        ]);

        $this->assertNull($run->ai_visibility_topic_id);
        $this->assertSame(AiVisibilityKeywordNormalizer::hash('GEOFlow 定价'), $run->keyword_hash);
    }

    public function test_deleting_topic_nulls_run_topic_id(): void
    {
        $topic = AiVisibilityTopic::create([
            'name' => '删除后置空主题',
        ]);

        $run = AiVisibilityRun::create([
            'keyword' => 'GEOFlow 对比',
            'provider_type' => AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
            'ai_visibility_topic_id' => $topic->id,
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow 对比'),
        ]);

        $topic->delete();

        $this->assertNull($run->refresh()->ai_visibility_topic_id);
    }

    public function test_deleting_topic_cascades_to_keyword_bindings(): void
    {
        $topic = AiVisibilityTopic::create([
            'name' => '级联删除主题',
        ]);

        AiVisibilityTopicKeyword::create([
            'ai_visibility_topic_id' => $topic->id,
            'keyword' => 'GEOFlow 教程',
            'keyword_hash' => AiVisibilityKeywordNormalizer::hash('GEOFlow 教程'),
        ]);

        $topic->delete();

        $this->assertSame(0, AiVisibilityTopicKeyword::where('ai_visibility_topic_id', $topic->id)->count());
    }
}
