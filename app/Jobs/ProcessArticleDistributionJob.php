<?php

namespace App\Jobs;

use App\Exceptions\HostedSitesDisabled;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\HostedSites\HostedSitePublishFailureService;
use App\Support\GeoFlow\DistributionErrorSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessArticleDistributionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly int $distributionId) {}

    /** @return list<string> */
    public function tags(): array
    {
        $tags = ['article-distribution:'.$this->distributionId];
        $distribution = ArticleDistribution::query()
            ->select(['article_id', 'distribution_channel_id'])
            ->find($this->distributionId);
        if ($distribution) {
            $tags[] = 'article:'.(int) $distribution->article_id;
            $tags[] = 'distribution-channel:'.(int) $distribution->distribution_channel_id;
        }

        return $tags;
    }

    public function handle(
        DistributionOrchestrator $orchestrator,
        DistributionRetryPolicy $retryPolicy,
        ?HostedSitePublishFailureService $hostedFailures = null,
    ): void {
        $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
        if (! $distribution) {
            return;
        }

        try {
            if (! $orchestrator->process($distribution)) {
                return;
            }
        } catch (Throwable $e) {
            $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
            if (! $distribution) {
                return;
            }
            if ($e instanceof HostedSitesDisabled) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $distribution->forceFill([
                    'status' => 'queued',
                    'next_retry_at' => null,
                    'last_error_message' => $e->getMessage(),
                    'remote_meta' => array_replace($remoteMeta, ['hosted_feature_paused' => true]),
                ])->save();

                return;
            }
            $distribution->loadMissing(['article.task.distributionChannels', 'channel']);
            $attemptCount = (int) $distribution->attempt_count;
            $maxAttempts = (int) ($distribution->article?->task?->distributionChannels
                ?->firstWhere('id', (int) $distribution->distribution_channel_id)
                ?->pivot?->max_attempts ?? 3);
            $shouldRetry = $retryPolicy->shouldRetry($e, $attemptCount, $maxAttempts);
            $retryAt = $shouldRetry ? $retryPolicy->retryAt($attemptCount) : null;
            if ((string) $distribution->channel?->status !== DistributionChannel::STATUS_ACTIVE) {
                $shouldRetry = false;
                $retryAt = null;
            }
            if ($distribution->channel?->isHostedSite()
                && ! config('geoflow.hosted_sites.enabled', false)) {
                $shouldRetry = false;
                $retryAt = null;
            }

            $safeMessage = DistributionErrorSanitizer::from($e);
            $committed = ($hostedFailures ?? app(HostedSitePublishFailureService::class))
                ->record($distribution, $safeMessage, $shouldRetry);
            if (! $committed) {
                $distribution->forceFill([
                    'status' => $shouldRetry ? 'queued' : 'failed',
                    'last_error_message' => $safeMessage,
                    'last_attempt_at' => now(),
                    'next_retry_at' => $retryAt,
                ])->save();
            }

            $orchestrator->log(
                $committed ? 'warning' : ($shouldRetry ? 'warning' : 'error'),
                $committed ? '文章分发结果已根据本地提交状态完成对账' : '文章分发失败：'.$safeMessage,
                $distribution->distribution_channel_id,
                $distribution->id,
                $distribution->article_id,
                ['event' => $committed
                    ? 'distribution.commit_reconciled'
                    : ($shouldRetry ? 'distribution.retry_scheduled' : 'distribution.failed')]
            );

            if ($shouldRetry && ! $committed) {
                self::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->delay($retryAt);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $distribution = ArticleDistribution::query()->find($this->distributionId);
        if (! $distribution || (string) $distribution->status === 'synced') {
            return;
        }

        $exceptionClass = $exception ? class_basename($exception) : 'UnknownFailure';
        $safeMessage = 'Distribution job terminated: '.$exceptionClass;
        $committed = app(HostedSitePublishFailureService::class)
            ->record($distribution, $safeMessage, false);
        if (! $committed) {
            $distribution->forceFill([
                'status' => 'failed',
                'next_retry_at' => null,
                'last_attempt_at' => now(),
                'last_error_message' => $safeMessage,
            ])->save();
        }
        DistributionLog::query()->create([
            'distribution_channel_id' => (int) $distribution->distribution_channel_id,
            'article_distribution_id' => (int) $distribution->id,
            'article_id' => (int) $distribution->article_id,
            'level' => $committed ? 'warning' : 'error',
            'event' => $committed ? 'distribution.commit_reconciled' : 'distribution.job_failed',
            'message' => $committed
                ? 'Distribution job ended after the local publication transaction committed.'
                : $safeMessage,
            'context' => ['exception_class' => $exceptionClass],
            'created_at' => now(),
        ]);
    }
}
