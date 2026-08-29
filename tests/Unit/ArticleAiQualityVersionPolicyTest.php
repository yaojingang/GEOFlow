<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use Tests\TestCase;

class ArticleAiQualityVersionPolicyTest extends TestCase
{
    public function test_the_same_article_keeps_a_stable_experiment_assignment(): void
    {
        config()->set('geoflow.ai_quality_execution_version', 'legacy');
        config()->set('geoflow.ai_quality_principle_v2_percent', 25);
        config()->set('geoflow.ai_quality_fast_v2_percent', 37);
        config()->set('geoflow.ai_quality_scoring_v2_percent', 23);
        config()->set('geoflow.ai_quality_shadow_v2_percent', 10);

        $policy = new ArticleAiQualityVersionPolicy;
        $first = $policy->selection(501, 'workspace-a');

        $this->assertSame($first, $policy->selection(501, 'workspace-a'));
        $this->assertContains($first['execution'], ['legacy', 'fast_v2']);
        $this->assertContains($first['principles'], ['v1', 'v2']);
        $this->assertContains($first['scoring'], ['v1', 'v2']);
        $this->assertIsBool($first['shadow_v2']);
        $this->assertSame($first['scoring'] === 'v2', $first['gate_applied_v2']);
    }

    public function test_explicit_fast_execution_and_full_scoring_rollout_override_buckets(): void
    {
        config()->set('geoflow.ai_quality_principle_v2_percent', 100);
        config()->set('geoflow.ai_quality_fast_v2_percent', 100);
        config()->set('geoflow.ai_quality_scoring_v2_percent', 100);
        config()->set('geoflow.ai_quality_shadow_v2_percent', 100);

        $selection = (new ArticleAiQualityVersionPolicy)->selection(8);

        $this->assertSame('fast_v2', $selection['execution']);
        $this->assertSame('v2', $selection['principles']);
        $this->assertSame('v2', $selection['scoring']);
        $this->assertFalse($selection['shadow_v2']);
        $this->assertSame('exec=f2;ret=4;principles=2;prompt=2;score=2', $selection['algorithm_version']);
    }
}
