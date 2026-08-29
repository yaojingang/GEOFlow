<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class ArticleQualityReviewerAgent implements Agent, HasProviderOptions, HasStructuredOutput
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
            'reviewed_claim_hashes' => $schema->array()->items($schema->string())->required(),
            'issues' => $schema->array()->items(
                $schema->object(fn (JsonSchema $issue): array => [
                    'code' => $issue->string()->enum(self::ARTICLE_QUALITY_ISSUE_CODES)->required(),
                    'severity' => $issue->string()->enum(['critical', 'high', 'medium', 'low'])->required(),
                    'claim_hash' => $issue->string()->required(),
                    'field' => $issue->string()->enum(['title', 'excerpt', 'content', 'keywords', 'meta_description'])->required(),
                    'quote' => $issue->string()->required(),
                    'evidence_keys' => $issue->array()->items($issue->string())->required(),
                    'evidence_status' => $issue->string()->enum(['supported', 'contradicted', 'unverified'])->required(),
                    'reason' => $issue->string()->required(),
                    'suggestion' => $issue->string()->required(),
                    'confidence' => $issue->number()->required(),
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
            'truncated_issue_count' => $schema->integer()->required(),
        ];
    }
}
