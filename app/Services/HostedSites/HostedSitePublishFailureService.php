<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use Illuminate\Support\Facades\DB;

final class HostedSitePublishFailureService
{
    public function record(ArticleDistribution $candidate, string $safeMessage, bool $willRetry): bool
    {
        return DB::transaction(function () use ($candidate, $safeMessage, $willRetry): bool {
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            if (! $channel?->isHostedSite()) {
                return false;
            }
            $profile = HostedSiteProfile::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->lockForUpdate()
                ->first();
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $candidate->article_id)
                ->lockForUpdate()
                ->first();
            $article = Article::query()->whereKey((int) $candidate->article_id)->lockForUpdate()->first();
            $assignment = HostedSiteArticleAssignment::query()
                ->where('article_id', (int) $candidate->article_id)
                ->lockForUpdate()
                ->first();
            $distribution = ArticleDistribution::query()
                ->whereKey((int) $candidate->id)
                ->lockForUpdate()
                ->first();
            if (! $profile || ! $assignment || ! $distribution) {
                return false;
            }

            $action = (string) $distribution->action;
            $committed = in_array($action, ['publish', 'update'], true)
                ? $assignment->status === HostedSiteArticleAssignment::STATUS_PUBLISHED
                : $action === 'delete'
                    && $assignment->status === HostedSiteArticleAssignment::STATUS_WITHDRAWN;
            if ($committed) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $distribution->forceFill([
                    'status' => 'synced',
                    'remote_id' => (string) $assignment->id,
                    'remote_url' => $action === 'delete'
                        ? null
                        : 'https://'.$profile->hostname.'/article/'.$article?->slug,
                    'remote_meta' => array_replace($remoteMeta, [
                        'hosted_site_profile_id' => (int) $profile->id,
                        'assignment_status' => (string) $assignment->status,
                        'failure_reconciled' => true,
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

                return true;
            }

            if ($assignment->status !== HostedSiteArticleAssignment::STATUS_RESERVED) {
                $assignment->forceFill(['last_error_message' => $safeMessage])->save();
                if (! $willRetry) {
                    $this->recordProfileFailure($profile);
                }

                return false;
            }

            $assignment->forceFill([
                'status' => $willRetry
                    ? HostedSiteArticleAssignment::STATUS_RESERVED
                    : HostedSiteArticleAssignment::STATUS_FAILED,
                'reservation_expires_at' => $willRetry
                    ? now()->addMinutes((int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30))
                    : null,
                'last_error_message' => $safeMessage,
            ])->save();
            if ($request) {
                $request->forceFill([
                    'status' => $willRetry
                        ? HostedSiteAllocationRequest::STATUS_ASSIGNED
                        : HostedSiteAllocationRequest::STATUS_PENDING,
                    'next_attempt_at' => $willRetry ? null : now()->addMinutes(5),
                    'last_error_code' => 'publish_failed',
                    'last_error_message' => $safeMessage,
                ])->save();
            }
            if (! $willRetry) {
                $this->recordProfileFailure($profile);
            }

            return false;
        }, 3);
    }

    private function recordProfileFailure(HostedSiteProfile $profile): void
    {
        $failures = (int) $profile->consecutive_publish_failures + 1;
        $threshold = (int) config('geoflow.hosted_sites.failure_cooldown_threshold', 3);
        $profile->forceFill([
            'consecutive_publish_failures' => $failures,
            'cooldown_until' => $failures >= $threshold
                ? now()->addMinutes((int) config('geoflow.hosted_sites.failure_cooldown_minutes', 60))
                : $profile->cooldown_until,
        ])->save();
    }
}
