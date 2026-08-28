<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessArticleAiQualityJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = false;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $checkId) {}

    public function uniqueId(): string
    {
        return (string) $this->checkId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('article-ai-quality:'.$this->checkId))
                ->releaseAfter(30)
                ->expireAfter(930),
        ];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality', 'ai-quality-check:'.$this->checkId];
    }

    public function handle(ArticleAiQualityInspectionService $service): void
    {
        try {
            $service->process($this->checkId, allowRunningRecovery: $this->attempts() > 1);
        } catch (Throwable $exception) {
            $service->markRetryPending($this->checkId, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        app(ArticleAiQualityInspectionService::class)->markFailed(
            $this->checkId,
            $exception ?? new \RuntimeException('article_ai_quality_job_failed'),
        );
    }
}
