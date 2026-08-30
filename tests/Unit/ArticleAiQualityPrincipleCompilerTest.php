<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityPrincipleCompiler;
use Tests\TestCase;

class ArticleAiQualityPrincipleCompilerTest extends TestCase
{
    public function test_it_compiles_stable_core_and_topic_specific_principles(): void
    {
        $rules = json_decode((string) file_get_contents(resource_path('rules/advertising-cn-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $compiler = new ArticleAiQualityPrincipleCompiler;

        $snapshot = $compiler->compile(
            ['title' => '医疗培训服务', 'content' => '课程不承诺治疗效果。'],
            $rules,
            [['id' => 8, 'hash' => 'kb-hash']],
            ['is_ai_generated' => true],
        );

        $this->assertContains('CN-AD-LAW-04', $snapshot['selected_rule_ids']);
        $this->assertContains('CN-AD-LAW-16-18', $snapshot['selected_rule_ids']);
        $this->assertContains('CN-AD-LAW-24', $snapshot['selected_rule_ids']);
        $this->assertNotContains('CN-AIGC-LABEL-04-06', $snapshot['selected_rule_ids']);
        $this->assertNotContains('CN-AIGC-LABEL-09-10', $snapshot['selected_rule_ids']);
        $this->assertNotContains('CN-AIGC-LABEL-04-06', array_column($rules['rules'], 'id'));
        $this->assertNotContains('CN-AIGC-LABEL-09-10', array_column($rules['rules'], 'id'));
        $this->assertSame($snapshot['advertising_rules'], $compiler->rules($snapshot));
    }

    public function test_it_rejects_a_tampered_principle_snapshot(): void
    {
        $this->expectExceptionMessage('principle_snapshot_invalid');
        (new ArticleAiQualityPrincipleCompiler)->rules([
            'version' => ArticleAiQualityPrincipleCompiler::VERSION,
            'advertising_rules_hash' => hash('sha256', '{}'),
            'advertising_rules' => ['rules' => []],
        ]);
    }

    public function test_it_accepts_a_valid_in_flight_snapshot_from_the_previous_principle_version(): void
    {
        $rules = ['version' => 'cn-ads-content-labeling-1.1.0', 'rules' => [[
            'id' => 'CN-AIGC-LABEL-04-06',
            'title' => '旧版生成内容标识规则',
        ]]];

        $resolved = (new ArticleAiQualityPrincipleCompiler)->rules([
            'version' => 'article-quality-principles-2.0.0',
            'advertising_rules_hash' => hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'advertising_rules' => $rules,
        ]);

        $this->assertSame([], $resolved['rules']);
    }
}
