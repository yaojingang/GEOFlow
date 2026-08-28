<?php

namespace App\Jobs;

use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

final class ResolveAiWorkspaceRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public readonly string $leaseOwner;

    public function __construct(public readonly string $runId, ?string $leaseOwner = null)
    {
        $this->leaseOwner = $leaseOwner ?? (string) Str::uuid7();
    }

    public function handle(AiWorkspaceCoordinator $coordinator): void
    {
        $coordinator->resolveRun($this->runId, $this->leaseOwner);
    }

    public function failed(Throwable $exception): void
    {
        app(AiWorkspaceCoordinator::class)->markJobFailure($this->runId, $exception, $this->leaseOwner);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-workspace', 'ai-workspace-run:'.$this->runId, 'stage:resolve'];
    }
}
