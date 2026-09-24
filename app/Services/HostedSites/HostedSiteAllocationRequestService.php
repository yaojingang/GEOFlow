<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Support\GeoFlow\ArticleWorkflow;
use DomainException;
use Illuminate\Support\Facades\DB;

final class HostedSiteAllocationRequestService
{
    public function __construct(private readonly ArticlePublicationQualityGate $publicationQualityGate) {}

    public function request(Article $article, ?array $workflowFence = null): HostedSiteAllocationRequest
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

        $workflowFence ??= app(ArticlePublicationEligibilityService::class)->fence($article);

        return DB::transaction(function () use ($article, $task, $hostedChannels, $profile, $workflowFence): HostedSiteAllocationRequest {
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
            $lockedTask = Task::query()->whereKey((int) $task->id)->lockForUpdate()->first();
            $lockedArticle = Article::query()->whereKey((int) $article->id)->lockForUpdate()->first();
            $lockedArticle?->setRelation('task', $lockedTask);
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $article->id)
                ->lockForUpdate()
                ->first();
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
                || (int) $lockedArticle->task_id !== (int) $lockedTask->id
                || ! app(DistributionOrchestrator::class)->workflowFenceMatches($lockedArticle, $workflowFence)
                || (string) $lockedTask->publish_scope !== 'distribution_only'
                || $hostedChannelIds !== [$channelId]
                || ! in_array((string) $lockedArticle->status, ['private', 'published'], true)
                || ! ArticleWorkflow::isPublishableReviewStatus($lockedArticle->review_status)) {
                throw new DomainException('The article or task changed while requesting hosted distribution.');
            }

            $this->publicationQualityGate->check($lockedArticle, 'hosted_site_allocation_request');

            $request ??= new HostedSiteAllocationRequest([
                'article_id' => (int) $lockedArticle->id,
                'attempt_count' => 0,
            ]);
            if ($request->hosted_site_article_assignment_id !== null) {
                $request->forceFill(['workflow_fence' => $workflowFence])->save();

                return $request;
            }

            $request->forceFill([
                'task_id' => (int) $lockedTask->id,
                'hosted_site_profile_id' => (int) $lockedProfile->id,
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'workflow_fence' => $workflowFence,
                'next_attempt_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            return $request;
        }, 3);
    }

    /** Reauthorize never-allocated automatic requests only after an explicit task resume. */
    public function resumeForTask(int $taskId): int
    {
        $resumed = 0;
        $candidates = HostedSiteAllocationRequest::query()->where('task_id', $taskId)
            ->whereNull('hosted_site_article_assignment_id')
            ->whereIn('status', [HostedSiteAllocationRequest::STATUS_PENDING, HostedSiteAllocationRequest::STATUS_ALLOCATING, HostedSiteAllocationRequest::STATUS_CANCELLED])
            ->with('profile')->lazyById(100);
        foreach ($candidates as $candidate) {
            $resumed += (int) DB::transaction(function () use ($candidate, $taskId): bool {
                $channelId = (int) $candidate->profile?->distribution_channel_id;
                $channel = DistributionChannel::query()->whereKey($channelId)->lockForUpdate()->first();
                $profile = HostedSiteProfile::query()->whereKey($candidate->hosted_site_profile_id)->lockForUpdate()->first();
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
                $article = Article::query()->whereKey($candidate->article_id)->lockForUpdate()->first();
                $article?->setRelation('task', $task);
                $request = HostedSiteAllocationRequest::query()->whereKey($candidate->id)->lockForUpdate()->first();
                $fence = $request?->workflow_fence;
                if (! $channel?->isHostedSite() || ! $profile || ! $task || ! $article || ! $request
                    || (int) $request->hosted_site_profile_id !== (int) $profile->id
                    || (int) $request->task_id !== $taskId || (int) $article->task_id !== $taskId
                    || $request->hosted_site_article_assignment_id !== null
                    || $task->status !== 'active' || ! $task->schedule_enabled
                    || ! in_array($request->status, [HostedSiteAllocationRequest::STATUS_PENDING, HostedSiteAllocationRequest::STATUS_ALLOCATING, HostedSiteAllocationRequest::STATUS_CANCELLED], true)
                    || ($request->status === HostedSiteAllocationRequest::STATUS_CANCELLED && $request->last_error_code !== 'distribution_workflow_superseded')
                    || ! is_array($fence) || ($fence['origin'] ?? '') !== 'automatic'
                    || (int) ($fence['task_id'] ?? 0) !== $taskId
                    || (int) ($fence['workflow_version'] ?? 0) !== (int) $article->workflow_version
                    || (int) ($fence['automation_version'] ?? 0) === (int) $task->automation_version
                    || $article->publication_intent !== 'none'
                    || ! in_array($article->status, ['published', 'private'], true)
                    || ! ArticleWorkflow::isPublishableReviewStatus($article->review_status)) {
                    return false;
                }
                $request->update([
                    'workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article),
                    'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                    'next_attempt_at' => now(), 'last_error_code' => null, 'last_error_message' => null,
                ]);

                return true;
            }, 3);
        }

        return $resumed;
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
