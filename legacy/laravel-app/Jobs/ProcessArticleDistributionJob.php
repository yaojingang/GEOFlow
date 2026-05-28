<?php

namespace App\Jobs;

use App\Models\ArticleDistribution;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessArticleDistributionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly int $distributionId) {}

    public function handle(DistributionOrchestrator $orchestrator, DistributionRetryPolicy $retryPolicy): void
    {
        $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
        if (! $distribution) {
            return;
        }

        try {
            $orchestrator->process($distribution);
        } catch (Throwable $e) {
            $distribution->loadMissing(['article.task.distributionChannels']);
            $attemptCount = (int) $distribution->attempt_count;
            $maxAttempts = (int) ($distribution->article?->task?->distributionChannels
                ?->firstWhere('id', (int) $distribution->distribution_channel_id)
                ?->pivot?->max_attempts ?? 3);
            $shouldRetry = $retryPolicy->shouldRetry($e, $attemptCount, $maxAttempts);
            $retryAt = $shouldRetry ? $retryPolicy->retryAt($attemptCount) : null;

            $distribution->forceFill([
                'status' => $shouldRetry ? 'queued' : 'failed',
                'last_error_message' => mb_substr($e->getMessage(), 0, 1000),
                'last_attempt_at' => now(),
                'next_retry_at' => $retryAt,
            ])->save();

            $orchestrator->log(
                $shouldRetry ? 'warning' : 'error',
                '文章分发失败：'.$e->getMessage(),
                $distribution->distribution_channel_id,
                $distribution->id,
                $distribution->article_id,
                ['event' => $shouldRetry ? 'distribution.retry_scheduled' : 'distribution.failed']
            );

            if ($shouldRetry) {
                self::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->delay($retryAt);
            }
        }
    }
}
