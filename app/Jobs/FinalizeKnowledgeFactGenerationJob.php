<?php

namespace App\Jobs;

use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FinalizeKnowledgeFactGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('knowledge');
    }

    public function handle(KnowledgeFactGenerationCoordinator $coordinator): void
    {
        $coordinator->finalize($this->runId);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeFactGenerationCoordinator::class)->markFinalizeFailure($this->runId, $exception);
    }
}
