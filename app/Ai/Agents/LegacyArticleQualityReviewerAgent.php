<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class LegacyArticleQualityReviewerAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresArticleQualityProviderOptions;
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit = 2048,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'promotion_context' => $schema->string()->enum(['informational', 'promotional', 'mixed', 'uncertain'])->required(),
            'knowledge_coverage' => $schema->string()->enum(['sufficient', 'partial', 'insufficient'])->required(),
            'issues' => $schema->array()->items(
                $schema->object(fn (JsonSchema $issue): array => [
                    'code' => $issue->string()->enum(self::ARTICLE_QUALITY_ISSUE_CODES)->required(),
                    'severity' => $issue->string()->enum(['critical', 'high', 'medium', 'low'])->required(),
                    'field' => $issue->string()->enum(['title', 'excerpt', 'content', 'keywords', 'meta_description'])->required(),
                    'quote' => $issue->string()->required(),
                    'paragraph_index' => $issue->integer()->required(),
                    'heading' => $issue->string()->required(),
                    'fact_candidate_id' => $issue->string()->required(),
                    'article_claim' => $issue->string()->required(),
                    'evidence_value' => $issue->string()->required(),
                    'knowledge_refs' => $issue->array()->items($issue->string())->required(),
                    'legal_refs' => $issue->array()->items($issue->string())->required(),
                    'reason' => $issue->string()->required(),
                    'suggestion' => $issue->string()->required(),
                ])
            )->required(),
            'uncertainties' => $schema->array()->items(
                $schema->object(fn (JsonSchema $uncertainty): array => [
                    'claim' => $uncertainty->string()->required(),
                    'materiality' => $uncertainty->string()->enum(['high', 'medium', 'low'])->required(),
                    'reason' => $uncertainty->string()->required(),
                    'needed_evidence' => $uncertainty->string()->required(),
                ])
            )->required(),
        ];
    }
}
