<?php

namespace App\Services\HostedSites;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use Illuminate\Support\Facades\DB;

final class HostedSiteReconciler
{
    public function __construct(private readonly HostedSiteAllocator $allocator) {}

    /** @return array{pending:int,reserved:int,stale_sending:int,feature_paused:int,dispatched:int,repaired:int} */
    public function reconcile(int $limit = 500, bool $dryRun = false): array
    {
        if (! config('geoflow.hosted_sites.enabled', false)) {
            return ['pending' => 0, 'reserved' => 0, 'stale_sending' => 0, 'feature_paused' => 0, 'dispatched' => 0, 'repaired' => 0];
        }

        $limit = max(1, min(5000, $limit));
        $pendingIds = HostedSiteAllocationRequest::query()
            ->where('status', HostedSiteAllocationRequest::STATUS_PENDING)
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $reservedIds = HostedSiteArticleAssignment::query()
            ->where('status', HostedSiteArticleAssignment::STATUS_RESERVED)
            ->whereNotNull('reservation_expires_at')
            ->where('reservation_expires_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $staleSendingIds = ArticleDistribution::query()
            ->where('status', 'sending')
            ->whereNotNull('last_attempt_at')
            ->where('last_attempt_at', '<=', now()->subSeconds(
                (int) config('geoflow.hosted_sites.stale_sending_seconds', 150)
            ))
            ->whereHas('channel', fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE
            ))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $featurePausedIds = ArticleDistribution::query()
            ->where('status', 'queued')
            ->where('remote_meta->hosted_feature_paused', true)
            ->whereHas('channel', fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE
            ))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $result = [
            'pending' => $pendingIds->count(),
            'reserved' => $reservedIds->count(),
            'stale_sending' => $staleSendingIds->count(),
            'feature_paused' => $featurePausedIds->count(),
            'dispatched' => 0,
            'repaired' => 0,
        ];
        if ($dryRun) {
            return $result;
        }

        foreach ($staleSendingIds as $distributionId) {
            $outcome = $this->repairStaleDistribution((int) $distributionId);
            $result['dispatched'] += $outcome === 'dispatched' ? 1 : 0;
            $result['repaired'] += $outcome === 'repaired' ? 1 : 0;
        }

        foreach ($featurePausedIds as $distributionId) {
            if ($this->resumeFeaturePausedDistribution((int) $distributionId)) {
                $result['dispatched']++;
            }
        }

        foreach ($reservedIds as $assignmentId) {
            $outcome = $this->repairReservation((int) $assignmentId);
            $result['dispatched'] += $outcome === 'dispatched' ? 1 : 0;
            $result['repaired'] += $outcome === 'repaired' ? 1 : 0;
        }

        foreach ($pendingIds as $requestId) {
            $request = HostedSiteAllocationRequest::query()->find((int) $requestId);
            if ($request instanceof HostedSiteAllocationRequest) {
                $this->releaseFailedAssignment($request);
                $request->refresh();
            }
            if ($request instanceof HostedSiteAllocationRequest && $this->allocator->allocate($request) !== null) {
                $result['dispatched']++;
            }
        }

        return $result;
    }

    private function resumeFeaturePausedDistribution(int $distributionId): bool
    {
        return DB::transaction(function () use ($distributionId): bool {
            $candidate = ArticleDistribution::query()->find($distributionId);
            if (! $candidate instanceof ArticleDistribution) {
                return false;
            }
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $profile = $channel
                ? HostedSiteProfile::query()
                    ->where('distribution_channel_id', (int) $channel->id)
                    ->lockForUpdate()
                    ->first()
                : null;
            HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $candidate->article_id)
                ->lockForUpdate()
                ->first();
            Article::query()->whereKey((int) $candidate->article_id)->lockForUpdate()->first();
            $assignment = $profile
                ? HostedSiteArticleAssignment::query()
                    ->where('article_id', (int) $candidate->article_id)
                    ->where('hosted_site_profile_id', (int) $profile->id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $distribution = ArticleDistribution::query()->whereKey($distributionId)->lockForUpdate()->first();
            if (! $channel || ! $profile || ! $assignment || ! $distribution
                || (string) $distribution->status !== 'queued'
                || ! (bool) data_get($distribution->remote_meta, 'hosted_feature_paused')
                || ! $this->canPublish($channel, $profile)) {
                return false;
            }

            $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
            unset($remoteMeta['hosted_feature_paused']);
            $distribution->forceFill([
                'remote_meta' => $remoteMeta,
                'next_retry_at' => now(),
                'last_error_message' => null,
            ])->save();
            if ($assignment->status === HostedSiteArticleAssignment::STATUS_RESERVED) {
                $assignment->forceFill([
                    'reservation_expires_at' => now()->addMinutes(
                        (int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30)
                    ),
                ])->save();
            }
            ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                ->onQueue('distribution')
                ->afterCommit();

            return true;
        }, 3);
    }

    private function repairReservation(int $assignmentId): ?string
    {
        return DB::transaction(function () use ($assignmentId): ?string {
            $candidate = HostedSiteArticleAssignment::query()->find($assignmentId);
            if (! $candidate instanceof HostedSiteArticleAssignment) {
                return null;
            }

            $candidate->loadMissing('profile');
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->profile?->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $profile = $channel
                ? HostedSiteProfile::query()->where('distribution_channel_id', (int) $channel->id)->lockForUpdate()->first()
                : null;
            $request = HostedSiteAllocationRequest::query()
                ->where(function ($query) use ($assignmentId, $candidate): void {
                    $query->where('hosted_site_article_assignment_id', $assignmentId)
                        ->orWhere('article_id', (int) $candidate->article_id);
                })
                ->lockForUpdate()
                ->first();
            $article = Article::query()->whereKey((int) $candidate->article_id)->lockForUpdate()->first();
            $assignment = HostedSiteArticleAssignment::query()->whereKey($assignmentId)->lockForUpdate()->first();
            $distribution = $channel && $article
                ? ArticleDistribution::query()
                    ->where('distribution_channel_id', (int) $channel->id)
                    ->where('article_id', (int) $article->id)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first()
                : null;

            if (! $channel || ! $profile || ! $article || ! $assignment) {
                return null;
            }
            if ($assignment->status !== HostedSiteArticleAssignment::STATUS_RESERVED
                || $assignment->reservation_expires_at?->isFuture()) {
                return null;
            }
            if ($distribution?->status === 'synced') {
                $assignment->forceFill([
                    'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
                    'reservation_expires_at' => null,
                    'published_at' => $assignment->published_at ?? now(),
                    'last_error_message' => null,
                ])->save();
                $request?->forceFill([
                    'hosted_site_article_assignment_id' => (int) $assignment->id,
                    'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
                    'next_attempt_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return 'repaired';
            }
            if (! $this->canPublish($channel, $profile)) {
                $assignment->forceFill([
                    'status' => HostedSiteArticleAssignment::STATUS_FAILED,
                    'reservation_expires_at' => null,
                    'last_error_message' => 'Hosted site is unavailable during reconciliation.',
                ])->save();
                $distribution?->forceFill([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => 'Hosted site is unavailable during reconciliation.',
                ])->save();
                $request?->forceFill([
                    'hosted_site_article_assignment_id' => (int) $assignment->id,
                    'status' => $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED
                        ? HostedSiteAllocationRequest::STATUS_CANCELLED
                        : HostedSiteAllocationRequest::STATUS_PENDING,
                    'next_attempt_at' => $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED
                        ? null
                        : now()->addMinutes(5),
                    'last_error_code' => 'site_unavailable',
                    'last_error_message' => 'Hosted site is unavailable during reconciliation.',
                ])->save();

                return 'repaired';
            }
            if ($request instanceof HostedSiteAllocationRequest
                && $distribution instanceof ArticleDistribution) {
                $distribution->forceFill([
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'last_error_message' => null,
                ])->save();
                $assignment->forceFill([
                    'reservation_expires_at' => now()->addMinutes(
                        (int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30)
                    ),
                ])->save();
                ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->afterCommit();

                return 'dispatched';
            }

            if (! $request instanceof HostedSiteAllocationRequest) {
                $assignment->forceFill([
                    'status' => HostedSiteArticleAssignment::STATUS_FAILED,
                    'reservation_expires_at' => null,
                    'last_error_message' => 'The allocation request was missing during reconciliation.',
                ])->save();

                return 'repaired';
            }

            $request->forceFill([
                'hosted_site_article_assignment_id' => null,
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'next_attempt_at' => now(),
                'last_error_code' => 'missing_distribution',
                'last_error_message' => 'Hosted distribution was missing and the reservation was released.',
            ])->save();
            $assignment->delete();

            return 'repaired';
        }, 3);
    }

    private function repairStaleDistribution(int $distributionId): ?string
    {
        return DB::transaction(function () use ($distributionId): ?string {
            $candidate = ArticleDistribution::query()
                ->with('channel.hostedSiteProfile')
                ->find($distributionId);
            $channelId = (int) $candidate?->distribution_channel_id;
            $articleId = (int) $candidate?->article_id;
            if ($channelId === 0 || $articleId === 0) {
                return null;
            }

            $channel = DistributionChannel::query()->whereKey($channelId)->lockForUpdate()->first();
            $profile = $channel
                ? HostedSiteProfile::query()
                    ->where('distribution_channel_id', $channelId)
                    ->lockForUpdate()
                    ->first()
                : null;
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', $articleId)
                ->lockForUpdate()
                ->first();
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->first();
            $assignment = $profile
                ? HostedSiteArticleAssignment::query()
                    ->where('article_id', $articleId)
                    ->where('hosted_site_profile_id', (int) $profile->id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $distribution = ArticleDistribution::query()
                ->whereKey($distributionId)
                ->lockForUpdate()
                ->first();

            if (! $channel || ! $profile || ! $article || ! $assignment || ! $distribution
                || (string) $distribution->status !== 'sending'
                || $distribution->last_attempt_at?->isAfter(now()->subSeconds(
                    (int) config('geoflow.hosted_sites.stale_sending_seconds', 150)
                ))) {
                return null;
            }

            $action = (string) $distribution->action;
            if ($action === 'publish'
                && $assignment->status === HostedSiteArticleAssignment::STATUS_PUBLISHED) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $distribution->forceFill([
                    'status' => 'synced',
                    'remote_id' => (string) $assignment->id,
                    'remote_url' => 'https://'.$profile->hostname.'/article/'.$article->slug,
                    'remote_meta' => array_replace($remoteMeta, [
                        'hosted_site_profile_id' => (int) $profile->id,
                        'assignment_status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
                        'reconciled' => true,
                    ]),
                    'next_retry_at' => null,
                    'last_error_message' => null,
                ])->save();
                $request?->forceFill([
                    'hosted_site_article_assignment_id' => (int) $assignment->id,
                    'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
                    'next_attempt_at' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return 'repaired';
            }

            if ($action === 'delete'
                && $assignment->status === HostedSiteArticleAssignment::STATUS_WITHDRAWN) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $distribution->forceFill([
                    'status' => 'synced',
                    'remote_id' => (string) $assignment->id,
                    'remote_url' => null,
                    'remote_meta' => array_replace($remoteMeta, [
                        'hosted_site_profile_id' => (int) $profile->id,
                        'assignment_status' => HostedSiteArticleAssignment::STATUS_WITHDRAWN,
                        'reconciled' => true,
                    ]),
                    'next_retry_at' => null,
                    'last_error_message' => null,
                ])->save();

                return 'repaired';
            }

            if (($action === 'delete'
                    && $assignment->status === HostedSiteArticleAssignment::STATUS_PUBLISHED)
                || ($action === 'update'
                    && $assignment->status === HostedSiteArticleAssignment::STATUS_PUBLISHED)) {
                $distribution->forceFill([
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'last_error_message' => null,
                ])->save();
                ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->afterCommit();

                return 'dispatched';
            }

            if ($assignment->status === HostedSiteArticleAssignment::STATUS_RESERVED
                && $request instanceof HostedSiteAllocationRequest
                && $this->canPublish($channel, $profile)) {
                $assignment->forceFill([
                    'reservation_expires_at' => now()->addMinutes(
                        (int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30)
                    ),
                ])->save();
                $distribution->forceFill([
                    'status' => 'queued',
                    'next_retry_at' => now(),
                    'last_error_message' => null,
                ])->save();
                ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->afterCommit();

                return 'dispatched';
            }

            if ($assignment->status === HostedSiteArticleAssignment::STATUS_RESERVED) {
                $assignment->forceFill([
                    'status' => HostedSiteArticleAssignment::STATUS_FAILED,
                    'reservation_expires_at' => null,
                    'last_error_message' => 'Stale hosted publication could not be resumed.',
                ])->save();
            }
            $distribution->forceFill([
                'status' => 'failed',
                'next_retry_at' => null,
                'last_error_message' => 'Stale hosted publication could not be resumed.',
            ])->save();
            $request?->forceFill([
                'status' => $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED
                    ? HostedSiteAllocationRequest::STATUS_CANCELLED
                    : HostedSiteAllocationRequest::STATUS_PENDING,
                'next_attempt_at' => $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED
                    ? null
                    : now()->addMinutes(5),
                'last_error_code' => 'stale_distribution',
                'last_error_message' => 'Stale hosted publication could not be resumed.',
            ])->save();

            return 'repaired';
        }, 3);
    }

    private function releaseFailedAssignment(HostedSiteAllocationRequest $candidate): void
    {
        DB::transaction(function () use ($candidate): void {
            $candidate->loadMissing('assignment.profile');
            $assignmentId = (int) ($candidate->hosted_site_article_assignment_id ?? 0);
            $channelId = (int) ($candidate->assignment?->profile?->distribution_channel_id ?? 0);
            if ($assignmentId === 0 || $channelId === 0) {
                return;
            }

            $channel = DistributionChannel::query()->whereKey($channelId)->lockForUpdate()->first();
            $profile = $channel
                ? HostedSiteProfile::query()->where('distribution_channel_id', $channelId)->lockForUpdate()->first()
                : null;
            $request = HostedSiteAllocationRequest::query()->whereKey((int) $candidate->id)->lockForUpdate()->first();
            Article::query()->whereKey((int) $candidate->article_id)->lockForUpdate()->first();
            $assignment = HostedSiteArticleAssignment::query()->whereKey($assignmentId)->lockForUpdate()->first();
            $hasSyncedDistribution = ArticleDistribution::query()
                ->where('distribution_channel_id', $channelId)
                ->where('article_id', (int) $candidate->article_id)
                ->lockForUpdate()
                ->where('status', 'synced')
                ->exists();
            if (! $profile || ! $request || ! $assignment || $hasSyncedDistribution
                || $assignment->status !== HostedSiteArticleAssignment::STATUS_FAILED) {
                return;
            }

            $request->forceFill(['hosted_site_article_assignment_id' => null])->save();
            $assignment->delete();
            ArticleDistribution::query()
                ->where('distribution_channel_id', $channelId)
                ->where('article_id', (int) $candidate->article_id)
                ->where('status', 'failed')
                ->delete();
        }, 3);
    }

    private function canPublish(DistributionChannel $channel, HostedSiteProfile $profile): bool
    {
        return (string) $channel->status === DistributionChannel::STATUS_ACTIVE
            && $profile->serving_status === HostedSiteProfile::SERVING_ONLINE
            && $profile->quality_status !== HostedSiteProfile::QUALITY_BLOCKED;
    }
}
