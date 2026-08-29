<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class TitleGeneratorAgent implements Agent
{
    use Promptable;

    public function __construct(
        private readonly string $systemInstructions,
        private readonly int $outputTokenLimit,
    ) {}

    public function instructions(): string
    {
        return $this->systemInstructions;
    }

    public function maxTokens(): int
    {
        return $this->outputTokenLimit;
    }
}
