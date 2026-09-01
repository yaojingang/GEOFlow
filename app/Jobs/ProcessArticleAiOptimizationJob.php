<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProcessArticleAiOptimizationJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public readonly string $attemptOwner;

    public function __construct(public readonly int $runId, ?string $attemptOwner = null)
    {
        $this->timeout = (int) config('geoflow.ai_quality_optimization_job_timeout_seconds', 850);
        $this->attemptOwner = trim((string) $attemptOwner) !== ''
            ? trim((string) $attemptOwner)
            : (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality-optimization', 'ai-quality-optimization-run:'.$this->runId];
    }

    public function handle(ArticleAiOptimizationCoordinator $coordinator): void
    {
        $coordinator->process($this->runId, $this->attemptOwner);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(ArticleAiOptimizationCoordinator::class)->markFailed(
            $this->runId,
            $exception ?? new \RuntimeException('article_ai_optimization_job_failed'),
            $this->attemptOwner,
        );
    }
}
