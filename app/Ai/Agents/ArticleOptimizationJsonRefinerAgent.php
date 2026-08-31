<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Concerns\ConfiguresArticleQualityProviderOptions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

final class ArticleOptimizationJsonRefinerAgent implements Agent, HasProviderOptions
{
    use ConfiguresArticleQualityProviderOptions;
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit = 4096,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions."\n\n只输出一个符合补丁协议的 JSON 对象，不要输出 Markdown 代码块或解释。";
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }
}
