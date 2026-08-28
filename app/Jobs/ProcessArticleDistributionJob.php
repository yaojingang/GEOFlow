<?php

namespace App\Jobs;

use App\Exceptions\HostedSitesDisabled;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Services\AiWorkspace\AiWorkspaceDispatchGuard;
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
        ?AiWorkspaceDispatchGuard $dispatchGuard = null,
        ?HostedSitePublishFailureService $hostedFailures = null,
    ): void {
        $distribution = ArticleDistribution::query()->whereKey($this->distributionId)->first();
        if (! $distribution) {
            return;
        }
        if ((string) $distribution->status !== 'queued') {
            return;
        }
        if (! ($dispatchGuard ?? app(AiWorkspaceDispatchGuard::class))->allowsDistribution($distribution)) {
            $meta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
            unset($meta['ai_workspace_payload']);
            $meta['ai_workspace_guard_status'] = 'denied';
            ArticleDistribution::query()
                ->whereKey($distribution->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => 'AI 工作台授权或运行状态已失效。',
                    'remote_meta' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);

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
            $expectedStatus = (string) $distribution->status;
            if (! in_array($expectedStatus, ['queued', 'sending'], true)) {
                return;
            }
            if ($e instanceof HostedSitesDisabled) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                ArticleDistribution::query()
                    ->whereKey((int) $distribution->id)
                    ->where('status', $expectedStatus)
                    ->update([
                        'status' => 'queued',
                        'next_retry_at' => null,
                        'last_error_message' => $e->getMessage(),
                        'remote_meta' => json_encode(
                            array_replace($remoteMeta, ['hosted_feature_paused' => true]),
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                        ),
                        'updated_at' => now(),
                    ]);

                return;
            }
            $distribution->loadMissing(['article.task.distributionChannels', 'channel']);
            $attemptCount = (int) $distribution->attempt_count;
            $maxAttempts = (int) ($distribution->article?->task?->distributionChannels
                ?->firstWhere('id', (int) $distribution->distribution_channel_id)
                ?->pivot?->max_attempts ?? 3);
            $shouldRetry = $retryPolicy->shouldRetry($e, $attemptCount, $maxAttempts);
            if ($this->createsUncertainAiWordPressPost($distribution) && $shouldRetry) {
                try {
                    if ($orchestrator->reconcileUnknownOutcome($distribution)) {
                        return;
                    }
                } catch (Throwable $reconciliationError) {
                    report($reconciliationError);
                }
                $safeMessage = DistributionErrorSanitizer::from($e);
                $updated = ArticleDistribution::query()
                    ->whereKey((int) $distribution->id)
                    ->where('status', $expectedStatus)
                    ->update([
                        'status' => 'outcome_unknown',
                        'last_error_message' => '远程请求可能已生效，需要人工对账：'.$safeMessage,
                        'last_attempt_at' => now(),
                        'next_retry_at' => null,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    return;
                }
                $orchestrator->log(
                    'error',
                    'AI 工作台 WordPress 分发结果无法确认，已停止自动重试',
                    (int) $distribution->distribution_channel_id,
                    (int) $distribution->id,
                    (int) $distribution->article_id,
                    ['event' => 'distribution.outcome_unknown'],
                );

                return;
            }
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
                ->record($distribution, $safeMessage, $shouldRetry, $expectedStatus);
            $updated = 0;
            if (! $committed) {
                $updated = ArticleDistribution::query()
                    ->whereKey((int) $distribution->id)
                    ->where('status', $expectedStatus)
                    ->update([
                        'status' => $shouldRetry ? 'queued' : 'failed',
                        'last_error_message' => $safeMessage,
                        'last_attempt_at' => now(),
                        'next_retry_at' => $retryAt,
                        'updated_at' => now(),
                    ]);
            }
            if (! $committed && $updated !== 1) {
                return;
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
        if (! $distribution) {
            return;
        }
        $expectedStatus = (string) $distribution->status;
        if (! in_array($expectedStatus, ['queued', 'sending'], true)) {
            return;
        }

        $exceptionClass = $exception ? class_basename($exception) : 'UnknownFailure';
        $safeMessage = 'Distribution job terminated: '.$exceptionClass;
        $distribution->loadMissing('channel');
        if ((string) $distribution->status === 'sending' && $this->createsUncertainAiWordPressPost($distribution)) {
            try {
                if (app(DistributionOrchestrator::class)->reconcileUnknownOutcome($distribution)) {
                    return;
                }
            } catch (Throwable $reconciliationError) {
                report($reconciliationError);
            }
            $updated = ArticleDistribution::query()
                ->whereKey((int) $distribution->id)
                ->where('status', $expectedStatus)
                ->update([
                    'status' => 'outcome_unknown',
                    'next_retry_at' => null,
                    'last_attempt_at' => now(),
                    'last_error_message' => '远程请求可能已生效，需要人工对账：'.$safeMessage,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                return;
            }
            DistributionLog::query()->create([
                'distribution_channel_id' => (int) $distribution->distribution_channel_id,
                'article_distribution_id' => (int) $distribution->id,
                'article_id' => (int) $distribution->article_id,
                'level' => 'error',
                'event' => 'distribution.outcome_unknown',
                'message' => 'AI 工作台 WordPress 分发 Worker 终止，远程结果无法确认。',
                'context' => ['exception_class' => $exceptionClass],
                'created_at' => now(),
            ]);

            return;
        }
        $committed = app(HostedSitePublishFailureService::class)
            ->record($distribution, $safeMessage, false, $expectedStatus);
        $updated = 0;
        if (! $committed) {
            $updated = ArticleDistribution::query()
                ->whereKey((int) $distribution->id)
                ->where('status', $expectedStatus)
                ->update([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_attempt_at' => now(),
                    'last_error_message' => $safeMessage,
                    'updated_at' => now(),
                ]);
        }
        if (! $committed && $updated !== 1) {
            return;
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

    private function createsUncertainAiWordPressPost(ArticleDistribution $distribution): bool
    {
        return is_array(data_get($distribution->remote_meta, 'ai_workspace_guard'))
            && $distribution->channel?->isWordPressRest()
            && ((string) $distribution->action === 'publish'
                || ((string) $distribution->action === 'update' && ! $distribution->wordpressPostId()));
    }
}
