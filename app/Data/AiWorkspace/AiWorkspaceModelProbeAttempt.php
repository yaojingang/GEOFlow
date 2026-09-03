<?php

namespace App\Data\AiWorkspace;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class AiWorkspaceModelProbeAttempt
{
    /**
     * @param  array<string, mixed>|null  $providerResult
     * @param  array<string, mixed>  $streamingProfile
     */
    public function __construct(
        public CarbonImmutable $checkedAt,
        public float $deadline,
        public ?array $providerResult,
        public array $streamingProfile,
        public ?Throwable $streamingFailure,
    ) {}

    public function requiresPlainTextFallback(): bool
    {
        return $this->providerResult === null;
    }
}
