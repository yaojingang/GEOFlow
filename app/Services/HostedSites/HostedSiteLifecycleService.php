<?php

namespace App\Services\HostedSites;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Services\Site\HostedSiteResolver;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class HostedSiteLifecycleService
{
    public function __construct(
        private readonly HostedSiteResolver $resolver,
        private readonly SiteThemeCatalog $themes,
        private readonly HostedSiteQualityService $quality,
    ) {}

    /** @param array<string,mixed> $payload */
    public function create(array $payload, ?int $adminId): DistributionChannel
    {
        $hostname = (string) $payload['hostname'];
        $rootDomain = $this->resolver->rootDomainFor($hostname)
            ?? throw new DomainException('Hosted root domain is not configured.');

        $channel = DB::transaction(function () use ($payload, $hostname, $rootDomain, $adminId): DistributionChannel {
            $channel = DistributionChannel::query()->create([
                'name' => (string) $payload['name'],
                'domain' => $hostname,
                'endpoint_url' => 'https://'.$hostname,
                'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
                'front_mode' => 'rewrite',
                'template_key' => filled($payload['template_key'] ?? null)
                    ? (string) $payload['template_key']
                    : ($this->themes->hostedCompatibleIds()[0] ?? 'default'),
                'site_settings' => $this->siteSettings($payload),
                'channel_config' => ['frontend_experience_mode' => DistributionChannel::FRONTEND_EXPERIENCE_SNAPSHOT_DEFAULT],
                'status' => DistributionChannel::STATUS_PAUSED,
                'created_by_admin_id' => $adminId,
            ]);
            HostedSiteProfile::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'hostname' => $hostname,
                'root_domain' => $rootDomain,
                'topic' => (string) $payload['topic'],
                'locale' => (string) $payload['locale'],
                'timezone' => (string) $payload['timezone'],
                'daily_publish_limit' => (int) $payload['daily_publish_limit'],
                'publish_weight' => (int) ($payload['publish_weight'] ?? 100),
                'min_publish_interval_minutes' => (int) $payload['min_publish_interval_minutes'],
                'min_articles_before_index' => (int) $payload['min_articles_before_index'],
            ]);

            return $channel->fresh('hostedSiteProfile');
        }, 3);

        $this->resolver->invalidate($hostname);

        return $channel;
    }

    /** @param array<string,mixed> $payload */
    public function update(DistributionChannel $channel, array $payload): DistributionChannel
    {
        $oldHostname = (string) $channel->domain;
        $hostname = (string) $payload['hostname'];
        $rootDomain = $this->resolver->rootDomainFor($hostname)
            ?? throw new DomainException('Hosted root domain is not configured.');

        $updated = DB::transaction(function () use ($channel, $payload, $hostname, $rootDomain): DistributionChannel {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            if ($profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED) {
                throw new DomainException('Archived hosted sites cannot be edited.');
            }

            $lockedChannel->forceFill([
                'name' => (string) $payload['name'],
                'domain' => $hostname,
                'endpoint_url' => 'https://'.$hostname,
                'template_key' => filled($payload['template_key'] ?? null)
                    ? (string) $payload['template_key']
                    : $lockedChannel->template_key,
                'site_settings' => $this->siteSettings(
                    $payload,
                    is_array($lockedChannel->site_settings) ? $lockedChannel->site_settings : [],
                ),
                'status' => DistributionChannel::STATUS_PAUSED,
                'last_health_status' => null,
                'last_health_checked_at' => null,
                'channel_config' => $this->withoutActivationToken($lockedChannel),
            ])->save();
            $profile->forceFill([
                'hostname' => $hostname,
                'root_domain' => $rootDomain,
                'topic' => (string) $payload['topic'],
                'locale' => (string) $payload['locale'],
                'timezone' => (string) $payload['timezone'],
                'daily_publish_limit' => (int) $payload['daily_publish_limit'],
                'publish_weight' => (int) ($payload['publish_weight'] ?? 100),
                'min_publish_interval_minutes' => (int) $payload['min_publish_interval_minutes'],
                'min_articles_before_index' => (int) $payload['min_articles_before_index'],
                'settings_version' => (int) $profile->settings_version + 1,
                'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
                'quality_status' => HostedSiteProfile::QUALITY_PENDING,
                'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                'indexed_at' => null,
            ])->save();

            return $lockedChannel->fresh('hostedSiteProfile');
        }, 3);

        $this->resolver->invalidate($oldHostname);
        $this->resolver->invalidate($hostname);

        return $updated;
    }

    public function activate(DistributionChannel $channel): DistributionChannel
    {
        $activationToken = (string) Str::uuid();
        $candidate = DB::transaction(function () use ($channel, $activationToken): DistributionChannel {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            $freshPreflight = $lockedChannel->last_health_checked_at !== null
                && $lockedChannel->last_health_checked_at->gte(now()->subMinutes(
                    (int) config('geoflow.hosted_sites.preflight_fresh_minutes', 15)
                ));
            if ((string) $lockedChannel->last_health_status !== 'ok'
                || ! $freshPreflight
                || $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED) {
                throw new DomainException('Hosted site must pass preflight before activation.');
            }
            $channelConfig = is_array($lockedChannel->channel_config) ? $lockedChannel->channel_config : [];
            $channelConfig['hosted_site_activation_token'] = $activationToken;
            $lockedChannel->forceFill([
                'status' => DistributionChannel::STATUS_PAUSED,
                'channel_config' => $channelConfig,
            ])->save();
            $profile->forceFill([
                'serving_status' => HostedSiteProfile::SERVING_ONLINE,
                'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                'indexed_at' => null,
            ])->save();

            return $lockedChannel->fresh('hostedSiteProfile');
        }, 3);

        try {
            $onlinePreflight = $this->quality->preflight($candidate, true, true);
        } catch (\Throwable $exception) {
            $this->returnActivationProbeToMaintenance($candidate, $activationToken);

            throw new DomainException('Hosted site online preflight could not be completed.', 0, $exception);
        }
        if (! $onlinePreflight['passed']) {
            $this->returnActivationProbeToMaintenance($candidate, $activationToken);

            throw new DomainException('Hosted site failed the online preflight and returned to maintenance.');
        }

        try {
            return DB::transaction(function () use ($candidate, $activationToken): DistributionChannel {
                $lockedChannel = $this->lockHostedChannel($candidate);
                $profile = $this->lockProfile($lockedChannel);
                $freshPreflight = $lockedChannel->last_health_checked_at !== null
                    && $lockedChannel->last_health_checked_at->gte(now()->subMinutes(
                        (int) config('geoflow.hosted_sites.preflight_fresh_minutes', 15)
                    ));
                if ((string) data_get($lockedChannel->channel_config, 'hosted_site_activation_token') !== $activationToken
                    || (string) $lockedChannel->status !== DistributionChannel::STATUS_PAUSED
                    || $profile->serving_status !== HostedSiteProfile::SERVING_ONLINE
                    || $profile->quality_status !== HostedSiteProfile::QUALITY_PASSED
                    || (string) $lockedChannel->last_health_status !== 'ok'
                    || ! $freshPreflight) {
                    throw new DomainException('Hosted site changed during online preflight.');
                }
                $lockedChannel->forceFill([
                    'status' => DistributionChannel::STATUS_ACTIVE,
                    'channel_config' => $this->withoutActivationToken($lockedChannel),
                ])->save();
                $profile->forceFill(['activated_at' => now()])->save();

                return $lockedChannel->fresh('hostedSiteProfile');
            }, 3);
        } catch (\Throwable $exception) {
            $this->returnActivationProbeToMaintenance($candidate, $activationToken);

            throw $exception;
        }
    }

    public function pause(DistributionChannel $channel): DistributionChannel
    {
        return DB::transaction(function () use ($channel): DistributionChannel {
            $lockedChannel = $this->lockHostedChannel($channel);
            $lockedChannel->forceFill([
                'status' => DistributionChannel::STATUS_PAUSED,
                'channel_config' => $this->withoutActivationToken($lockedChannel),
            ])->save();

            return $lockedChannel->fresh('hostedSiteProfile');
        }, 3);
    }

    public function maintenance(DistributionChannel $channel): DistributionChannel
    {
        return DB::transaction(function () use ($channel): DistributionChannel {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            $lockedChannel->forceFill([
                'status' => DistributionChannel::STATUS_PAUSED,
                'channel_config' => $this->withoutActivationToken($lockedChannel),
            ])->save();
            $profile->forceFill([
                'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
                'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                'indexed_at' => null,
            ])->save();

            return $lockedChannel->fresh('hostedSiteProfile');
        }, 3);
    }

    public function setIndexing(
        DistributionChannel $channel,
        string $status,
        bool $qualityConfirmed,
    ): DistributionChannel {
        if ($status === HostedSiteProfile::INDEXING_INDEX) {
            $preflight = $this->quality->preflight($channel, true, true);
            if (! $preflight['passed']) {
                throw new DomainException('Hosted site must pass a current online preflight before indexing.');
            }
        }

        return DB::transaction(function () use ($channel, $status, $qualityConfirmed): DistributionChannel {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            if ($status === HostedSiteProfile::INDEXING_INDEX) {
                $published = $profile->assignments()
                    ->where('status', HostedSiteArticleAssignment::STATUS_PUBLISHED)
                    ->whereHas('article', function ($article): void {
                        $article->whereNull('deleted_at')
                            ->whereIn('review_status', ArticleWorkflow::PUBLISHABLE_REVIEW_STATUSES)
                            ->whereIn('status', ['private', 'published'])
                            ->whereHas('task', fn ($task) => $task->where('publish_scope', 'distribution_only'));
                    })
                    ->count();
                $freshPreflight = $lockedChannel->last_health_checked_at !== null
                    && $lockedChannel->last_health_checked_at->gte(now()->subMinutes(
                        (int) config('geoflow.hosted_sites.preflight_fresh_minutes', 15)
                    ));
                if (! $qualityConfirmed
                    || $profile->serving_status !== HostedSiteProfile::SERVING_ONLINE
                    || (string) $lockedChannel->last_health_status !== 'ok'
                    || ! $freshPreflight
                    || $published < (int) $profile->min_articles_before_index) {
                    throw new DomainException('Hosted site does not meet the indexing quality gate.');
                }
                $observationMinutes = max(
                    0,
                    (int) config('geoflow.hosted_sites.index_observation_minutes', 30)
                );
                if ($observationMinutes > 0) {
                    $observationStart = now()->subMinutes($observationMinutes);
                    if ($profile->activated_at === null || $profile->activated_at->gt($observationStart)) {
                        throw new DomainException('Hosted site must complete its observation window before indexing.');
                    }
                    $recentServerErrors = DB::table('view_logs')
                        ->where('hosted_site_profile_id', (int) $profile->id)
                        ->where('created_at', '>=', $observationStart)
                        ->where('status_code', '>=', 500)
                        ->exists();
                    if ($recentServerErrors) {
                        throw new DomainException('Hosted site has recent 5xx responses and cannot be indexed.');
                    }
                }
                $profile->forceFill([
                    'quality_status' => HostedSiteProfile::QUALITY_PASSED,
                    'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
                    'indexed_at' => $profile->indexed_at ?? now(),
                ])->save();
            } else {
                $profile->forceFill([
                    'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                    'indexed_at' => null,
                ])->save();
            }

            return $lockedChannel->fresh('hostedSiteProfile');
        }, 3);
    }

    public function restorePublication(Article $candidate): HostedSiteArticleAssignment
    {
        $candidateAssignment = HostedSiteArticleAssignment::query()
            ->with('profile:id,distribution_channel_id')
            ->where('article_id', (int) $candidate->id)
            ->firstOrFail();
        $channelId = (int) $candidateAssignment->profile?->distribution_channel_id;

        return DB::transaction(function () use ($candidate, $channelId): HostedSiteArticleAssignment {
            $channel = DistributionChannel::query()->whereKey($channelId)->lockForUpdate()->firstOrFail();
            $profile = HostedSiteProfile::query()
                ->where('distribution_channel_id', $channelId)
                ->lockForUpdate()
                ->firstOrFail();
            $request = HostedSiteAllocationRequest::query()
                ->where('article_id', (int) $candidate->id)
                ->lockForUpdate()
                ->first();
            $article = Article::query()->whereKey((int) $candidate->id)->lockForUpdate()->firstOrFail();
            $assignment = HostedSiteArticleAssignment::query()
                ->where('article_id', (int) $article->id)
                ->where('hosted_site_profile_id', (int) $profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $distributions = ArticleDistribution::query()
                ->where('article_id', (int) $article->id)
                ->where('distribution_channel_id', $channelId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($assignment->status === HostedSiteArticleAssignment::STATUS_PUBLISHED
                || $assignment->status === HostedSiteArticleAssignment::STATUS_RESERVED) {
                return $assignment;
            }
            if ($assignment->status !== HostedSiteArticleAssignment::STATUS_WITHDRAWN) {
                throw new DomainException('Only a withdrawn hosted article can be restored explicitly.');
            }
            if ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE
                || $profile->serving_status !== HostedSiteProfile::SERVING_ONLINE) {
                throw new DomainException('Hosted site must be online before restoring an article.');
            }

            $task = $article->task()->lockForUpdate()->first();
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
                || (string) $task->publish_scope !== 'distribution_only'
                || $hostedChannelIds !== [$channelId]
                || ! in_array((string) $article->status, ['private', 'published'], true)
                || ! ArticleWorkflow::isPublishableReviewStatus($article->review_status)) {
                throw new DomainException('The article no longer satisfies the hosted publication contract.');
            }

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
                throw new DomainException('Hosted site daily capacity is full.');
            }

            $assignment->forceFill([
                'status' => HostedSiteArticleAssignment::STATUS_RESERVED,
                'capacity_date' => $capacityDate,
                'reservation_expires_at' => now()->addMinutes(
                    (int) config('geoflow.hosted_sites.reservation_ttl_minutes', 30)
                ),
                'withdrawn_at' => null,
                'last_error_message' => null,
            ])->save();

            $request ??= new HostedSiteAllocationRequest([
                'article_id' => (int) $article->id,
                'task_id' => (int) $task->id,
            ]);
            $request->forceFill([
                'task_id' => (int) $task->id,
                'hosted_site_profile_id' => (int) $profile->id,
                'hosted_site_article_assignment_id' => (int) $assignment->id,
                'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $distribution = $distributions->firstWhere('action', 'publish')
                ?? new ArticleDistribution([
                    'article_id' => (int) $article->id,
                    'distribution_channel_id' => $channelId,
                    'action' => 'publish',
                ]);
            $distribution->forceFill([
                'status' => 'queued',
                'idempotency_key' => 'hosted-article-'.$article->id.'-channel-'.$channelId.'-restore-v1',
                'next_retry_at' => now(),
                'payload_hash' => $assignment->content_fingerprint,
                'last_error_message' => null,
            ])->save();
            ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                ->onQueue('distribution')
                ->afterCommit();

            return $assignment;
        }, 3);
    }

    public function archive(DistributionChannel $channel, string $confirmedHostname): void
    {
        DB::transaction(function () use ($channel, $confirmedHostname): void {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            if (! hash_equals($profile->hostname, strtolower(trim($confirmedHostname)))) {
                throw new DomainException('Hostname confirmation does not match.');
            }
            $lockedChannel->forceFill([
                'status' => DistributionChannel::STATUS_PAUSED,
                'channel_config' => $this->withoutActivationToken($lockedChannel),
            ])->save();
            $profile->forceFill([
                'serving_status' => HostedSiteProfile::SERVING_ARCHIVED,
                'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                'archived_at' => now(),
                'indexed_at' => null,
            ])->save();
            $linkedTaskIds = DB::table('task_distribution_channels')
                ->where('distribution_channel_id', (int) $lockedChannel->id)
                ->pluck('task_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $assignmentIds = HostedSiteArticleAssignment::query()
                ->where('hosted_site_profile_id', (int) $profile->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $requests = HostedSiteAllocationRequest::query()
                ->where(function ($query) use ($profile, $linkedTaskIds, $assignmentIds): void {
                    $query->where('hosted_site_profile_id', (int) $profile->id)
                        ->orWhereIn('task_id', $linkedTaskIds)
                        ->orWhereIn('hosted_site_article_assignment_id', $assignmentIds);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $articleIds = $requests->pluck('article_id')
                ->merge(HostedSiteArticleAssignment::query()
                    ->where('hosted_site_profile_id', (int) $profile->id)
                    ->pluck('article_id'))
                ->map(static fn ($id): int => (int) $id)
                ->unique()
                ->sort()
                ->values();
            if ($articleIds->isNotEmpty()) {
                Article::query()->whereIn('id', $articleIds)->orderBy('id')->lockForUpdate()->get();
            }
            $reservedAssignments = HostedSiteArticleAssignment::query()
                ->where('hosted_site_profile_id', (int) $profile->id)
                ->where('status', HostedSiteArticleAssignment::STATUS_RESERVED)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $reservedArticleIds = $reservedAssignments->pluck('article_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $inFlightDistributions = ArticleDistribution::query()
                ->where('distribution_channel_id', (int) $lockedChannel->id)
                ->whereIn('article_id', $reservedArticleIds)
                ->whereIn('status', ['queued', 'sending'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($reservedAssignments as $assignment) {
                $assignment->forceFill([
                    'status' => HostedSiteArticleAssignment::STATUS_FAILED,
                    'reservation_expires_at' => null,
                    'last_error_message' => 'Hosted site was archived before publication completed.',
                ])->save();
            }
            foreach ($inFlightDistributions as $distribution) {
                $distribution->forceFill([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => 'Hosted site was archived before publication completed.',
                ])->save();
            }
            foreach ($requests as $request) {
                if (in_array((string) $request->status, [
                    HostedSiteAllocationRequest::STATUS_PENDING,
                    HostedSiteAllocationRequest::STATUS_ALLOCATING,
                ], true) || in_array((int) $request->article_id, $reservedArticleIds, true)) {
                    $request->forceFill([
                        'status' => HostedSiteAllocationRequest::STATUS_CANCELLED,
                        'next_attempt_at' => null,
                        'last_error_code' => 'site_archived',
                        'last_error_message' => 'Hosted site was archived.',
                    ])->save();
                }
            }
            DB::table('task_distribution_channels')
                ->where('distribution_channel_id', (int) $lockedChannel->id)
                ->delete();
        }, 3);

        $this->resolver->invalidate((string) $channel->domain);
    }

    private function lockHostedChannel(DistributionChannel $channel): DistributionChannel
    {
        $locked = DistributionChannel::query()->whereKey((int) $channel->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isHostedSite()) {
            throw new DomainException('Channel is not a hosted site.');
        }

        return $locked;
    }

    /** @return array<string,mixed> */
    private function withoutActivationToken(DistributionChannel $channel): array
    {
        $channelConfig = is_array($channel->channel_config) ? $channel->channel_config : [];
        unset($channelConfig['hosted_site_activation_token']);

        return $channelConfig;
    }

    private function returnActivationProbeToMaintenance(
        DistributionChannel $channel,
        string $activationToken,
    ): void {
        DB::transaction(function () use ($channel, $activationToken): void {
            $lockedChannel = $this->lockHostedChannel($channel);
            $profile = $this->lockProfile($lockedChannel);
            if ((string) data_get($lockedChannel->channel_config, 'hosted_site_activation_token') !== $activationToken
                || $profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED) {
                return;
            }
            $lockedChannel->forceFill([
                'status' => DistributionChannel::STATUS_PAUSED,
                'channel_config' => $this->withoutActivationToken($lockedChannel),
            ])->save();
            $profile->forceFill([
                'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
                'indexing_status' => HostedSiteProfile::INDEXING_NOINDEX,
                'indexed_at' => null,
            ])->save();
        }, 3);
    }

    private function lockProfile(DistributionChannel $channel): HostedSiteProfile
    {
        return HostedSiteProfile::query()
            ->where('distribution_channel_id', (int) $channel->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function siteSettings(array $payload, ?array $existing = null): array
    {
        $safeKeys = [
            'site_name',
            'site_description',
            'site_keywords',
            'about_title',
            'about_content',
            'contact_email',
            'lead_form_slugs',
            'theme_id',
            'featured_limit',
            'per_page',
            'homepage_style',
        ];
        $settings = array_intersect_key(
            $existing ?? SiteSettingsBag::primaryAll(),
            array_flip($safeKeys)
        );

        return array_filter(array_replace($settings, [
            'site_name' => trim((string) ($payload['name'] ?? '')),
            'site_description' => trim((string) ($payload['site_description'] ?? '')),
            'site_keywords' => trim((string) ($payload['site_keywords'] ?? '')),
            'about_title' => trim((string) ($payload['about_title'] ?? '')),
            'about_content' => trim((string) ($payload['about_content'] ?? '')),
            'contact_email' => strtolower(trim((string) ($payload['contact_email'] ?? ''))),
            'lead_form_slugs' => array_values(array_unique(array_map(
                'strval',
                is_array($payload['lead_form_slugs'] ?? null) ? $payload['lead_form_slugs'] : []
            ))),
            'theme_id' => trim((string) ($payload['template_key'] ?? '')),
        ]), static fn (mixed $value): bool => $value !== '' && $value !== []);
    }
}
