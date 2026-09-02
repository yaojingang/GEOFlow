<?php

namespace App\Data\Ai;

final readonly class KnowledgeFactGenerationRecoveryDispatch
{
    /**
     * @param  list<array{sequence:int,input_hash:string,evidence:list<array<string,string>>,claim_token:string}>  $batches
     */
    public function __construct(
        public int $runId,
        public int $executionAttempt,
        public string $finalizerToken,
        public array $batches,
    ) {}

    public function finalizerOnly(): bool
    {
        return $this->batches === [];
    }
}
