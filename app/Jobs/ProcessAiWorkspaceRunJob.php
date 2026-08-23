<?php

namespace App\Jobs;

use App\Services\AiWorkspace\AiWorkflowEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class ProcessAiWorkspaceRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public readonly string $workerToken;

    public function __construct(public readonly string $runId, ?string $workerToken = null)
    {
        $this->workerToken = $workerToken ?? (string) Str::uuid7();
    }

    public function handle(AiWorkflowEngine $engine): void
    {
        $engine->process($this->runId, $this->workerToken);
    }

    public function failed(Throwable $exception): void
    {
        app(AiWorkflowEngine::class)->markJobFailure($this->runId, $exception, $this->workerToken);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-workspace', 'ai-workspace-run:'.$this->runId, 'stage:execute'];
    }
}
