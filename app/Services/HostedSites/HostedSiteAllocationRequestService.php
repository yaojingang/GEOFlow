<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteProfile;
use DomainException;

final class HostedSiteAllocationRequestService
{
    public function request(Article $article): HostedSiteAllocationRequest
    {
        if (! config('geoflow.hosted_sites.enabled', false)) {
            throw new DomainException('Hosted sites are disabled.');
        }

        $article->loadMissing('task.distributionChannels');
        $task = $article->task;
        if ($task === null || (string) $task->publish_scope !== 'distribution_only') {
            throw new DomainException('Hosted site tasks require distribution_only publish scope.');
        }

        $hostedChannels = $task->distributionChannels
            ->filter(static fn (DistributionChannel $channel): bool => $channel->isHostedSite())
            ->values();
        if ($hostedChannels->count() !== 1) {
            throw new DomainException('Phase one requires exactly one hosted site per task.');
        }
        $profile = $hostedChannels->first()?->hostedSiteProfile;
        if (! $profile instanceof HostedSiteProfile) {
            throw new DomainException('The hosted site profile is missing.');
        }

        if (! in_array((string) $article->status, ['private', 'published'], true)
            || (string) $article->review_status !== 'approved') {
            throw new DomainException('Article is not eligible for hosted site distribution.');
        }

        $request = HostedSiteAllocationRequest::query()->firstOrCreate(
            ['article_id' => (int) $article->id],
            [
                'task_id' => (int) $task->id,
                'hosted_site_profile_id' => (int) $profile->id,
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'attempt_count' => 0,
                'next_attempt_at' => now(),
            ]
        );

        if ($request->status === HostedSiteAllocationRequest::STATUS_CANCELLED) {
            $request->forceFill([
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'task_id' => (int) $task->id,
                'hosted_site_profile_id' => (int) $profile->id,
                'next_attempt_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        }

        return $request;
    }

    public function cancel(Article $article): void
    {
        HostedSiteAllocationRequest::query()
            ->where('article_id', (int) $article->id)
            ->whereNot('status', HostedSiteAllocationRequest::STATUS_ASSIGNED)
            ->update([
                'status' => HostedSiteAllocationRequest::STATUS_CANCELLED,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }
}
