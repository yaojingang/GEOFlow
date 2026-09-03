<?php

namespace App\Jobs;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Services\GeoFlow\TitleGenerationCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class ProcessTitleGenerationBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Rate-limit and overlap middleware may release a batch repeatedly; model errors use maxExceptions.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 360;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 600;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(
        public readonly int $runId,
        public readonly int $aiModelId,
        public readonly int $batchSequence,
        public readonly string $leaseToken,
    ) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new RateLimited('title-generation'),
            (new WithoutOverlapping('title-generation-run:'.$this->runId))
                ->releaseAfter(5)
                ->expireAfter((int) config('geoflow.title_ai_lease_seconds', 420)),
        ];
    }

    public function uniqueId(): string
    {
        return $this->runId.':'.$this->batchSequence.':'.$this->leaseToken;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'title-generation',
            'title-generation-run:'.$this->runId,
            'ai-model:'.$this->aiModelId,
        ];
    }

    public function handle(TitleGenerationCoordinator $coordinator): void
    {
        try {
            $coordinator->processBatch(
                $this->runId,
                $this->batchSequence,
                $this->leaseToken,
            );
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $coordinator->markFailed(
                $this->runId,
                $this->batchSequence,
                $this->leaseToken,
                $exception,
                false,
            );
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        app(TitleGenerationCoordinator::class)->markFailed(
            $this->runId,
            $this->batchSequence,
            $this->leaseToken,
            $exception ?? new \RuntimeException('title_generation_batch_failed'),
            true,
        );
    }
}
