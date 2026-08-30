<?php

namespace App\Jobs;

use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class GenerateKnowledgeFactBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 170;

    public bool $failOnTimeout = true;

    public array $backoff = [5, 30, 120];

    public function __construct(public readonly int $runId, public readonly int $sequence, public readonly string $inputHash, public readonly array $evidence)
    {
        $this->onQueue('knowledge');
    }

    public function middleware(): array
    {
        return [new RateLimited('knowledge-fact-generation'), (new WithoutOverlapping("knowledge-fact-run:{$this->runId}:{$this->sequence}"))->releaseAfter(5)->expireAfter(210)];
    }

    public function tags(): array
    {
        return ['knowledge-facts', "knowledge-fact-run:{$this->runId}", "batch:{$this->sequence}"];
    }

    public function handle(KnowledgeFactGenerationCoordinator $coordinator): void
    {
        $coordinator->processBatch($this->runId, $this->sequence, $this->inputHash, $this->evidence);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeFactGenerationCoordinator::class)->recordBatchFailure($this->runId, $this->sequence, $this->inputHash, $exception);
    }
}
