<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class ArticleQualityJsonReviewerAgent implements Agent
{
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit = 8192,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions."\n\n供应商当前使用 JSON 回退模式。只输出一个符合 F: Format 定义的 JSON 对象，不要输出 Markdown 代码块或解释。";
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }
}
