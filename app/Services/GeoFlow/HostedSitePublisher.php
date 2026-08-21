<?php

namespace App\Services\GeoFlow;

use App\Exceptions\HostedSitesDisabled;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\HostedSites\HostedSiteContentFingerprint;
use App\Services\Site\HostedSiteResolver;
use App\Support\GeoFlow\ArticleWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HostedSitePublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly HostedSiteContentFingerprint $fingerprints,
        private readonly HostedSiteResolver $resolver,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        if (! config('geoflow.hosted_sites.enabled', false)) {
            return ['healthy' => false, 'status' => 'disabled', 'hostname' => null];
        }

        $profile = $channel->hostedSiteProfile;

        return [
            'healthy' => $profile instanceof HostedSiteProfile
                && $profile->serving_status !== HostedSiteProfile::SERVING_ARCHIVED,
            'status' => $profile?->serving_status ?? 'missing_profile',
            'hostname' => $profile?->hostname,
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->write($distribution, HostedSiteArticleAssignment::STATUS_PUBLISHED);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->write($distribution, HostedSiteArticleAssignment::STATUS_PUBLISHED);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        return $this->write($distribution, HostedSiteArticleAssignment::STATUS_WITHDRAWN);
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        if (! config('geoflow.hosted_sites.enabled', false)) {
            throw new HostedSitesDisabled;
        }

        $profile = DB::transaction(function () use ($channel): HostedSiteProfile {
            DistributionChannel::query()->whereKey((int) $channel->id)->lockForUpdate()->firstOrFail();
            $profile = HostedSiteProfile::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->lockForUpdate()
                ->firstOrFail();
            $profile->increment('settings_version');

            return $profile->fresh();
        });
        $this->resolver->invalidate($profile->hostname);

        return ['synced' => true, 'settings_version' => (int) $profile->settings_version];
    }

    /** @return array<string,mixed> */
    private function write(ArticleDistribution $distribution, string $status): array
    {
        $result = DB::transaction(function () use ($distribution, $status): array {
            $channel = DistributionChannel::query()
                ->whereKey((int) $distribution->distribution_channel_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! config('geoflow.hosted_sites.enabled', false)) {
                throw new HostedSitesDisabled;
            }
            $profile = HostedSiteProfile::query()
                ->where('distribution_channel_id', (int) $channel->id)
                ->lockForUpdate()
                ->firstOrFail();
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $distribution->article_id)
                ->lockForUpdate()
                ->first();
            $article = $distribution->article()->lockForUpdate()->firstOrFail();
            $task = Task::query()->whereKey((int) $article->task_id)->lockForUpdate()->first();
            $assignment = HostedSiteArticleAssignment::query()
                ->where('article_id', (int) $distribution->article_id)
                ->where('hosted_site_profile_id', (int) $profile->id)
                ->lockForUpdate()
                ->first();
            ArticleDistribution::query()
                ->whereKey((int) $distribution->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $assignment) {
                throw new RuntimeException('Hosted site assignment is missing.');
            }

            $published = $status === HostedSiteArticleAssignment::STATUS_PUBLISHED;
            $firstPublication = $published
                && $assignment->status !== HostedSiteArticleAssignment::STATUS_PUBLISHED;
            if ($published) {
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
                    || ! $request
                    || (int) $request->task_id !== (int) $task->id
                    || (int) $request->hosted_site_profile_id !== (int) $profile->id
                    || (string) $task->publish_scope !== 'distribution_only'
                    || $hostedChannelIds !== [(int) $channel->id]
                    || ! in_array((string) $article->status, ['private', 'published'], true)
                    || ! ArticleWorkflow::isPublishableReviewStatus($article->review_status)) {
                    throw new RuntimeException('The article no longer satisfies the hosted publication contract.');
                }
            }
            if ($published
                && ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE
                    || $profile->serving_status !== HostedSiteProfile::SERVING_ONLINE
                    || $profile->quality_status === HostedSiteProfile::QUALITY_BLOCKED)) {
                throw new RuntimeException('Hosted site is not eligible to publish content.');
            }

            if ($firstPublication) {
                $capacityDate = CarbonImmutable::now($profile->timezone)->toDateString();
                $usedCapacity = HostedSiteArticleAssignment::query()
                    ->where('hosted_site_profile_id', (int) $profile->id)
                    ->whereKeyNot((int) $assignment->id)
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
                    throw new RuntimeException('Hosted site daily capacity is full at publication time.');
                }
                if ($profile->last_published_at !== null
                    && $profile->last_published_at
                        ->addMinutes((int) $profile->min_publish_interval_minutes)
                        ->isFuture()) {
                    throw new RuntimeException('Hosted site publish interval has not elapsed.');
                }

                $assignment->capacity_date = $capacityDate;
            }
            $assignment->forceFill([
                'status' => $status,
                'content_fingerprint' => $published
                    ? $this->fingerprints->forArticle($article)
                    : $assignment->content_fingerprint,
                'reservation_expires_at' => null,
                'published_at' => $published ? ($assignment->published_at ?? now()) : $assignment->published_at,
                'withdrawn_at' => $published ? null : now(),
                'last_error_message' => null,
            ])->save();

            if ($firstPublication) {
                $profile->forceFill([
                    'last_published_at' => now(),
                    'consecutive_publish_failures' => 0,
                    'cooldown_until' => null,
                ])->save();
            }

            return [$profile, $assignment, $article];
        }, 3);

        [$profile, $assignment, $article] = $result;
        $this->resolver->invalidate($profile->hostname);
        $url = 'https://'.$profile->hostname.'/article/'.$article->slug;

        return [
            'remote_id' => (string) $assignment->id,
            'remote_url' => $status === HostedSiteArticleAssignment::STATUS_WITHDRAWN ? null : $url,
            'remote_meta' => [
                'hosted_site_profile_id' => (int) $profile->id,
                'assignment_status' => $status,
            ],
        ];
    }
}
