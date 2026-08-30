<?php

namespace Tests\Unit;

use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Ai\Agents\LegacyArticleQualityReviewerAgent;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\TestCase;

class ArticleQualityReviewerAgentTest extends TestCase
{
    public function test_quality_agents_use_the_compact_output_budget_by_default(): void
    {
        $this->assertSame(2048, (new ArticleQualityReviewerAgent('instructions'))->maxTokens());
        $this->assertSame(2048, (new ArticleQualityJsonReviewerAgent('instructions'))->maxTokens());
    }

    public function test_quality_agents_disable_deepseek_thinking_and_send_the_v4_output_limit(): void
    {
        $agents = [
            new ArticleQualityReviewerAgent('instructions', 321),
            new ArticleQualityJsonReviewerAgent('instructions', 321),
            new LegacyArticleQualityReviewerAgent('instructions', 321),
        ];

        foreach ($agents as $agent) {
            $this->assertSame([
                'max_tokens' => 321,
                'thinking' => ['type' => 'disabled'],
            ], $agent->providerOptions(Lab::DeepSeek));
            $this->assertSame([], $agent->providerOptions(Lab::OpenAI));
        }
    }

    public function test_structured_agent_uses_the_compact_v2_root_cause_schema(): void
    {
        $schema = (new ArticleQualityReviewerAgent('instructions'))->schema(new JsonSchemaTypeFactory);

        $this->assertSame([
            'summary',
            'promotion_context',
            'reviewed_claim_hashes',
            'issues',
            'uncertainties',
            'truncated_issue_count',
        ], array_keys($schema));
        $issueSchema = $schema['issues']->toArray()['items']['properties'];
        $this->assertSame([
            'code',
            'severity',
            'claim_hash',
            'field',
            'quote',
            'evidence_keys',
            'evidence_status',
            'reason',
            'suggestion',
            'confidence',
        ], array_keys($issueSchema));
    }

    public function test_legacy_structured_agent_keeps_the_v1_rollback_schema(): void
    {
        $schema = (new LegacyArticleQualityReviewerAgent('instructions'))->schema(new JsonSchemaTypeFactory);

        $this->assertArrayHasKey('knowledge_coverage', $schema);
        $this->assertArrayNotHasKey('truncated_issue_count', $schema);
    }

    public function test_structured_quality_schemas_exclude_the_removed_ai_generation_disclosure_code(): void
    {
        foreach ([
            new ArticleQualityReviewerAgent('instructions'),
            new LegacyArticleQualityReviewerAgent('instructions'),
        ] as $agent) {
            $schema = $agent->schema(new JsonSchemaTypeFactory);
            $codeSchema = $schema['issues']->toArray()['items']['properties']['code'];

            $this->assertContains('citation_missing', $codeSchema['enum']);
            $this->assertContains('content_integrity', $codeSchema['enum']);
            $this->assertNotContains('ai_generated_disclosure', $codeSchema['enum']);
            $this->assertNotContains('other', $codeSchema['enum']);
        }
    }
}
