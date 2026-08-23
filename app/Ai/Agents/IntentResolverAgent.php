<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[MaxTokens(1200)]
#[Temperature(0)]
#[Timeout(45)]
final class IntentResolverAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $capabilityCatalog) {}

    public function instructions(): string
    {
        return <<<'PROMPT'
你是 GEOFlow 的意图解析器。只从给定能力目录中选择能力，禁止创造能力键。
输出用户的主要意图、候选能力、明确请求步骤、已知参数、缺失参数、歧义说明和置信信号。
candidate_capabilities 用于表达候选或相关能力；requested_steps 按用户原始顺序列出每个明确操作。
每个 requested_steps 项必须有唯一 operation_id、能力键、该操作自己的参数和缺失参数。同一能力出现多次时保留为多个步骤。
当请求属于知识问答且无需系统数据时，将 mode 设为 answer。
当请求涉及受限能力时仍返回对应能力键，后端会执行权限和风险校验。
PROMPT."\n\n能力目录：\n".$this->capabilityCatalog;
    }

    /** @return array<string,Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(['workflow', 'answer'])->required(),
            'intent' => $schema->string()->required(),
            'candidate_capabilities' => $schema->array()->items(
                $schema->object(fn (JsonSchema $item): array => [
                    'key' => $item->string()->required(),
                    'confidence' => $item->number()->required(),
                    'reason' => $item->string()->required(),
                ])
            )->required(),
            'requested_steps' => $schema->array()->items(
                $schema->object(fn (JsonSchema $step): array => [
                    'operation_id' => $step->string()->required(),
                    'capability' => $step->string()->required(),
                    'parameters' => $step->object(fn (JsonSchema $parameters): array => [
                        'query' => $parameters->string(), 'theme' => $parameters->string(), 'name' => $parameters->string(),
                        'title' => $parameters->string(), 'content' => $parameters->string(), 'url' => $parameters->string(),
                        'date' => $parameters->string(), 'end_date' => $parameters->string(), 'task_id' => $parameters->integer(),
                        'job_id' => $parameters->integer(), 'hosted_site_id' => $parameters->integer(),
                        'category_id' => $parameters->integer(), 'author_id' => $parameters->integer(),
                        'article_ids' => $parameters->array()->items($parameters->integer()),
                        'channel_ids' => $parameters->array()->items($parameters->integer()), 'action' => $parameters->string(),
                    ])->required(),
                    'missing_parameters' => $step->array()->items($step->string())->required(),
                ])
            )->required(),
            'known_parameters' => $schema->object(fn (JsonSchema $item): array => [
                'query' => $item->string(),
                'theme' => $item->string(),
                'name' => $item->string(),
                'title' => $item->string(),
                'content' => $item->string(),
                'url' => $item->string(),
                'date' => $item->string(),
                'end_date' => $item->string(),
                'task_id' => $item->integer(),
                'job_id' => $item->integer(),
                'hosted_site_id' => $item->integer(),
                'category_id' => $item->integer(),
                'author_id' => $item->integer(),
                'article_ids' => $item->array()->items($item->integer()),
                'channel_ids' => $item->array()->items($item->integer()),
                'action' => $item->string(),
            ])->required(),
            'missing_parameters' => $schema->array()->items($schema->string())->required(),
            'ambiguities' => $schema->array()->items($schema->string())->required(),
            'semantic_confidence' => $schema->number()->required(),
            'object_confidence' => $schema->number()->required(),
            'completeness_confidence' => $schema->number()->required(),
        ];
    }
}
