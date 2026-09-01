<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class ArticleOptimizationRefinerAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use ConfiguresArticleQualityProviderOptions;
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit = 4096,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }

    /** @return array<string,Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'base_article_hash' => $schema->string()->required(),
            'strategy' => $schema->string()->enum(['pass', 'excellent_80', 'excellent_90'])->required(),
            'operations' => $schema->array()->items(
                $schema->object(fn (JsonSchema $operation): array => [
                    'field' => $operation->string()->enum(['title', 'excerpt', 'content', 'keywords', 'meta_description'])->required(),
                    'replacement' => $operation->string()->required(),
                    'issue_codes' => $operation->array()->items($operation->string())->required(),
                    'root_cause_keys' => $operation->array()->items($operation->string())->required(),
                    'evidence_keys' => $operation->array()->items($operation->string())->required(),
                    'reason' => $operation->string()->required(),
                ])
            )->required(),
        ];
    }
}
