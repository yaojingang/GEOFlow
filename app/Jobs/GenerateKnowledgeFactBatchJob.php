<?php

namespace App\Jobs;

use App\Data\Ai\KnowledgeFactGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\KnowledgeFactModelRateLimitExceeded;
use App\Exceptions\PermanentAiProviderException;
use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationCoordinator;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

class GenerateKnowledgeFactBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 170;

    public bool $failOnTimeout = true;

    public array $backoff = [5, 30, 120];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $runId,
        public readonly int $sequence,
        public readonly string $inputHash,
        public readonly array $evidence,
        public readonly int $executionAttempt = 1,
        public readonly string $claimToken = '',
    ) {
        $this->onQueue('knowledge');
    }

    public function middleware(): array
    {
        return [new RateLimited('knowledge-fact-generation'), (new WithoutOverlapping("knowledge-fact-run:{$this->runId}:{$this->sequence}"))->releaseAfter(5)->expireAfter(210)];
    }

    public function uniqueId(): string
    {
        return "{$this->runId}:{$this->executionAttempt}:{$this->sequence}:{$this->inputHash}";
    }

    public function tags(): array
    {
        return ['knowledge-facts', "knowledge-fact-run:{$this->runId}", "batch:{$this->sequence}"];
    }

    public function handle(KnowledgeFactGenerationCoordinator $coordinator): void
    {
        $context = null;
        try {
            $context = $coordinator->claimBatch(
                $this->runId,
                $this->sequence,
                $this->inputHash,
                $this->executionAttempt,
                $this->claimToken,
                (string) Str::uuid7(),
            );
            if (! $context instanceof KnowledgeFactGenerationExecutionContext) {
                return;
            }
            $coordinator->processClaimedBatch($context, $this->evidence);
        } catch (KnowledgeFactModelRateLimitExceeded $exception) {
            if ($context instanceof KnowledgeFactGenerationExecutionContext) {
                $coordinator->releaseBatchForRetry($context, refundAttempt: true);
            }

            $this->release($exception->retryAfterSeconds());
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $coordinator->recordBatchFailure(
                $this->runId,
                $this->sequence,
                $this->inputHash,
                $exception,
                $this->executionAttempt,
                $this->claimToken,
                false,
                $context?->leaseToken(),
                $context?->batchAttempt ?? 0,
            );
        } catch (Throwable $exception) {
            if ($context instanceof KnowledgeFactGenerationExecutionContext) {
                $coordinator->releaseBatchForRetry($context);
            }

            throw $exception;
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeFactGenerationCoordinator::class)->recordBatchFailure(
            $this->runId,
            $this->sequence,
            $this->inputHash,
            $exception,
            $this->executionAttempt,
            $this->claimToken,
            true,
            null,
            $this->attempts(),
        );
    }
}
