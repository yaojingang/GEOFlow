<?php

namespace App\Services\HostedSites;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Support\GeoFlow\ArticleWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class HostedSiteAllocator
{
    public function __construct(private readonly HostedSiteContentFingerprint $fingerprints) {}

    public function allocate(HostedSiteAllocationRequest $candidate): ?HostedSiteArticleAssignment
    {
        $candidate->loadMissing('article.task.distributionChannels');
        $channel = $candidate->article?->task?->distributionChannels
            ?->first(static fn (DistributionChannel $channel): bool => $channel->isHostedSite());
        if (! $channel instanceof DistributionChannel) {
            return $this->recordFailure($candidate, 'no_hosted_channel', 'No hosted channel is attached to the task.');
        }

        try {
            $result = DB::transaction(function () use ($candidate, $channel): array {
                $lockedChannel = DistributionChannel::query()
                    ->whereKey((int) $channel->id)
                    ->lockForUpdate()
                    ->first();
                $profile = HostedSiteProfile::query()
                    ->where('distribution_channel_id', (int) $channel->id)
                    ->lockForUpdate()
                    ->first();
                $request = HostedSiteAllocationRequest::query()
                    ->whereKey((int) $candidate->id)
                    ->lockForUpdate()
                    ->first();
                $article = $request instanceof HostedSiteAllocationRequest
                    ? Article::query()->whereKey((int) $request->article_id)->lockForUpdate()->first()
                    : null;

                if (! $lockedChannel || ! $profile || ! $request || ! $article) {
                    return [null, 'missing_dependency', 'Hosted allocation dependency is missing.'];
                }

                $task = Task::query()->whereKey((int) $article->task_id)->lockForUpdate()->first();
                $hostedChannelIds = $task
                    ? DistributionChannel::query()
                        ->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE)
                        ->whereHas('tasks', fn ($query) => $query->whereKey((int) $task->id))
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(static fn ($id): int => (int) $id)
                        ->all()
                    : [];
                if (! $task
                    || (int) $request->task_id !== (int) $task->id
                    || (int) $request->hosted_site_profile_id !== (int) $profile->id
                    || (string) $task->publish_scope !== 'distribution_only'
                    || $hostedChannelIds !== [(int) $lockedChannel->id]
                    || ! in_array((string) $article->status, ['private', 'published'], true)
                    || ! ArticleWorkflow::isPublishableReviewStatus($article->review_status)) {
                    $request->forceFill([
                        'status' => HostedSiteAllocationRequest::STATUS_CANCELLED,
                        'next_attempt_at' => null,
                        'last_error_code' => 'allocation_contract_changed',
                        'last_error_message' => 'The article or task no longer satisfies the hosted allocation contract.',
                    ])->save();

                    return [null, 'allocation_contract_changed', 'The article or task no longer satisfies the hosted allocation contract.'];
                }

                $existing = HostedSiteArticleAssignment::query()
                    ->where('article_id', (int) $article->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if (in_array($existing->status, [
                        HostedSiteArticleAssignment::STATUS_FAILED,
                        HostedSiteArticleAssignment::STATUS_WITHDRAWN,
                    ], true)) {
                        return [
                            null,
                            'assignment_requires_recovery',
                            'The existing hosted assignment must be reconciled before publication can resume.',
                        ];
                    }

                    $request->forceFill([
                        'hosted_site_profile_id' => (int) $profile->id,
                        'hosted_site_article_assignment_id' => (int) $existing->id,
                        'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
                        'next_attempt_at' => null,
                        'last_error_code' => null,
                        'last_error_message' => null,
                    ])->save();

                    return [$existing, null, null];
                }

                ArticleDistribution::query()
                    ->where('article_id', (int) $article->id)
                    ->where('distribution_channel_id', (int) $lockedChannel->id)
                    ->lockForUpdate()
                    ->get();

                $request->forceFill([
                    'status' => HostedSiteAllocationRequest::STATUS_ALLOCATING,
                    'attempt_count' => (int) $request->attempt_count + 1,
                    'last_attempt_at' => now(),
                ])->save();

                $failure = $this->eligibilityFailure($lockedChannel, $profile);
                if ($failure !== null) {
                    return [null, $failure[0], $failure[1]];
                }

                $capacityDate = CarbonImmutable::now($profile->timezone)->toDateString();
                $usedCapacity = HostedSiteArticleAssignment::query()
                    ->where('hosted_site_profile_id', (int) $profile->id)
                    ->whereDate('capacity_date', $capacityDate)
                    ->where(function ($query): void {
                        $query->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED)
                            ->orWhere(function ($reserved): void {
                                $reserved->where('status', HostedSiteArticleAssignment::STATUS_RESERVED)
                                    ->where(function ($expiry): void {
                                        $expiry->whereNull('reservation_expires_at')
                                            ->orWhere('reservation_expires_at', '>', now());
                                    });
                            });
                    })
                    ->count();
                if ($usedCapacity >= (int) $profile->daily_publish_limit) {
                    return [null, 'no_capacity', 'Hosted site daily capacity is full.'];
                }

                if ($profile->last_published_at !== null
                    && $profile->last_published_at->addMinutes((int) $profile->min_publish_interval_minutes)->isFuture()) {
                    return [null, 'publish_interval', 'Hosted site publish interval has not elapsed.'];
                }

                $fingerprint = $this->fingerprints->forArticle($article);
                if (HostedSiteArticleAssignment::query()->where('content_fingerprint', $fingerprint)->exists()) {
                    return [null, 'duplicate_content', 'An identical hosted article already exists.'];
                }

                $assignment = HostedSiteArticleAssignment::query()->create([
                    'article_id' => (int) $article->id,
                    'hosted_site_profile_id' => (int) $profile->id,
                    'status' => HostedSiteArticleAssignment::STATUS_RESERVED,
                    'content_fingerprint' => $fingerprint,
                    'capacity_date' => $capacityDate,
                    'reservation_expires_at' => now()->addMinutes(
                        (int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30)
                    ),
                    'assigned_at' => now(),
                ]);

                $distribution = ArticleDistribution::query()->create([
                    'article_id' => (int) $article->id,
                    'distribution_channel_id' => (int) $lockedChannel->id,
                    'action' => 'publish',
                    'status' => 'queued',
                    'idempotency_key' => 'hosted-article-'.$article->id.'-channel-'.$lockedChannel->id.'-publish-v1',
                    'attempt_count' => 0,
                    'next_retry_at' => now(),
                    'payload_hash' => $fingerprint,
                ]);

                $request->forceFill([
                    'hosted_site_profile_id' => (int) $profile->id,
                    'hosted_site_article_assignment_id' => (int) $assignment->id,
                    'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
                    'next_attempt_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                DistributionLog::query()->create([
                    'distribution_channel_id' => (int) $lockedChannel->id,
                    'article_distribution_id' => (int) $distribution->id,
                    'article_id' => (int) $article->id,
                    'level' => 'info',
                    'event' => 'hosted_site.allocated',
                    'message' => '文章已预留托管站点容量',
                    'context' => ['hosted_site_profile_id' => (int) $profile->id],
                    'created_at' => now(),
                ]);

                ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->afterCommit();

                return [$assignment, null, null];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains(
                strtolower($exception->getMessage()),
                'hosted_assignments_content_fingerprint_unique'
            )) {
                throw $exception;
            }

            return $this->recordFailure($candidate, 'duplicate_content', 'An identical hosted article already exists.');
        }

        [$assignment, $errorCode, $errorMessage] = $result;
        if ($assignment instanceof HostedSiteArticleAssignment) {
            return $assignment;
        }

        return $this->recordFailure($candidate, (string) $errorCode, (string) $errorMessage);
    }

    /** @return array{string,string}|null */
    private function eligibilityFailure(
        DistributionChannel $channel,
        HostedSiteProfile $profile,
    ): ?array {
        if ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
            return ['channel_paused', 'Hosted channel is not active.'];
        }
        if ($profile->serving_status !== HostedSiteProfile::SERVING_ONLINE) {
            return ['site_not_online', 'Hosted site is not online.'];
        }
        if ($profile->quality_status === HostedSiteProfile::QUALITY_BLOCKED) {
            return ['quality_blocked', 'Hosted site quality is blocked.'];
        }
        if ($profile->cooldown_until !== null && $profile->cooldown_until->isFuture()) {
            return ['cooldown', 'Hosted site is cooling down after publish failures.'];
        }

        return null;
    }

    private function recordFailure(
        HostedSiteAllocationRequest $candidate,
        string $errorCode,
        string $errorMessage,
    ): null {
        $safeMessage = mb_substr($errorMessage, 0, 1000);
        $terminal = in_array($errorCode, [
            'allocation_contract_changed',
            'duplicate_content',
            'missing_dependency',
            'no_hosted_channel',
        ], true);
        $requestUpdate = HostedSiteAllocationRequest::query()
            ->whereKey((int) $candidate->id);
        $eligibleStatuses = [
            HostedSiteAllocationRequest::STATUS_PENDING,
            HostedSiteAllocationRequest::STATUS_ALLOCATING,
        ];
        if ($errorCode === 'assignment_requires_recovery') {
            $eligibleStatuses[] = HostedSiteAllocationRequest::STATUS_ASSIGNED;
            $requestUpdate->whereHas('assignment', fn ($assignment) => $assignment->whereIn('status', [
                HostedSiteArticleAssignment::STATUS_FAILED,
                HostedSiteArticleAssignment::STATUS_WITHDRAWN,
            ]));
        } else {
            $requestUpdate->whereNull('hosted_site_article_assignment_id');
        }
        $requestUpdate->whereIn('status', $eligibleStatuses);
        $requestUpdate->update([
            'status' => $terminal
                ? HostedSiteAllocationRequest::STATUS_CANCELLED
                : HostedSiteAllocationRequest::STATUS_PENDING,
            'next_attempt_at' => $terminal ? null : now()->addMinutes(5),
            'last_error_code' => mb_substr($errorCode, 0, 64),
            'last_error_message' => $safeMessage,
            'updated_at' => now(),
        ]);
        DistributionLog::query()->create([
            'article_id' => (int) $candidate->article_id,
            'level' => 'warning',
            'event' => 'hosted_site.allocation_deferred',
            'message' => '托管站点分配已延期',
            'context' => ['error_code' => $errorCode],
            'created_at' => now(),
        ]);

        return null;
    }
}
