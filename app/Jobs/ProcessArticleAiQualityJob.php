<?php

namespace App\Jobs;

use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessArticleAiQualityJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly int $checkId,
        public readonly string $expectedScope = 'full',
    ) {
        $this->timeout = (int) config('geoflow.ai_quality_job_timeout_seconds', 245);
    }

    public function uniqueId(): string
    {
        return $this->checkId.':'.$this->expectedScope;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality', 'ai-quality-check:'.$this->checkId];
    }

    public function handle(ArticleAiQualityInspectionService $service): void
    {
        try {
            $service->process(
                $this->checkId,
                allowRunningRecovery: $this->attempts() > 1,
                expectedScope: $this->expectedScope,
            );
        } catch (Throwable $exception) {
            if ($service->tryStartSampledFallback($this->checkId, $exception)) {
                return;
            }
            $service->markFailed($this->checkId, $exception, $this->expectedScope);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $service = app(ArticleAiQualityInspectionService::class);
        $failure = $exception ?? new \RuntimeException('article_ai_quality_job_failed');
        if (! $service->tryStartSampledFallback($this->checkId, $failure)) {
            $service->markFailed($this->checkId, $failure, $this->expectedScope);
        }
    }
}
