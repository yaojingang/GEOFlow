<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Support\GeoFlow\ArticleWorkflow;
use DomainException;
use Illuminate\Support\Facades\DB;

final class HostedSiteAllocationRequestService
{
    public function request(Article $article): HostedSiteAllocationRequest
    {
        if (! config('geoflow.hosted_sites.enabled', false)) {
            throw new DomainException('Hosted sites are disabled.');
        }

        $article->load('task.distributionChannels.hostedSiteProfile');
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
            || ! ArticleWorkflow::isPublishableReviewStatus($article->review_status)) {
            throw new DomainException('Article is not eligible for hosted site distribution.');
        }

        return DB::transaction(function () use ($article, $task, $hostedChannels, $profile): HostedSiteAllocationRequest {
            $channelId = (int) $hostedChannels->first()->id;
            $lockedChannel = DistributionChannel::query()
                ->whereKey($channelId)
                ->lockForUpdate()
                ->first();
            $lockedProfile = HostedSiteProfile::query()
                ->whereKey((int) $profile->id)
                ->where('distribution_channel_id', $channelId)
                ->lockForUpdate()
                ->first();
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $article->id)
                ->lockForUpdate()
                ->first();
            $lockedArticle = Article::query()->whereKey((int) $article->id)->lockForUpdate()->first();
            $lockedTask = $lockedArticle instanceof Article
                ? Task::query()->whereKey((int) $lockedArticle->task_id)->lockForUpdate()->first()
                : null;
            $hostedChannelIds = $lockedTask
                ? DistributionChannel::query()
                    ->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE)
                    ->whereHas('tasks', fn ($query) => $query->whereKey((int) $lockedTask->id))
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all()
                : [];

            if (! $lockedChannel?->isHostedSite()
                || ! $lockedProfile
                || ! $lockedArticle
                || ! $lockedTask
                || (int) $lockedTask->id !== (int) $task->id
                || (string) $lockedTask->publish_scope !== 'distribution_only'
                || $hostedChannelIds !== [$channelId]
                || ! in_array((string) $lockedArticle->status, ['private', 'published'], true)
                || ! ArticleWorkflow::isPublishableReviewStatus($lockedArticle->review_status)) {
                throw new DomainException('The article or task changed while requesting hosted distribution.');
            }

            $request ??= new HostedSiteAllocationRequest([
                'article_id' => (int) $lockedArticle->id,
                'attempt_count' => 0,
            ]);
            if ($request->hosted_site_article_assignment_id !== null) {
                return $request;
            }

            $request->forceFill([
                'task_id' => (int) $lockedTask->id,
                'hosted_site_profile_id' => (int) $lockedProfile->id,
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'next_attempt_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return $request;
        }, 3);
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
