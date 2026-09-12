<?php

namespace Tests\Feature;

use App\Models\AiVisibilityRun;
use App\Models\AiVisibilityTopic;
use App\Models\AiVisibilityTopicKeyword;
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
            'keyword_hash' => hash('sha256', 'GEOFlow AI 可见性'),
        ]);

        $this->assertTrue($topic->keywords->first()->is($binding));
        $this->assertTrue($binding->topic->is($topic));

        $this->expectException(QueryException::class);

        AiVisibilityTopicKeyword::create([
            'ai_visibility_topic_id' => $topic->id,
            'keyword' => 'GEOFlow AI 可见性 重复',
            'keyword_hash' => hash('sha256', 'GEOFlow AI 可见性'),
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
            'keyword_hash' => hash('sha256', 'GEOFlow 介绍'),
        ]);

        $this->assertTrue($run->topic->is($topic));
        $this->assertTrue($topic->runs->first()->is($run));
    }
}
