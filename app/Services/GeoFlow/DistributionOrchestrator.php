<?php

namespace App\Services\GeoFlow;

use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiWorkspaceChannelRevision;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\DistributionAcknowledgedResponseException;
use App\Exceptions\DistributionTaskRevisionMismatch;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\AiWorkspace\AiWorkspaceDispatchGuard;
use App\Services\HostedSites\HostedSiteAllocationRequestService;
use App\Services\HostedSites\HostedSiteAllocator;
use App\Services\HostedSites\HostedSiteLifecycleService;
use App\Support\GeoFlow\DistributionErrorSanitizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class DistributionOrchestrator
{
    /** Rebind unsent work and previously authorized retries after an explicit task resume. */
    public function resumeUnsentForTask(int $taskId): int
    {
        $resumed = 0;
        $deliveries = ArticleDistribution::query()->whereHas('article', fn ($query) => $query->where('task_id', $taskId))
            ->whereIn('status', ['queued', 'cancelled'])
            ->where(fn ($query) => $query->where(fn ($fresh) => $fresh->where('attempt_count', 0)->whereNull('last_attempt_at'))
                ->orWhere('remote_meta->transport_not_started', true)->orWhereNotNull('remote_meta->safe_retry_at'))->lazyById(100);
        foreach ($deliveries as $delivery) {
            try {
                $changed = DB::transaction(function () use ($delivery, $taskId): bool {
                    $channel = DistributionChannel::query()->whereKey($delivery->distribution_channel_id)->lockForUpdate()->first();
                    $article = $this->lockWorkflowArticle((int) $delivery->article_id);
                    $locked = ArticleDistribution::query()->whereKey($delivery->id)->lockForUpdate()->first();
                    $fence = data_get($locked?->remote_meta, 'workflow_fence');
                    if (! $channel || $channel->status !== DistributionChannel::STATUS_ACTIVE || ! $article || ! $locked
                        || (int) $article->task_id !== $taskId || $article->task?->status !== 'active' || ! $article->task?->schedule_enabled
                        || ! in_array($locked->status, ['queued', 'cancelled'], true)
                        || (($locked->attempt_count || $locked->last_attempt_at) && ! data_get($locked->remote_meta, 'transport_not_started', false)
                            && ! data_get($locked->remote_meta, 'safe_retry_at'))
                        || ! is_array($fence) || ($fence['origin'] ?? '') !== 'automatic'
                        || (int) ($fence['workflow_version'] ?? 0) !== (int) $article->workflow_version
                        || ((int) ($fence['automation_version'] ?? 0) === (int) $article->task->automation_version
                            && ($locked->status !== 'queued' || data_get($locked->remote_meta, 'queue_dispatched_at')))
                        || $article->publication_intent !== 'none' || ! $this->isDistributableSnapshot($article)
                        || ! $this->currentChannelAllowsDelivery($article, $locked)) {
                        return false;
                    }
                    $meta = (array) $locked->remote_meta;
                    $meta['workflow_fence'] = app(ArticlePublicationEligibilityService::class)->fence($article);
                    unset($meta['queue_dispatched_at']);
                    $locked->update([
                        'status' => 'queued',
                        'next_retry_at' => data_get($meta, 'safe_retry_at') ?? now(),
                        'last_error_message' => null, 'remote_meta' => $meta,
                    ]);
                    $this->dispatchDeliveryAfterCommit((int) $locked->id);

                    return true;
                });
                $resumed += (int) $changed;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $resumed;
    }

    /** Recover committed deliveries whose queue submission failed, with a bounded retry budget. */
    public function recoverUndispatched(int $limit = 100): int
    {
        $recovered = 0;
        $candidates = ArticleDistribution::query()->where('status', 'queued')
            ->whereNull('remote_meta->queue_dispatched_at')
            ->where(fn ($query) => $query->whereNull('remote_meta->queue_submit_attempts')->orWhere('remote_meta->queue_submit_attempts', '<', 3))
            ->where(fn ($query) => $query->whereNull('remote_meta->queue_submit_retry_at')->orWhere('remote_meta->queue_submit_retry_at', '<=', now()->toIso8601String()))
            ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')->limit(max(1, min(100, $limit)))->get();
        foreach ($candidates as $candidate) {
            try {
                $recovered += (int) DB::transaction(function () use ($candidate): bool {
                    $channel = DistributionChannel::query()->whereKey($candidate->distribution_channel_id)->lockForUpdate()->first();
                    $article = $this->lockWorkflowArticle((int) $candidate->article_id);
                    $delivery = ArticleDistribution::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $delivery || $delivery->status !== 'queued' || data_get($delivery->remote_meta, 'queue_dispatched_at')) {
                        return false;
                    }
                    if (! $channel || $channel->status !== DistributionChannel::STATUS_ACTIVE || ! $article
                        || ($delivery->action !== 'delete' && (! $this->workflowFenceMatches($article, data_get($delivery->remote_meta, 'workflow_fence'))
                            || ! $this->isDistributableSnapshot($article) || ! $this->currentChannelAllowsDelivery($article, $delivery)))) {
                        $delivery->update(['status' => 'cancelled', 'next_retry_at' => null, 'last_error_message' => 'distribution_workflow_superseded']);

                        return false;
                    }
                    $this->dispatchDeliveryAfterCommit((int) $delivery->id);

                    return true;
                });
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $recovered;
    }

    public function __construct(
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionPublisherManager $publisherManager,
        private readonly TaskDistributionChannelSelector $channelSelector,
        private readonly ArticlePublicationQualityGate $publicationQualityGate,
        private readonly DistributionChannelOperationLeaseService $channelOperationLeaseService,
        private readonly HostedSiteAllocationRequestService $hostedAllocationRequests,
        private readonly HostedSiteAllocator $hostedSiteAllocator,
        private readonly HostedSiteLifecycleService $hostedSiteLifecycle,
        private readonly AiWorkspaceDispatchGuard $aiWorkspaceDispatchGuard,
        private readonly ArticleAiQualityRolloutPolicy $aiQualityRolloutPolicy,
    ) {}

    /**
     * @param  list<int>  $channelIds
     */
    public function syncTaskChannels(Task $task, array $channelIds): void
    {
        DB::transaction(function () use ($task, $channelIds): void {
            $this->lockTaskChannelSelection((int) $task->id, $channelIds);
            $activeIds = DistributionChannel::query()
                ->whereIn('id', $channelIds)
                ->where('status', DistributionChannel::STATUS_ACTIVE)
                ->pluck('id')
                ->mapWithKeys(static fn ($id): array => [(int) $id => true]);
            $lockedTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->firstOrFail();

            $requestedHostedCount = DistributionChannel::query()
                ->whereIn('id', array_keys($activeIds->all()))
                ->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE)
                ->count();
            if ($requestedHostedCount > 1) {
                throw new \DomainException('Phase one allows one hosted site per task.');
            }
            if ($requestedHostedCount === 1 && (string) $lockedTask->publish_scope !== 'distribution_only') {
                throw new \DomainException('Hosted site tasks require distribution_only publish scope.');
            }

            $syncPayload = [];
            $sortOrder = 0;
            $seen = [];
            foreach (array_values($channelIds) as $channelId) {
                $id = (int) $channelId;
                if ($id <= 0 || isset($seen[$id]) || ! isset($activeIds[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $syncPayload[$id] = [
                    'sort_order' => $sortOrder++,
                    'trigger' => 'after_local_publish',
                    'remote_status' => 'follow_local',
                    'failure_policy' => 'ignore_distribution_failure',
                    'max_attempts' => 3,
                ];
            }

            $lockedTask->distributionChannels()->sync($syncPayload);
        });
    }

    /**
     * @param  list<int>  $channelIds
     */
    public function lockTaskChannelSelection(?int $taskId, array $channelIds): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Task channel selection locks require an active database transaction.');
        }

        $requestedIds = collect($channelIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $existingIds = $taskId
            ? DB::table('task_distribution_channels')
                ->where('task_id', $taskId)
                ->pluck('distribution_channel_id')
                ->map(static fn ($id): int => (int) $id)
            : collect();
        $lockIds = $requestedIds->merge($existingIds)->unique()->sort()->values();
        if ($lockIds->isEmpty()) {
            return;
        }

        $lockedChannels = DistributionChannel::query()
            ->whereIn('id', $lockIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'status'])
            ->keyBy('id');
        $blockedExistingIds = $existingIds->filter(function (int $id) use ($lockedChannels): bool {
            $channel = $lockedChannels->get($id);

            return ! $channel || (string) $channel->status === DistributionChannel::STATUS_DELETING;
        });
        if ($blockedExistingIds->isNotEmpty()) {
            throw new \RuntimeException(__('admin.distribution.delete.operation_blocked'));
        }
        $unavailableIds = $requestedIds->filter(
            static fn (int $id): bool => ! isset($lockedChannels[$id])
                || (string) $lockedChannels[$id]->status !== DistributionChannel::STATUS_ACTIVE
        );
        if ($unavailableIds->isNotEmpty()) {
            throw new \RuntimeException(__('admin.distribution.delete.channel_unavailable_error'));
        }
    }

    public function taskRevision(Task $task): string
    {
        $channelIds = DB::table('task_distribution_channels')
            ->where('task_id', (int) $task->id)
            ->orderBy('distribution_channel_id')
            ->pluck('distribution_channel_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $qualityKnowledgeBaseIds = DB::table('task_knowledge_bases')
            ->where('task_id', (int) $task->id)
            ->orderBy('sort_order')
            ->orderBy('knowledge_base_id')
            ->pluck('knowledge_base_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $payload = [
            'id' => (int) $task->id,
            'status' => (string) $task->status,
            'publish_scope' => (string) $task->publish_scope,
            'channel_ids' => $channelIds,
            'ai_quality_retrieval_mode' => (string) $task->ai_quality_retrieval_mode,
            'ai_quality_policy_version' => max(1, (int) $task->ai_quality_policy_version),
            'quality_knowledge_base_ids' => $qualityKnowledgeBaseIds,
        ];

        return AiPayloadDigest::make($payload);
    }

    public function assertTaskRevision(int $taskId, string $expectedRevision): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Task revision checks require an active database transaction.');
        }

        $task = Task::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->firstOrFail();
        if (! hash_equals($this->taskRevision($task), $expectedRevision)) {
            throw new DistributionTaskRevisionMismatch(__('admin.distribution.delete.task_update_stale_error'));
        }
    }

    /** @return list<int> */
    public function enqueueForArticle(
        int|Article $article,
        string $action = 'publish',
        array $aiWorkspaceGuard = [],
        bool $throwOnFailure = false,
        ?array $workflowFence = null,
    ): array {
        return $this->enqueueForArticleSelection($article, $action, $aiWorkspaceGuard, null, $throwOnFailure, $workflowFence);
    }

    /**
     * Queue the exact approved channel targets without changing the task's saved distribution configuration.
     *
     * @param  list<int>  $channelIds
     * @param  array<string,mixed>  $aiWorkspaceGuard
     * @return list<int>
     */
    public function enqueueForArticleTargets(
        int|Article $article,
        array $channelIds,
        array $aiWorkspaceGuard,
        string $action = 'publish',
    ): array {
        return $this->enqueueForArticleSelection($article, $action, $aiWorkspaceGuard, $channelIds);
    }

    /**
     * @param  array<string,mixed>  $aiWorkspaceGuard
     * @param  list<int>|null  $targetChannelIds
     * @return list<int>
     */
    private function enqueueForArticleSelection(
        int|Article $article,
        string $action,
        array $aiWorkspaceGuard,
        ?array $targetChannelIds = null,
        bool $throwOnFailure = false,
        ?array $workflowFence = null,
    ): array {
        try {
            $articleModel = $article instanceof Article
                ? $article
                : Article::query()->whereKey($article)->first();

            if (! $articleModel || ! $articleModel->task_id) {
                return [];
            }

            $articleModel->load('task.distributionChannels');
            $workflowFence ??= app(ArticlePublicationEligibilityService::class)->fence($articleModel);
            if ($action !== 'delete' && ! $this->workflowFenceMatches($articleModel, $workflowFence)) {
                throw new \RuntimeException('distribution_workflow_superseded');
            }
            $publishScope = (string) ($articleModel->task?->publish_scope ?? 'local_and_distribution');
            if ($publishScope === 'local_only') {
                return [];
            }
            $canDistribute = $articleModel->status === 'published'
                || ($publishScope === 'distribution_only' && in_array((string) $articleModel->status, ['private', 'published'], true));
            if (! $canDistribute) {
                return [];
            }

            $exactTargets = $targetChannelIds !== null;
            if ($exactTargets) {
                $requestedIds = collect($targetChannelIds)
                    ->map(static fn ($id): int => (int) $id)
                    ->filter(static fn (int $id): bool => $id > 0)
                    ->unique()
                    ->values();
                if ($requestedIds->isEmpty()) {
                    return [];
                }
                $availableTargets = DistributionChannel::query()
                    ->whereIn('id', $requestedIds->all())
                    ->where('status', DistributionChannel::STATUS_ACTIVE)
                    ->get()
                    ->keyBy('id');
                if ($availableTargets->count() !== $requestedIds->count()) {
                    throw new \RuntimeException('部分已审批的分发站点当前不可用。');
                }
                $channels = $requestedIds
                    ->map(static fn (int $id): DistributionChannel => $availableTargets->get($id))
                    ->values();
                $attachedHostedIds = $articleModel->task?->distributionChannels
                    ?->filter(static fn (DistributionChannel $channel): bool => $channel->isHostedSite())
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all() ?? [];
                $unconfiguredHostedTarget = $channels->first(
                    static fn (DistributionChannel $channel): bool => $channel->isHostedSite()
                        && ! in_array((int) $channel->id, $attachedHostedIds, true),
                );
                if ($unconfiguredHostedTarget instanceof DistributionChannel) {
                    throw new \RuntimeException('托管站点需要先在任务设置中完成关联。');
                }
            } else {
                $channels = $articleModel->task?->distributionChannels
                    ?->where('status', DistributionChannel::STATUS_ACTIVE) ?? new Collection;
            }

            if ($channels->isEmpty()) {
                throw new \RuntimeException('distribution_channels_unavailable');
            }

            if ($action !== 'delete' && ! $this->isDistributableSnapshot($articleModel)) {
                throw new \RuntimeException('文章当前状态不允许分发');
            }
            $qualityCheck = $action !== 'delete'
                ? $this->publicationQualityGate->check($articleModel, 'distribution_enqueue')
                : null;

            $hostedChannels = $channels
                ->filter(static fn (DistributionChannel $channel): bool => $channel->isHostedSite())
                ->values();
            $externalChannels = $channels
                ->reject(static fn (DistributionChannel $channel): bool => $channel->isHostedSite())
                ->values();
            $channels = $exactTargets
                ? $externalChannels
                : $this->channelSelector->selectChannelsForArticle($articleModel, $externalChannels, $action);

            if ($action === 'publish' && $hostedChannels->isNotEmpty()) {
                $existingAssignment = HostedSiteArticleAssignment::query()
                    ->where('article_id', (int) $articleModel->id)
                    ->first();
                if ($existingAssignment?->status === HostedSiteArticleAssignment::STATUS_WITHDRAWN) {
                    $this->hostedSiteLifecycle->restorePublication($articleModel, $workflowFence);
                } else {
                    $allocationRequest = $this->hostedAllocationRequests->request($articleModel, $workflowFence);
                    $this->hostedSiteAllocator->allocate($allocationRequest);
                }
            } elseif ($action !== 'publish' && $hostedChannels->isNotEmpty()) {
                $assignment = HostedSiteArticleAssignment::query()
                    ->with('profile.channel')
                    ->where('article_id', (int) $articleModel->id)
                    ->first();
                $assignedChannel = $assignment?->profile?->channel;
                if ($assignedChannel instanceof DistributionChannel
                    && (string) $assignedChannel->status === DistributionChannel::STATUS_ACTIVE) {
                    $channels->push($assignedChannel);
                }
            }

            if ($channels->isEmpty()) {
                return [];
            }

            $payload = $action === 'delete'
                ? $this->payloadBuilder->build($articleModel)
                : $this->buildVerifiedPayload($articleModel, 'distribution_enqueue');
            if ($aiWorkspaceGuard !== []) {
                $expectedDigest = (string) ($aiWorkspaceGuard['expected_payload_digest'] ?? '');
                if ($expectedDigest === '' || ! hash_equals($expectedDigest, AiPayloadDigest::make($payload))) {
                    throw new \RuntimeException('AI 工作台分发载荷在审批后已变化。');
                }
            }
            $payloadHash = AiPayloadDigest::make($payload);

            $queuedDistributionIds = [];
            foreach ($channels as $channel) {
                $distributionId = DB::transaction(function () use ($channel, $articleModel, $action, $payload, $payloadHash, $aiWorkspaceGuard, $qualityCheck, $exactTargets, $workflowFence): ?int {
                    $lockedChannel = DistributionChannel::query()
                        ->whereKey((int) $channel->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                        return null;
                    }

                    $lockedArticle = $this->lockWorkflowArticle((int) $articleModel->id);
                    if (! $lockedArticle
                        || ! $lockedArticle->task_id
                        || (int) $lockedArticle->task_id !== (int) $articleModel->task_id) {
                        return null;
                    }
                    $lockedTask = Task::query()
                        ->whereKey((int) $lockedArticle->task_id)
                        ->lockForUpdate()
                        ->first();
                    if (! $lockedTask || (string) $lockedTask->publish_scope === 'local_only') {
                        return null;
                    }
                    $lockedArticle->setRelation('task', $lockedTask);
                    if ($action !== 'delete' && ! $this->workflowFenceMatches($lockedArticle, $workflowFence)) {
                        throw new \RuntimeException('distribution_workflow_superseded');
                    }
                    $canDistribute = (string) $lockedArticle->status === 'published'
                        || ((string) $lockedTask->publish_scope === 'distribution_only'
                            && in_array((string) $lockedArticle->status, ['private', 'published'], true));
                    if (! $canDistribute || ($action !== 'delete' && ! $this->isDistributableSnapshot($lockedArticle))) {
                        return null;
                    }
                    if ((! $exactTargets || $lockedChannel->isHostedSite())
                        && ! DB::table('task_distribution_channels')
                            ->where('task_id', (int) $lockedTask->id)
                            ->where('distribution_channel_id', (int) $lockedChannel->id)
                            ->exists()) {
                        return null;
                    }

                    $distributions = ArticleDistribution::query()
                        ->where('article_id', (int) $articleModel->id)
                        ->where('distribution_channel_id', (int) $lockedChannel->id)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $distribution = $distributions->firstWhere('action', $action);
                    $unknownDistribution = $distributions->firstWhere('status', 'outcome_unknown');
                    if ($unknownDistribution instanceof ArticleDistribution) {
                        $this->log('warning', '远端分发结果需要对账，已阻止重复入队', $lockedChannel->id, $unknownDistribution->id, $articleModel->id, [
                            'event' => 'distribution.outcome_unknown_requeue_blocked',
                        ]);

                        return null;
                    }
                    if ($lockedChannel->isWordPressRest()) {
                        if ($distributions->contains('status', 'sending')) {
                            return null;
                        }
                    }
                    if ($distribution && (string) $distribution->status === 'sending') {
                        return null;
                    }
                    $remoteMeta = is_array($distribution?->remote_meta) ? $distribution->remote_meta : [];
                    if ($distribution && ($distribution->status === 'queued' || ($distribution->status === 'synced' && ! $lockedChannel->isWordPressRest()))
                        && ! data_get($remoteMeta, 'remote_deleted')
                        && (int) data_get($remoteMeta, 'workflow_fence.workflow_version', 0) === (int) $lockedArticle->workflow_version
                        && ($distribution->status === 'synced' || (
                            (int) data_get($remoteMeta, 'workflow_fence.automation_version', 0) === (int) ($lockedArticle->task?->automation_version ?? 0)
                            && ($qualityCheck === null ? ! isset($remoteMeta['ai_quality_guard']) : $this->qualityGuardMatches($distribution, $qualityCheck))
                        ))
                        && hash_equals((string) $distribution->payload_hash, $payloadHash)) {
                        if ($distribution->status === 'queued' && ! data_get($remoteMeta, 'queue_dispatched_at')) {
                            $this->dispatchDeliveryAfterCommit((int) $distribution->id);
                        }

                        return null;
                    }
                    $remoteMeta['workflow_fence'] = $workflowFence;
                    unset($remoteMeta['queue_dispatched_at']);
                    $wordpressPostIds = $lockedChannel->isWordPressRest()
                        ? $distributions
                            ->map(static fn (ArticleDistribution $candidate): ?int => $candidate->wordpressPostId())
                            ->filter(static fn (?int $postId): bool => is_int($postId) && $postId > 0)
                            ->unique()
                            ->sort()
                            ->values()
                        : collect();
                    if ($wordpressPostIds->count() > 1) {
                        $distribution ??= new ArticleDistribution([
                            'article_id' => (int) $articleModel->id,
                            'distribution_channel_id' => (int) $lockedChannel->id,
                            'action' => $action,
                            'idempotency_key' => $this->idempotencyKey(
                                (int) $articleModel->id,
                                (int) $lockedChannel->id,
                                $action,
                                $payloadHash,
                            ),
                            'payload_hash' => $payloadHash,
                        ]);
                        $remoteMeta['wordpress_identity_conflict'] = [
                            'known_post_ids' => $wordpressPostIds->all(),
                            'detected_at' => now()->toIso8601String(),
                        ];
                        $distribution->forceFill([
                            'status' => 'outcome_unknown',
                            'next_retry_at' => null,
                            'last_error_message' => '检测到多个 WordPress 远端文章 ID，需要人工对账。',
                            'remote_meta' => $remoteMeta,
                        ])->save();
                        $this->log('error', '检测到多个 WordPress 远端文章 ID，已停止分发', $lockedChannel->id, $distribution->id, $articleModel->id, [
                            'event' => 'distribution.remote_identity_conflict',
                            'known_post_ids' => $wordpressPostIds->all(),
                        ]);

                        return null;
                    }
                    $wordpressIdentitySource = $lockedChannel->isWordPressRest()
                        ? $distributions->first(static fn (ArticleDistribution $candidate): bool => (bool) $candidate->wordpressPostId())
                        : null;
                    if ($wordpressIdentitySource instanceof ArticleDistribution
                        && ! $distribution?->wordpressPostId()) {
                        $remotePostId = $wordpressIdentitySource->wordpressPostId();
                        $distribution ??= new ArticleDistribution([
                            'article_id' => (int) $articleModel->id,
                            'distribution_channel_id' => (int) $lockedChannel->id,
                            'action' => $action,
                        ]);
                        $distribution->forceFill([
                            'remote_id' => (string) $remotePostId,
                            'remote_url' => $wordpressIdentitySource->remote_url,
                        ]);
                        $remoteMeta['wordpress_post_id'] = $remotePostId;
                        $remoteMeta['wordpress_identity_source_distribution_id'] = (int) $wordpressIdentitySource->id;
                    }
                    $wordpressDeliveryFingerprint = $lockedChannel->isWordPressRest()
                        ? $this->wordpressDeliveryFingerprint($payload, $lockedChannel)
                        : null;
                    if ($distribution
                        && $wordpressDeliveryFingerprint !== null
                        && (string) $distribution->status === 'synced'
                        && $distribution->wordpressPostId()
                        && hash_equals(
                            (string) ($remoteMeta['wordpress_delivery_fingerprint'] ?? ''),
                            $wordpressDeliveryFingerprint,
                        )) {
                        $this->log('info', 'WordPress 文章内容与渠道配置未变化，已跳过重复分发', $lockedChannel->id, $distribution->id, $articleModel->id, [
                            'event' => 'distribution.unchanged_skipped',
                        ]);

                        return null;
                    }
                    $distribution ??= new ArticleDistribution([
                        'article_id' => (int) $articleModel->id,
                        'distribution_channel_id' => (int) $lockedChannel->id,
                        'action' => $action,
                    ]);
                    if ($wordpressDeliveryFingerprint !== null) {
                        $remoteMeta['wordpress_delivery_fingerprint'] = $wordpressDeliveryFingerprint;
                    }
                    if ($qualityCheck !== null) {
                        $remoteMeta['ai_quality_guard'] = $this->qualityGuardAudit($qualityCheck);
                    } else {
                        unset($remoteMeta['ai_quality_guard']);
                    }
                    $remoteMeta['distribution_payload'] = $payload;
                    if ($aiWorkspaceGuard !== []) {
                        $approvedRevision = (string) data_get(
                            $aiWorkspaceGuard,
                            'approved_channel_revisions.'.(int) $lockedChannel->id,
                            '',
                        );
                        if ($approvedRevision === '') {
                            throw new \RuntimeException('AI 工作台分发缺少已审批的目标版本。');
                        }
                        $remoteMeta['ai_workspace_guard'] = array_replace($aiWorkspaceGuard, [
                            'channel_revision' => $approvedRevision,
                        ]);
                        $remoteMeta['ai_workspace_payload'] = $payload;
                    }
                    $distribution->forceFill([
                        'status' => 'queued',
                        'next_retry_at' => now(),
                        'payload_hash' => $payloadHash,
                        'idempotency_key' => $this->idempotencyKey(
                            (int) $articleModel->id,
                            (int) $lockedChannel->id,
                            $action,
                            $payloadHash,
                            (int) ($remoteMeta['remote_copy_version'] ?? 0),
                        ),
                        'remote_meta' => $remoteMeta,
                    ])->save();

                    $this->log('info', '文章已进入分发队列', $lockedChannel->id, $distribution->id, $articleModel->id, [
                        'event' => 'distribution.queued',
                        'strategy' => (string) ($lockedTask->distribution_strategy ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
                        'ai_quality_guard' => $qualityCheck !== null ? $this->qualityGuardAudit($qualityCheck) : null,
                    ]);
                    $this->dispatchDeliveryAfterCommit((int) $distribution->id);

                    return (int) $distribution->id;
                });
                if (is_int($distributionId)) {
                    $queuedDistributionIds[] = $distributionId;
                }
            }

            return $queuedDistributionIds;
        } catch (Throwable $e) {
            $this->log('error', '文章分发入队失败：'.DistributionErrorSanitizer::from($e), null, null, $article instanceof Article ? (int) $article->id : $article, [
                'event' => 'distribution.enqueue_failed',
            ]);
            if ($aiWorkspaceGuard !== [] || $throwOnFailure) {
                throw $e;
            }

            return [];
        }
    }

    /** @return array<string,mixed> */
    private function qualityGuardAudit(ArticleAiQualityCheck $check): array
    {
        $coverage = is_array($check->coverage_meta) ? $check->coverage_meta : [];
        unset($coverage['sampled_content']);
        if (is_array($coverage['sampled_ranges'] ?? null)) {
            $coverage['sampled_ranges'] = array_values(array_map(static function (array $range): array {
                unset($range['content']);

                return $range;
            }, array_values(array_filter($coverage['sampled_ranges'], 'is_array'))));
        }

        return [
            'check_id' => (int) $check->id,
            'input_fingerprint' => (string) $check->input_fingerprint,
            'retrieval_basis_hash' => (string) $check->retrieval_basis_hash,
            'requested_retrieval_mode' => (string) $check->requested_retrieval_mode,
            'effective_retrieval_mode' => (string) $check->effective_retrieval_mode,
            'retrieval_strategy_version' => (string) $check->retrieval_strategy_version,
            'rollout_epoch' => max(1, (int) data_get($check->execution_meta, 'retrieval_basis.rollout.epoch', 1)),
            'article_content_hash' => (string) $check->article_content_hash,
            'decision' => (string) $check->decision,
            'score' => $check->score === null ? null : (int) $check->score,
            'is_overridden' => (bool) $check->is_overridden,
            'inspection_scope' => (string) ($check->inspection_scope ?: 'full'),
            'fallback_trigger_code' => $check->fallback_trigger_code,
            'coverage' => $coverage,
            'algorithm_version' => (string) $check->algorithm_version,
            'scoring_version' => (string) $check->scoring_version,
        ];
    }

    private function qualityGuardMatches(
        ArticleDistribution $distribution,
        ?ArticleAiQualityCheck $currentCheck,
    ): bool {
        $guard = data_get($distribution->remote_meta, 'ai_quality_guard');
        if (! is_array($guard)) {
            return ! $currentCheck instanceof ArticleAiQualityCheck;
        }

        return $currentCheck instanceof ArticleAiQualityCheck && (
            (int) ($guard['check_id'] ?? 0) === (int) $currentCheck->id
            && hash_equals(
                (string) ($guard['input_fingerprint'] ?? ''),
                (string) $currentCheck->input_fingerprint,
            )
            && hash_equals(
                (string) ($guard['retrieval_basis_hash'] ?? ''),
                (string) $currentCheck->retrieval_basis_hash,
            )
        );
    }

    /**
     * Capture one immutable snapshot for distributions created before payload snapshots were introduced.
     *
     * @param  array<string,mixed>  $payload
     */
    private function persistPayloadSnapshot(
        ArticleDistribution $distribution,
        array $payload,
        ?ArticleAiQualityCheck $qualityCheck,
        bool $replace = false,
    ): ArticleDistribution {
        return DB::transaction(function () use ($distribution, $payload, $qualityCheck, $replace): ArticleDistribution {
            $locked = ArticleDistribution::query()
                ->whereKey((int) $distribution->id)
                ->where('status', 'queued')
                ->lockForUpdate()
                ->firstOrFail();
            $remoteMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
            $existing = data_get($remoteMeta, 'distribution_payload');
            if ($replace || ! is_array($existing)) {
                $remoteMeta['distribution_payload'] = $payload;
                if ($qualityCheck instanceof ArticleAiQualityCheck) {
                    $remoteMeta['ai_quality_guard'] = $this->qualityGuardAudit($qualityCheck);
                }
                $payloadHash = $this->payloadHash($payload);
                $locked->forceFill([
                    'payload_hash' => $payloadHash,
                    'idempotency_key' => $this->idempotencyKey(
                        (int) $locked->article_id,
                        (int) $locked->distribution_channel_id,
                        (string) $locked->action,
                        $payloadHash,
                    ),
                    'remote_meta' => $remoteMeta,
                ])->save();
            }

            return $locked->fresh();
        });
    }

    /** @param array<string,mixed> $payload */
    private function payloadHash(array $payload): string
    {
        return AiPayloadDigest::make($payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function healthCheck(DistributionChannel $channel): array
    {
        return $this->channelOperationLeaseService->run(
            $channel,
            'health_check',
            fn (DistributionChannel $lockedChannel): array => $this->publisherManager
                ->forChannel($lockedChannel)
                ->health($lockedChannel),
        );
    }

    public function process(ArticleDistribution $distribution): bool
    {
        $currentDistribution = ArticleDistribution::query()
            ->with('article')
            ->whereKey((int) $distribution->id)
            ->first();
        if (! $currentDistribution) {
            return false;
        }
        if (! $currentDistribution->article) {
            ArticleDistribution::query()
                ->whereKey((int) $currentDistribution->id)
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => '关联文章或任务已删除，分发已取消。',
                    'updated_at' => now(),
                ]);

            return false;
        }
        $article = $currentDistribution->article;
        if ($currentDistribution->action !== 'delete'
            && ! $this->workflowFenceMatches($article, data_get($currentDistribution->remote_meta, 'workflow_fence'))) {
            ArticleDistribution::query()->whereKey($currentDistribution->id)->where('status', 'queued')->update([
                'status' => 'cancelled', 'next_retry_at' => null, 'last_error_message' => 'distribution_workflow_superseded',
            ]);

            return false;
        }
        if ((string) $currentDistribution->action !== 'delete' && ! $this->isDistributableSnapshot($article)) {
            throw new \RuntimeException('文章当前状态不允许分发');
        }

        if ((string) $currentDistribution->action !== 'delete'
            && (app(ArticleAiQualityPolicyResolver::class)->resolve($article)['required'] ?? false)
            && is_array(data_get($currentDistribution->remote_meta, 'ai_quality_guard'))
            && ! $this->qualityGuardMatches($currentDistribution, $article->latestAiQualityCheck)) {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_basis_changed',
                '分发绑定的 AI 质检依据已变化，请重新入队。',
                $article->latestAiQualityCheck,
            );
        }

        $qualityCheck = (string) $currentDistribution->action !== 'delete'
            ? $this->publicationQualityGate->check($article, 'distribution_send')
            : null;
        $immutablePayload = data_get($currentDistribution->remote_meta, 'distribution_payload');
        if (! is_array($immutablePayload)) {
            $immutablePayload = data_get($currentDistribution->remote_meta, 'ai_workspace_payload');
        }
        if (! is_array($immutablePayload)) {
            $immutablePayload = (string) $currentDistribution->action === 'delete'
                ? []
                : $this->buildVerifiedPayload($article, 'distribution_send');
            $currentDistribution = $this->persistPayloadSnapshot(
                $currentDistribution,
                $immutablePayload,
                $qualityCheck,
            );
        }
        if ($qualityCheck !== null && ! $this->qualityGuardMatches($currentDistribution, $qualityCheck)) {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_basis_changed',
                '分发绑定的 AI 质检依据已变化，请重新入队。',
                $qualityCheck,
            );
        }
        $payload = $immutablePayload;
        $payloadHash = $this->payloadHash($payload);
        if (! hash_equals((string) $currentDistribution->payload_hash, $payloadHash)
            && ! hash_equals((string) $currentDistribution->payload_hash, hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''))) {
            throw new \RuntimeException('分发载荷摘要校验失败。');
        }
        if ((string) $currentDistribution->action === 'update') {
            $payload['event'] = 'article.update';
        }

        $distribution = $this->claimForProcessing((int) $currentDistribution->id);
        if (! $distribution) {
            return false;
        }
        $distribution->loadMissing(['article', 'channel']);
        $channel = $distribution->channel;
        if (! $distribution->article || ! $channel) {
            return false;
        }

        return $this->channelOperationLeaseService->run(
            $channel,
            'article_'.(string) $distribution->action,
            function (DistributionChannel $lockedChannel) use ($distribution, $payload, $article): bool {
                $acknowledgedResponse = null;
                try {
                    $response = DB::transaction(function () use ($distribution, $payload, $lockedChannel, &$acknowledgedResponse): ?array {
                        $lockedChannel = DistributionChannel::query()->whereKey($lockedChannel->id)->lockForUpdate()->firstOrFail();
                        if ($lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                            throw new \RuntimeException('distribution_channel_unavailable');
                        }
                        if ($lockedChannel->isHostedSite()) {
                            HostedSiteProfile::query()->where('distribution_channel_id', $lockedChannel->id)->lockForUpdate()->firstOrFail();
                        }
                        $committedEpoch = $this->aiQualityRolloutPolicy->acquireDistributionLeaseEpoch();
                        $lockedArticle = $this->lockWorkflowArticle((int) $distribution->article_id);
                        if (! $lockedArticle instanceof Article) {
                            return null;
                        }
                        $locked = ArticleDistribution::query()
                            ->whereKey((int) $distribution->id)
                            ->where('article_id', (int) $lockedArticle->id)
                            ->lockForUpdate()
                            ->first();
                        if (! $locked || (string) $locked->status !== 'sending') {
                            return null;
                        }
                        $locked->setRelation('article', $lockedArticle);

                        if ((string) $locked->action !== 'delete') {
                            if (! $this->workflowFenceMatches($lockedArticle, data_get($locked->remote_meta, 'workflow_fence'))
                                || ! $this->isDistributableSnapshot($lockedArticle)
                                || ! $this->currentChannelAllowsDelivery($lockedArticle, $locked)) {
                                $locked->update([
                                    'status' => 'cancelled', 'next_retry_at' => null, 'last_error_message' => 'distribution_workflow_superseded',
                                    'remote_meta' => array_replace((array) $locked->remote_meta, ['transport_not_started' => true]),
                                ]);

                                return null;
                            }
                            $guard = data_get($locked->remote_meta, 'ai_quality_guard');
                            $qualityCheck = $this->publicationQualityGate->check($lockedArticle, 'distribution_send_fenced');
                            // A disabled policy returns no check; retained guards remain audit history.
                            $guardEpochMatches = $qualityCheck === null || ! is_array($guard)
                                || (int) ($guard['rollout_epoch'] ?? 0) === $committedEpoch;
                            if ($qualityCheck !== null && (! $guardEpochMatches || ! $this->qualityGuardMatches($locked, $qualityCheck))) {
                                throw new ArticleAiQualityGateException(
                                    'article_ai_quality_basis_changed',
                                    '分发绑定的 AI 质检召回版本已变化，请重新入队。',
                                    $qualityCheck,
                                );
                            }
                        }

                        $workspaceGuard = data_get($locked->remote_meta, 'ai_workspace_guard');
                        $dispatchChannel = $lockedChannel;
                        if (is_array($workspaceGuard)) {
                            $approvedRevision = (string) ($workspaceGuard['channel_revision'] ?? '');
                            if ($approvedRevision === '' || ! hash_equals($approvedRevision, $this->channelRevision($lockedChannel))) {
                                throw new \RuntimeException('AI 工作台分发目标在审批后已变化。');
                            }
                            $dispatchChannel = $this->aiWorkspaceDispatchGuard->authorizeDistributionDispatch($locked);
                            $locked->refresh();
                            $locked->setRelation('channel', $dispatchChannel);
                            $locked->loadMissing('article');
                        }

                        $publisher = $this->publisherManager->forChannel($dispatchChannel);
                        try {
                            $response = match ((string) $locked->action) {
                                'update' => $publisher->update($locked, $payload),
                                'delete' => $publisher->delete($locked),
                                default => $publisher->publish($locked, $payload),
                            };
                            $acknowledgedResponse = $response;
                        } catch (Throwable $exception) {
                            if ($exception instanceof DistributionAcknowledgedResponseException) {
                                throw $exception;
                            }
                            $locked->refresh();
                            if ((string) $locked->status !== 'sending') {
                                return ['saved' => false, 'deferred_exception' => $exception];
                            }

                            throw $exception;
                        }
                        $locked->refresh();
                        if ((string) $locked->status !== 'sending') {
                            return ['saved' => false];
                        }
                        $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];

                        $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
                        $knownWordPressPostId = $lockedChannel->isWordPressRest()
                            && (string) $locked->action !== 'delete'
                            ? $locked->wordpressPostId()
                            : null;
                        $returnedWordPressPostId = $this->wordpressPostIdFromResponse($response);
                        if ($knownWordPressPostId
                            && $returnedWordPressPostId
                            && $knownWordPressPostId !== $returnedWordPressPostId) {
                            $existingMeta['wordpress_identity_conflict'] = [
                                'expected_post_id' => $knownWordPressPostId,
                                'returned_post_id' => $returnedWordPressPostId,
                            ];
                            $locked->forceFill([
                                'status' => 'outcome_unknown',
                                'next_retry_at' => null,
                                'last_error_message' => 'WordPress 返回的文章 ID 与已知远端身份不一致，需要人工对账。',
                                'remote_meta' => $existingMeta,
                            ])->save();

                            return [
                                'saved' => false,
                                'identity_conflict' => true,
                                'expected_post_id' => $knownWordPressPostId,
                                'returned_post_id' => $returnedWordPressPostId,
                            ];
                        }
                        if ((string) $locked->action !== 'delete') {
                            unset($existingMeta['wordpress_remote_deleted_at'], $existingMeta['remote_deleted']);
                        }
                        $locked->forceFill([
                            'status' => 'synced',
                            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $locked->remote_id,
                            'remote_url' => (string) $locked->action === 'delete'
                                ? null
                                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $locked->remote_url),
                            'remote_meta' => array_replace($existingMeta, $responseMeta),
                            'last_error_message' => null,
                        ])->save();

                        if ($locked->action === 'delete') {
                            $this->markRemoteCopyDeleted($locked);
                        }

                        return ['saved' => true, 'response' => $response];
                    });
                } catch (Throwable $exception) {
                    if ($exception instanceof DistributionAcknowledgedResponseException) {
                        $acknowledgedResponse = $exception->response;
                    }
                    if ($acknowledgedResponse !== null) {
                        $this->preserveAcknowledgedResponse($distribution, $acknowledgedResponse);
                    }
                    throw $exception;
                }
                if (($response['deferred_exception'] ?? null) instanceof Throwable) {
                    throw $response['deferred_exception'];
                }
                if ((bool) ($response['identity_conflict'] ?? false)) {
                    $this->log(
                        'error',
                        'WordPress 返回的文章 ID 与已知远端身份不一致，已停止分发',
                        $lockedChannel->id,
                        $distribution->id,
                        $article->id,
                        [
                            'event' => 'distribution.remote_identity_conflict',
                            'expected_post_id' => $response['expected_post_id'] ?? null,
                            'returned_post_id' => $response['returned_post_id'] ?? null,
                        ],
                    );

                    return false;
                }
                if (! is_array($response) || ! (bool) ($response['saved'] ?? false)) {
                    $this->log(
                        'warning',
                        '外部分发返回结果时本地任务已停止，保留待人工核对状态',
                        $lockedChannel->id,
                        $distribution->id,
                        $article->id,
                        ['event' => 'distribution.result_after_task_deletion'],
                    );

                    return false;
                }

                $responsePayload = is_array($response['response'] ?? null) ? $response['response'] : [];
                $this->log('info', '文章分发成功', $lockedChannel->id, $distribution->id, $article->id, $responsePayload);

                return true;
            },
        );
    }

    public function reconcileUnknownOutcome(ArticleDistribution $distribution): bool
    {
        $distribution = ArticleDistribution::query()
            ->with(['article', 'channel'])
            ->whereKey((int) $distribution->id)
            ->first();
        if (! $distribution instanceof ArticleDistribution
            || ! $distribution->article instanceof Article
            || ! $distribution->channel instanceof DistributionChannel
            || ! $distribution->channel->isWordPressRest()
            || ! in_array((string) $distribution->status, ['sending', 'outcome_unknown'], true)) {
            return false;
        }
        $payload = data_get($distribution->remote_meta, 'distribution_payload');
        if (! is_array($payload)) {
            $payload = data_get($distribution->remote_meta, 'ai_workspace_payload');
        }
        $publisher = $this->publisherManager->forChannel($distribution->channel);
        if (! is_array($payload) || ! $publisher instanceof WordPressRestPublisher) {
            return false;
        }
        $response = $publisher->reconcilePublication($distribution, $payload);
        if (! is_array($response)) {
            return false;
        }

        $result = DB::transaction(function () use ($distribution, $response): array {
            $channel = DistributionChannel::query()
                ->whereKey((int) $distribution->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            if (! $channel?->isWordPressRest()) {
                return ['updated' => false];
            }
            $distributions = ArticleDistribution::query()
                ->where('article_id', (int) $distribution->article_id)
                ->where('distribution_channel_id', (int) $distribution->distribution_channel_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $locked = $distributions->firstWhere('id', (int) $distribution->id);
            if (! $locked instanceof ArticleDistribution) {
                return ['updated' => false];
            }
            if (! in_array((string) $locked->status, ['sending', 'outcome_unknown'], true)) {
                return ['updated' => (string) $locked->status === 'synced'];
            }
            $knownWordPressPostIds = $distributions
                ->map(static fn (ArticleDistribution $candidate): ?int => $candidate->wordpressPostId())
                ->filter(static fn (?int $postId): bool => is_int($postId) && $postId > 0)
                ->unique()
                ->sort()
                ->values();
            $knownWordPressPostId = $knownWordPressPostIds->count() === 1
                ? (int) $knownWordPressPostIds->first()
                : null;
            $returnedWordPressPostId = $this->wordpressPostIdFromResponse($response);
            if ($knownWordPressPostIds->count() > 1
                || ($knownWordPressPostId
                    && $returnedWordPressPostId
                    && $knownWordPressPostId !== $returnedWordPressPostId)) {
                $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
                $identityConflict = $knownWordPressPostIds->count() > 1
                    ? ['known_post_ids' => $knownWordPressPostIds->all()]
                    : [
                        'expected_post_id' => $knownWordPressPostId,
                        'returned_post_id' => $returnedWordPressPostId,
                    ];
                $existingMeta['wordpress_identity_conflict'] = $identityConflict;
                $locked->forceFill([
                    'status' => 'outcome_unknown',
                    'next_retry_at' => null,
                    'last_error_message' => $knownWordPressPostIds->count() > 1
                        ? '检测到多个 WordPress 远端文章 ID，需要人工对账。'
                        : 'WordPress 返回的文章 ID 与已知远端身份不一致，需要人工对账。',
                    'remote_meta' => $existingMeta,
                ])->save();

                return [
                    'updated' => false,
                    'identity_conflict' => true,
                    'expected_post_id' => $knownWordPressPostId,
                    'returned_post_id' => $returnedWordPressPostId,
                    'known_post_ids' => $knownWordPressPostIds->all(),
                ];
            }
            if (! $returnedWordPressPostId) {
                return ['updated' => false];
            }
            $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
            $locked->forceFill([
                'status' => 'synced',
                'remote_id' => (string) $returnedWordPressPostId,
                'remote_url' => (string) ($response['remote_url'] ?? ''),
                'remote_meta' => array_replace($existingMeta, (array) ($response['remote_meta'] ?? [])),
                'last_error_message' => null,
                'next_retry_at' => null,
            ])->save();

            return ['updated' => true];
        });
        if ((bool) ($result['identity_conflict'] ?? false)) {
            $this->log(
                'error',
                'WordPress slug 对账结果与已知远端身份冲突，已停止对账',
                (int) $distribution->distribution_channel_id,
                (int) $distribution->id,
                (int) $distribution->article_id,
                [
                    'event' => 'distribution.remote_identity_conflict',
                    'expected_post_id' => $result['expected_post_id'] ?? null,
                    'returned_post_id' => $result['returned_post_id'] ?? null,
                    'known_post_ids' => $result['known_post_ids'] ?? [],
                ],
            );

            return false;
        }
        $updated = (bool) ($result['updated'] ?? false);
        if ($updated) {
            $this->log(
                'warning',
                'WordPress 分发结果已通过文章 slug 完成对账',
                (int) $distribution->distribution_channel_id,
                (int) $distribution->id,
                (int) $distribution->article_id,
                ['event' => 'distribution.commit_reconciled'],
            );
        }

        return $updated;
    }

    private function channelRevision(DistributionChannel $channel): string
    {
        return AiWorkspaceChannelRevision::make($channel);
    }

    public function claimForProcessing(int $distributionId): ?ArticleDistribution
    {
        $candidate = ArticleDistribution::query()
            ->select(['id', 'article_id', 'distribution_channel_id'])
            ->whereKey($distributionId)
            ->first();
        if (! $candidate) {
            return null;
        }
        $taskId = (int) (DB::table('articles')
            ->where('id', (int) $candidate->article_id)
            ->value('task_id') ?? 0);

        return DB::transaction(function () use ($candidate, $taskId): ?ArticleDistribution {
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $article = $this->lockWorkflowArticle((int) $candidate->article_id);
            $task = $article?->task;
            $distribution = ArticleDistribution::query()
                ->whereKey((int) $candidate->id)
                ->where('distribution_channel_id', (int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            if (! $distribution || (string) $distribution->status !== 'queued') {
                return null;
            }

            if (($taskId > 0 && ! $task) || ! $article) {
                $distribution->forceFill([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => '关联文章或任务已删除，分发已取消。',
                ])->save();

                return null;
            }

            if (! $channel
                || (string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
                $distribution->forceFill([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => __('admin.distribution.delete.channel_unavailable_error'),
                ])->save();

                return null;
            }
            if ($channel->isHostedSite() && ! config('geoflow.hosted_sites.enabled', false)) {
                $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $distribution->forceFill([
                    'status' => 'queued',
                    'next_retry_at' => null,
                    'last_error_message' => 'Hosted sites are temporarily disabled.',
                    'remote_meta' => array_replace($remoteMeta, ['hosted_feature_paused' => true]),
                ])->save();

                return null;
            }

            if (data_get($distribution->remote_meta, 'safe_retry_at') && $distribution->next_retry_at?->isFuture()) {
                return null;
            }
            $distribution->forceFill([
                'status' => 'sending',
                'attempt_count' => (int) $distribution->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_message' => null,
                'remote_meta' => Arr::except((array) $distribution->remote_meta, ['transport_not_started', 'safe_retry_at']),
            ])->save();

            return $distribution;
        });
    }

    public function updateRemoteArticle(ArticleDistribution $distribution): ArticleDistribution
    {
        return $this->sendImmediateAction($distribution, 'update');
    }

    public function deleteRemoteArticle(ArticleDistribution $distribution): ArticleDistribution
    {
        return $this->sendImmediateAction($distribution, 'delete');
    }

    public function enqueueChannelContentRefresh(DistributionChannel $channel): int
    {
        $channelId = (int) $channel->id;
        $count = 0;
        ArticleDistribution::query()
            ->where('distribution_channel_id', $channelId)
            ->where('action', '!=', 'delete')
            ->where('status', '!=', 'sending')
            ->whereHas('article', function ($query): void {
                $query->whereIn('status', ['published', 'private']);
            })
            ->orderBy('id')
            ->chunkById(100, function ($candidates) use (&$count, $channelId): void {
                foreach ($candidates as $candidate) {
                    if (! $candidate instanceof ArticleDistribution) {
                        continue;
                    }

                    $snapshotArticle = Article::query()->whereKey((int) $candidate->article_id)->first();
                    if (! $snapshotArticle instanceof Article) {
                        continue;
                    }
                    try {
                        $qualityCheck = $this->publicationQualityGate->check($snapshotArticle, 'distribution_enqueue');
                        $payload = $this->buildVerifiedPayload($snapshotArticle, 'distribution_enqueue');
                    } catch (Throwable) {
                        continue;
                    }
                    $payloadHash = $this->payloadHash($payload);
                    $articleUpdatedAt = $snapshotArticle->updated_at?->toISOString();
                    $workflowFence = app(ArticlePublicationEligibilityService::class)->fence($snapshotArticle, 'manual');

                    $queued = DB::transaction(function () use (
                        $candidate,
                        $channelId,
                        $payload,
                        $payloadHash,
                        $articleUpdatedAt,
                        $qualityCheck,
                        $workflowFence,
                    ): bool {
                        $lockedChannel = DistributionChannel::query()
                            ->whereKey($channelId)
                            ->lockForUpdate()
                            ->first();
                        if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                            return false;
                        }
                        $article = $this->lockWorkflowArticle((int) $candidate->article_id);
                        if (! $article
                            || ! in_array((string) $article->status, ['published', 'private'], true)
                            || $article->updated_at?->toISOString() !== $articleUpdatedAt
                            || ! $this->workflowFenceMatches($article, $workflowFence)) {
                            return false;
                        }
                        $task = $article?->task;
                        if ($article->task_id && (! $task || (string) $task->publish_scope === 'local_only')) {
                            return false;
                        }
                        $distribution = ArticleDistribution::query()
                            ->whereKey((int) $candidate->id)
                            ->where('article_id', (int) $article->id)
                            ->where('distribution_channel_id', $channelId)
                            ->where('action', '!=', 'delete')
                            ->where('status', '!=', 'sending')
                            ->lockForUpdate()
                            ->first();
                        if (! $distribution) {
                            return false;
                        }

                        $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                        unset($remoteMeta['queue_dispatched_at'], $remoteMeta['transport_not_started']);
                        $remoteMeta['workflow_fence'] = $workflowFence;
                        $remoteMeta['distribution_payload'] = $payload;
                        if ($qualityCheck instanceof ArticleAiQualityCheck) {
                            $remoteMeta['ai_quality_guard'] = $this->qualityGuardAudit($qualityCheck);
                        } else {
                            unset($remoteMeta['ai_quality_guard']);
                        }

                        $distribution->forceFill([
                            'action' => 'update',
                            'status' => 'queued',
                            'last_error_message' => null,
                            'next_retry_at' => now(),
                            'payload_hash' => $payloadHash,
                            'idempotency_key' => $this->idempotencyKey(
                                (int) $distribution->article_id,
                                $channelId,
                                'update',
                                $payloadHash,
                            ),
                            'remote_meta' => $remoteMeta,
                        ])->save();
                        $this->dispatchDeliveryAfterCommit((int) $distribution->id);

                        return true;
                    });
                    if ($queued) {
                        $count++;
                    }
                }
            });

        if ($count > 0) {
            DB::transaction(function () use ($channelId, $count): void {
                $channel = DistributionChannel::query()->whereKey($channelId)->lockForUpdate()->first();
                if (! $channel) {
                    return;
                }
                $this->log(
                    'info',
                    '目标站点内容刷新已入队',
                    $channelId,
                    null,
                    null,
                    ['event' => 'target.content_refresh_queued', 'count' => $count]
                );
            });
        }

        return $count;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function log(string $level, string $message, ?int $channelId = null, ?int $distributionId = null, ?int $articleId = null, array $context = []): void
    {
        DistributionLog::query()->create([
            'distribution_channel_id' => $channelId,
            'article_distribution_id' => $distributionId,
            'article_id' => $articleId,
            'level' => $level,
            'event' => is_string($context['event'] ?? null) ? (string) $context['event'] : null,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }

    /** Record remote acknowledgement after a failed local commit without sending again. */
    private function preserveAcknowledgedResponse(ArticleDistribution $candidate, array $response): void
    {
        DB::transaction(function () use ($candidate, $response): void {
            $channel = DistributionChannel::query()->whereKey($candidate->distribution_channel_id)->lockForUpdate()->first();
            $this->lockWorkflowArticle((int) $candidate->article_id);
            $delivery = ArticleDistribution::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $channel || ! $delivery) {
                return;
            }
            $meta = (array) $delivery->remote_meta;
            $remoteId = is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $delivery->remote_id;
            $meta['acknowledged_response'] = [
                'action' => $delivery->action, 'remote_id' => $remoteId,
                'recorded_at' => now()->toIso8601String(), 'error_code' => 'distribution_local_commit_failed',
            ];
            $delivery->update([
                'status' => $delivery->status === 'sending' ? 'outcome_unknown' : $delivery->status,
                'remote_id' => $delivery->remote_id ?: $remoteId,
                'remote_url' => $delivery->action === 'delete' ? null : ($response['remote_url'] ?? $delivery->remote_url),
                'remote_meta' => $meta, 'next_retry_at' => null,
                'last_error_message' => '远端已响应成功，本地提交失败；已停止自动重发，请对账确认。',
            ]);
        }, 3);
    }

    /** The caller holds the channel and article locks for the successful deletion. */
    private function markRemoteCopyDeleted(ArticleDistribution $deleted): void
    {
        $siblings = ArticleDistribution::query()->where('article_id', $deleted->article_id)
            ->where('distribution_channel_id', $deleted->distribution_channel_id)
            ->orderBy('id')->lockForUpdate()->get();
        $version = (int) $siblings->max(fn (ArticleDistribution $item): int => (int) data_get($item->remote_meta, 'remote_copy_version', 0));
        $hasLiveCopy = $siblings->contains(fn (ArticleDistribution $item): bool => $item->action !== 'delete' && ! data_get($item->remote_meta, 'remote_deleted'));
        $version += (int) $hasLiveCopy;
        foreach ($siblings as $sibling) {
            $meta = (array) $sibling->remote_meta;
            $meta['remote_copy_version'] = $version;
            $meta['remote_deleted'] = true;
            $meta['wordpress_remote_deleted_at'] = now()->toIso8601String();
            unset($meta['wordpress_delivery_fingerprint']);
            $sibling->update(['remote_url' => null, 'remote_meta' => $meta]);
        }
    }

    private function idempotencyKey(int $articleId, int $channelId, string $action, ?string $payloadHash = null, int $copyVersion = 0): string
    {
        $key = 'article-'.$articleId.'-channel-'.$channelId.'-'.$action.'-v1'.($copyVersion > 0 ? '-copy-'.$copyVersion : '');

        return $payloadHash === null || $payloadHash === ''
            ? $key
            : $key.'-'.substr($payloadHash, 0, 16);
    }

    private function sendImmediateAction(ArticleDistribution $distribution, string $action): ArticleDistribution
    {
        $distribution->loadMissing(['article', 'channel']);
        $article = $distribution->article;
        $channel = $distribution->channel;
        if (! $article || ! $channel) {
            throw new \RuntimeException('分发记录缺少文章或渠道');
        }

        $payload = $action === 'delete' ? [] : $this->buildVerifiedPayload($article, 'distribution_send');
        if ($action === 'update') {
            $payload['event'] = 'article.update';
        }
        $payloadHash = $action === 'delete'
            ? null
            : AiPayloadDigest::make($payload);

        [$distribution, $channel, $identityConflict] = $this->claimImmediateAction($distribution, $action, $payloadHash, $payload);
        if (is_array($identityConflict)) {
            $this->log(
                'error',
                '检测到多个 WordPress 远端文章 ID，已停止立即操作',
                (int) $channel->id,
                (int) $distribution->id,
                (int) $article->id,
                [
                    'event' => 'distribution.remote_identity_conflict',
                    'known_post_ids' => $identityConflict['known_post_ids'],
                ],
            );

            throw new \RuntimeException('检测到多个 WordPress 远端文章 ID，需要人工对账。');
        }
        $wordpressDeliveryFingerprint = $channel->isWordPressRest() && $action === 'update'
            ? $this->wordpressDeliveryFingerprint($payload, $channel)
            : null;

        $this->channelOperationLeaseService->run(
            $channel,
            'article_'.$action,
            function (DistributionChannel $lockedChannel) use ($distribution, $action, $payload, $article, $wordpressDeliveryFingerprint): void {
                $acknowledgedResponse = null;
                try {
                    $result = DB::transaction(function () use ($distribution, $action, $lockedChannel, $payload, $wordpressDeliveryFingerprint, &$acknowledgedResponse): array {
                        $lockedChannel = DistributionChannel::query()->whereKey($lockedChannel->id)->lockForUpdate()->firstOrFail();
                        if ($lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                            throw new \RuntimeException('distribution_channel_unavailable');
                        }
                        if ($lockedChannel->isHostedSite()) {
                            HostedSiteProfile::query()->where('distribution_channel_id', $lockedChannel->id)->lockForUpdate()->firstOrFail();
                        }
                        $this->aiQualityRolloutPolicy->acquireDistributionLeaseEpoch();
                        $article = $this->lockWorkflowArticle((int) $distribution->article_id);
                        if (! $article) {
                            return ['saved' => false];
                        }
                        $task = $article?->task;
                        if ($article->task_id && ! $task) {
                            return ['saved' => false];
                        }
                        $locked = ArticleDistribution::query()
                            ->whereKey((int) $distribution->id)
                            ->where('article_id', (int) $article->id)
                            ->lockForUpdate()
                            ->first();
                        if (! $locked || (string) $locked->status !== 'sending') {
                            return ['saved' => false];
                        }

                        $locked->setRelation('article', $article);
                        $locked->setRelation('channel', $lockedChannel);
                        if ($action !== 'delete') {
                            $currentPayload = $this->payloadBuilder->build($article->load(['category:id,name,slug', 'author:id,name', 'articleImages.image']));
                            $currentPayload['event'] = 'article.update';
                            if (! $this->workflowFenceMatches($article, data_get($locked->remote_meta, 'workflow_fence'))
                                || ! $this->isDistributableSnapshot($article)
                                || ! hash_equals((string) $locked->payload_hash, AiPayloadDigest::make($currentPayload))) {
                                $locked->update(['status' => 'cancelled', 'next_retry_at' => null, 'last_error_message' => 'distribution_workflow_superseded']);

                                return ['saved' => false, 'superseded' => true];
                            }
                            $this->publicationQualityGate->check($article, 'distribution_immediate_send_fenced');
                        }
                        $publisher = $this->publisherManager->forChannel($lockedChannel);
                        $response = $action === 'delete' ? $publisher->delete($locked) : $publisher->update($locked, $payload);
                        $acknowledgedResponse = $response;
                        $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
                        $locked->refresh();
                        if ($locked->status !== 'sending') {
                            return ['saved' => false];
                        }
                        $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
                        $knownWordPressPostId = $lockedChannel->isWordPressRest() && $action !== 'delete'
                            ? $locked->wordpressPostId()
                            : null;
                        $returnedWordPressPostId = $this->wordpressPostIdFromResponse($response);
                        if ($knownWordPressPostId
                            && $returnedWordPressPostId
                            && $knownWordPressPostId !== $returnedWordPressPostId) {
                            $existingMeta['wordpress_identity_conflict'] = [
                                'expected_post_id' => $knownWordPressPostId,
                                'returned_post_id' => $returnedWordPressPostId,
                            ];
                            $locked->forceFill([
                                'status' => 'outcome_unknown',
                                'next_retry_at' => null,
                                'last_error_message' => 'WordPress 返回的文章 ID 与已知远端身份不一致，需要人工对账。',
                                'remote_meta' => $existingMeta,
                            ])->save();

                            return [
                                'saved' => false,
                                'identity_conflict' => true,
                                'expected_post_id' => $knownWordPressPostId,
                                'returned_post_id' => $returnedWordPressPostId,
                            ];
                        }
                        if ($action !== 'delete') {
                            unset($existingMeta['remote_deleted']);
                        }
                        if ($wordpressDeliveryFingerprint !== null) {
                            $existingMeta['wordpress_delivery_fingerprint'] = $wordpressDeliveryFingerprint;
                            $existingMeta['distribution_payload'] = $payload;
                            unset($existingMeta['wordpress_remote_deleted_at']);
                        }
                        $locked->forceFill([
                            'status' => 'synced',
                            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $locked->remote_id,
                            'remote_url' => $action === 'delete'
                                ? null
                                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $locked->remote_url),
                            'remote_meta' => array_replace($existingMeta, $responseMeta),
                            'last_error_message' => null,
                        ])->save();
                        if ($action === 'delete') {
                            $this->markRemoteCopyDeleted($locked);
                        }
                        if ($wordpressDeliveryFingerprint !== null) {
                            ArticleDistribution::query()
                                ->where('article_id', (int) $locked->article_id)
                                ->where('distribution_channel_id', (int) $locked->distribution_channel_id)
                                ->where('id', '!=', (int) $locked->id)
                                ->where('action', '!=', 'delete')
                                ->lockForUpdate()
                                ->get()
                                ->each(function (ArticleDistribution $sibling) use ($payload, $response, $wordpressDeliveryFingerprint): void {
                                    $siblingMeta = is_array($sibling->remote_meta) ? $sibling->remote_meta : [];
                                    $siblingMeta['wordpress_delivery_fingerprint'] = $wordpressDeliveryFingerprint;
                                    $siblingMeta['distribution_payload'] = $payload;
                                    unset($siblingMeta['wordpress_remote_deleted_at']);
                                    $sibling->forceFill([
                                        'remote_url' => is_scalar($response['remote_url'] ?? null)
                                            ? (string) $response['remote_url']
                                            : $sibling->remote_url,
                                        'remote_meta' => $siblingMeta,
                                    ])->save();
                                });
                        }

                        return ['saved' => true, 'response' => $response];
                    });
                } catch (Throwable $exception) {
                    if ($exception instanceof DistributionAcknowledgedResponseException) {
                        $acknowledgedResponse = $exception->response;
                    }
                    if ($acknowledgedResponse !== null) {
                        $this->preserveAcknowledgedResponse($distribution, $acknowledgedResponse);
                    }
                    throw $exception;
                }
                if ($result['superseded'] ?? false) {
                    throw new \RuntimeException('distribution_workflow_superseded');
                }
                if ((bool) ($result['identity_conflict'] ?? false)) {
                    $this->log(
                        'error',
                        'WordPress 返回的文章 ID 与已知远端身份不一致，已停止立即操作',
                        (int) $lockedChannel->id,
                        (int) $distribution->id,
                        (int) $article->id,
                        [
                            'event' => 'distribution.remote_identity_conflict',
                            'expected_post_id' => $result['expected_post_id'] ?? null,
                            'returned_post_id' => $result['returned_post_id'] ?? null,
                        ],
                    );

                    throw new \RuntimeException('WordPress 返回的文章 ID 与已知远端身份不一致，需要人工对账。');
                }
                if (! (bool) ($result['saved'] ?? false)) {
                    $this->log(
                        'warning',
                        '远端立即操作返回时本地任务已删除，保留待人工核对状态',
                        (int) $lockedChannel->id,
                        (int) $distribution->id,
                        (int) $article->id,
                        ['event' => 'distribution.result_after_task_deletion'],
                    );

                    return;
                }

                $this->log(
                    'info',
                    $action === 'delete' ? '远端文章副本已删除' : '远端文章已更新',
                    (int) $lockedChannel->id,
                    (int) $distribution->id,
                    (int) $article->id,
                    ['event' => 'article.'.$action, 'remote_result' => $result['response']]
                );
            },
        );

        return ArticleDistribution::query()->findOrFail((int) $distribution->id);
    }

    /**
     * @return array{ArticleDistribution,DistributionChannel,array<string,mixed>|null}
     */
    private function claimImmediateAction(ArticleDistribution $candidate, string $action, ?string $payloadHash, array $payload): array
    {
        return DB::transaction(function () use ($candidate, $action, $payloadHash, $payload): array {
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $article = $this->lockWorkflowArticle((int) $candidate->article_id);
            $task = $article?->task;
            $distributions = ArticleDistribution::query()
                ->where('article_id', (int) $candidate->article_id)
                ->where('distribution_channel_id', (int) $candidate->distribution_channel_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sourceDistribution = $distributions->firstWhere('id', (int) $candidate->id);
            $distribution = $distributions->firstWhere('action', $action);
            if (! $channel
                || ! $article
                || ($article->task_id && ! $task)
                || ! $sourceDistribution
                || (int) $sourceDistribution->article_id !== (int) $article->id) {
                throw new \RuntimeException('分发记录缺少文章或渠道');
            }
            if ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
                $message = (string) $channel->status === DistributionChannel::STATUS_DELETING
                    ? __('admin.distribution.delete.operation_blocked')
                    : __('admin.distribution.delete.channel_unavailable_error');

                throw new \RuntimeException($message);
            }
            $blockedDistribution = $distributions->first(
                static fn (ArticleDistribution $item): bool => in_array((string) $item->status, ['sending', 'outcome_unknown'], true),
            );
            if ($blockedDistribution instanceof ArticleDistribution) {
                throw new \RuntimeException(($channel->isWordPressRest() ? 'WordPress ' : '').'分发正在处理中或等待人工对账，当前操作已阻止。');
            }
            if ($channel->isWordPressRest()) {
                $wordpressPostIds = $distributions
                    ->map(static fn (ArticleDistribution $distribution): ?int => $distribution->wordpressPostId())
                    ->filter(static fn (?int $postId): bool => is_int($postId) && $postId > 0)
                    ->unique()
                    ->sort()
                    ->values();
                if ($wordpressPostIds->count() > 1) {
                    $sourceMeta = is_array($sourceDistribution->remote_meta) ? $sourceDistribution->remote_meta : [];
                    $sourceMeta['wordpress_identity_conflict'] = [
                        'known_post_ids' => $wordpressPostIds->all(),
                        'detected_at' => now()->toIso8601String(),
                    ];
                    $sourceDistribution->forceFill([
                        'status' => 'outcome_unknown',
                        'next_retry_at' => null,
                        'last_error_message' => '检测到多个 WordPress 远端文章 ID，需要人工对账。',
                        'remote_meta' => $sourceMeta,
                    ])->save();

                    return [
                        $sourceDistribution,
                        $channel,
                        ['known_post_ids' => $wordpressPostIds->all()],
                    ];
                }
            }
            $distribution ??= new ArticleDistribution([
                'article_id' => (int) $sourceDistribution->article_id,
                'distribution_channel_id' => (int) $sourceDistribution->distribution_channel_id,
                'action' => $action,
                'remote_id' => $sourceDistribution->remote_id,
                'remote_url' => $sourceDistribution->remote_url,
                'remote_meta' => $sourceDistribution->remote_meta,
            ]);
            if (! $distribution->wordpressPostId() && $sourceDistribution->wordpressPostId()) {
                $distribution->forceFill([
                    'remote_id' => $sourceDistribution->remote_id,
                    'remote_url' => $sourceDistribution->remote_url,
                    'remote_meta' => $sourceDistribution->remote_meta,
                ]);
            }

            $distribution->forceFill([
                'status' => 'sending',
                'attempt_count' => (int) $distribution->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_message' => null,
                'payload_hash' => $payloadHash,
                'remote_meta' => array_replace((array) $distribution->remote_meta, [
                    'workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article, 'manual'),
                    'distribution_payload' => $payload,
                    'remote_copy_version' => (int) $distributions->max(fn (ArticleDistribution $item): int => (int) data_get($item->remote_meta, 'remote_copy_version', 0)),
                ]),
                'idempotency_key' => $this->idempotencyKey(
                    (int) $distribution->article_id,
                    (int) $channel->id,
                    $action,
                    $payloadHash,
                    (int) $distributions->max(fn (ArticleDistribution $item): int => (int) data_get($item->remote_meta, 'remote_copy_version', 0)),
                ),
            ])->save();

            return [$distribution, $channel, null];
        });
    }

    /** @param array<string,mixed> $response */
    private function wordpressPostIdFromResponse(array $response): ?int
    {
        $remoteId = $response['remote_id'] ?? null;
        if (! is_scalar($remoteId) || ! ctype_digit((string) $remoteId)) {
            return null;
        }

        $postId = (int) $remoteId;

        return $postId > 0 ? $postId : null;
    }

    /** @param array<string,mixed> $payload */
    private function wordpressDeliveryFingerprint(array $payload, DistributionChannel $channel): string
    {
        $deliveryPayload = $payload;
        unset($deliveryPayload['event']);
        if (is_array($deliveryPayload['article'] ?? null)) {
            unset($deliveryPayload['article']['updated_at']);
        }

        return AiPayloadDigest::make([
            'payload' => $deliveryPayload,
            'channel_revision' => $this->channelRevision($channel),
        ]);
    }

    /**
     * Build an immutable payload from the row-locked article snapshot that passed the risk gate.
     *
     * @return array<string, mixed>
     */
    private function buildVerifiedPayload(Article $article, string $trigger): array
    {
        $result = DB::transaction(function () use ($article, $trigger): Article {
            $lockedArticle = $this->lockWorkflowArticle((int) $article->id) ?? throw new \RuntimeException('article_unavailable');

            $lockedArticle->load([
                'category:id,name,slug',
                'author:id,name',
                'articleImages.image',
            ]);
            if (! $this->isDistributableSnapshot($lockedArticle)) {
                throw new \RuntimeException('文章当前状态不允许分发');
            }

            $this->publicationQualityGate->check($lockedArticle, $trigger);

            return clone $lockedArticle;
        });

        return $this->payloadBuilder->build($result);
    }

    private function isDistributableSnapshot(Article $article): bool
    {
        if (! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
            return false;
        }
        $eligibility = app(ArticlePublicationEligibilityService::class);
        if ($eligibility->manualReviewRequired($article) && ! $eligibility->hasCurrentApproval($article)) {
            return false;
        }
        if ($article->task === null) {
            return in_array((string) $article->status, ['published', 'private'], true);
        }

        $publishScope = (string) ($article->task->publish_scope ?? 'local_and_distribution');
        if ($publishScope === 'local_only') {
            return false;
        }

        return $article->status === 'published'
            || ($publishScope === 'distribution_only' && $article->status === 'private');
    }

    /** Automatic task work follows the current channel selection; approved workspace targets have their own guard. */
    private function currentChannelAllowsDelivery(Article $article, ArticleDistribution $delivery): bool
    {
        if (! $article->task_id || data_get($delivery->remote_meta, 'workflow_fence.origin') !== 'automatic'
            || is_array(data_get($delivery->remote_meta, 'ai_workspace_guard'))) {
            return true;
        }

        return $article->task_id && DB::table('task_distribution_channels')
            ->where('task_id', $article->task_id)->where('distribution_channel_id', $delivery->distribution_channel_id)->exists();
    }

    public function dispatchDeliveryAfterCommit(int $distributionId): void
    {
        DB::afterCommit(static function () use ($distributionId): void {
            $delivery = ArticleDistribution::query()->find($distributionId);
            if (! $delivery || $delivery->status !== 'queued') {
                return;
            }
            try {
                Bus::dispatch((new ProcessArticleDistributionJob($distributionId))->onQueue('distribution')->delay($delivery->next_retry_at)->beforeCommit());
            } catch (Throwable $exception) {
                $attempts = (int) data_get($delivery->remote_meta, 'queue_submit_attempts', 0) + 1;
                self::recordQueueSubmission($distributionId, [
                    'queue_submit_attempts' => $attempts,
                    'queue_submit_retry_at' => now()->addSeconds($attempts === 1 ? 60 : 300)->toIso8601String(),
                ], $attempts >= 3 ? 'distribution_queue_retry_exhausted' : 'distribution_queue_submission_failed');

                throw $exception;
            }
            self::recordQueueSubmission($distributionId, [
                'queue_dispatched_at' => now()->toIso8601String(),
                'queue_submit_attempts' => 0, 'queue_submit_retry_at' => null,
            ]);
        });
    }

    private static function recordQueueSubmission(int $id, array $submission, ?string $error = null): void
    {
        DB::transaction(static function () use ($id, $submission, $error): void {
            $delivery = ArticleDistribution::query()->whereKey($id)->lockForUpdate()->first();
            if (! $delivery || $delivery->status !== 'queued') {
                return;
            }
            $delivery->update([
                'remote_meta' => array_replace((array) $delivery->remote_meta, $submission),
                'last_error_message' => $error,
            ]);
        });
    }

    private function lockWorkflowArticle(int $articleId): ?Article
    {
        $taskId = (int) Article::query()->whereKey($articleId)->value('task_id');
        $task = $taskId ? Task::query()->whereKey($taskId)->lockForUpdate()->first() : null;
        $article = Article::query()->whereKey($articleId)->lockForUpdate()->first();
        if ($article && (int) $article->task_id !== $taskId) {
            throw new \RuntimeException('workflow_version_conflict');
        }
        if ($article) {
            $article->setRelation('task', $task);
        }

        return $article;
    }

    public function workflowFenceMatches(Article $article, mixed $fence): bool
    {
        if (! is_array($fence)
            || (int) ($fence['workflow_version'] ?? 0) !== (int) $article->workflow_version
            || (int) ($fence['task_id'] ?? 0) !== (int) $article->task_id
            || (int) ($fence['automation_version'] ?? 0) !== (int) ($article->task?->automation_version ?? 0)
            || $article->publication_intent === 'hold' || $article->review_status === 'rejected') {
            return false;
        }

        return ($fence['origin'] ?? '') === 'manual' || ! $article->task
            || ($article->task->status === 'active' && (bool) $article->task->schedule_enabled);
    }
}
