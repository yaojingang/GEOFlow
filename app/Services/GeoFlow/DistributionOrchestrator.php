<?php

namespace App\Services\GeoFlow;

use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiWorkspaceChannelRevision;
use App\Exceptions\DistributionTaskRevisionMismatch;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\HostedSiteArticleAssignment;
use App\Models\Task;
use App\Services\AiWorkspace\AiWorkspaceDispatchGuard;
use App\Services\HostedSites\HostedSiteAllocationRequestService;
use App\Services\HostedSites\HostedSiteAllocator;
use App\Services\HostedSites\HostedSiteLifecycleService;
use App\Support\GeoFlow\DistributionErrorSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DistributionOrchestrator
{
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
        $payload = [
            'id' => (int) $task->id,
            'status' => (string) $task->status,
            'publish_scope' => (string) $task->publish_scope,
            'channel_ids' => $channelIds,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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
    public function enqueueForArticle(int|Article $article, string $action = 'publish', array $aiWorkspaceGuard = []): array
    {
        return $this->enqueueForArticleSelection($article, $action, $aiWorkspaceGuard);
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
    ): array {
        try {
            $articleModel = $article instanceof Article
                ? $article
                : Article::query()->whereKey($article)->first();

            if (! $articleModel || ! $articleModel->task_id) {
                return [];
            }

            $articleModel->load('task.distributionChannels');
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
                return [];
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
                    $this->hostedSiteLifecycle->restorePublication($articleModel);
                } else {
                    $allocationRequest = $this->hostedAllocationRequests->request($articleModel);
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
            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            $queuedDistributionIds = [];
            foreach ($channels as $channel) {
                $distributionId = DB::transaction(function () use ($channel, $articleModel, $action, $payload, $payloadHash, $aiWorkspaceGuard, $qualityCheck, $exactTargets): ?int {
                    $lockedChannel = DistributionChannel::query()
                        ->whereKey((int) $channel->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                        return null;
                    }

                    $lockedArticle = Article::query()
                        ->whereKey((int) $articleModel->id)
                        ->lockForUpdate()
                        ->first(['id', 'task_id', 'status']);
                    if (! $lockedArticle
                        || ! $lockedArticle->task_id
                        || (int) $lockedArticle->task_id !== (int) $articleModel->task_id) {
                        return null;
                    }
                    $lockedTask = Task::query()
                        ->whereKey((int) $lockedArticle->task_id)
                        ->lockForUpdate()
                        ->first(['id', 'publish_scope', 'distribution_strategy']);
                    if (! $lockedTask || (string) $lockedTask->publish_scope === 'local_only') {
                        return null;
                    }
                    $canDistribute = (string) $lockedArticle->status === 'published'
                        || ((string) $lockedTask->publish_scope === 'distribution_only'
                            && in_array((string) $lockedArticle->status, ['private', 'published'], true));
                    if (! $canDistribute) {
                        return null;
                    }
                    if ((! $exactTargets || $lockedChannel->isHostedSite())
                        && ! DB::table('task_distribution_channels')
                            ->where('task_id', (int) $lockedTask->id)
                            ->where('distribution_channel_id', (int) $lockedChannel->id)
                            ->exists()) {
                        return null;
                    }

                    $distribution = ArticleDistribution::query()
                        ->where('article_id', (int) $articleModel->id)
                        ->where('distribution_channel_id', (int) $lockedChannel->id)
                        ->where('action', $action)
                        ->lockForUpdate()
                        ->first();
                    if ($distribution && (string) $distribution->status === 'sending') {
                        return null;
                    }
                    $distribution ??= new ArticleDistribution([
                        'article_id' => (int) $articleModel->id,
                        'distribution_channel_id' => (int) $lockedChannel->id,
                        'action' => $action,
                    ]);
                    $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
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
                        if ($qualityCheck !== null) {
                            $remoteMeta['ai_quality_guard'] = [
                                'check_id' => (int) $qualityCheck->id,
                                'input_fingerprint' => (string) $qualityCheck->input_fingerprint,
                                'article_content_hash' => (string) $qualityCheck->article_content_hash,
                                'decision' => (string) $qualityCheck->decision,
                                'score' => (int) $qualityCheck->score,
                                'is_overridden' => (bool) $qualityCheck->is_overridden,
                            ];
                        }
                    }
                    $distribution->forceFill([
                        'status' => 'queued',
                        'next_retry_at' => now(),
                        'payload_hash' => $payloadHash,
                        'idempotency_key' => $this->idempotencyKey((int) $articleModel->id, (int) $lockedChannel->id, $action),
                        'remote_meta' => $remoteMeta,
                    ])->save();

                    $this->log('info', '文章已进入分发队列', $lockedChannel->id, $distribution->id, $articleModel->id, [
                        'event' => 'distribution.queued',
                        'strategy' => (string) ($lockedTask->distribution_strategy ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
                    ]);
                    ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                        ->onQueue('distribution')
                        ->afterCommit();

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
            if ($aiWorkspaceGuard !== []) {
                throw $e;
            }

            return [];
        }
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

        $immutablePayload = data_get($currentDistribution->remote_meta, 'ai_workspace_payload');
        if ((string) $currentDistribution->action !== 'delete') {
            $this->publicationQualityGate->check($article, 'distribution_send');
        }
        $payload = is_array($immutablePayload)
            ? $immutablePayload
            : ((string) $currentDistribution->action === 'delete' ? [] : $this->buildVerifiedPayload($article, 'distribution_send'));
        if (is_array($immutablePayload)) {
            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
            if (! hash_equals((string) $currentDistribution->payload_hash, $payloadHash)) {
                throw new \RuntimeException('AI 工作台分发载荷摘要校验失败。');
            }
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
                $guard = data_get($distribution->remote_meta, 'ai_workspace_guard');
                $dispatchChannel = $lockedChannel;
                if (is_array($guard)) {
                    $approvedRevision = (string) ($guard['channel_revision'] ?? '');
                    if ($approvedRevision === '' || ! hash_equals($approvedRevision, $this->channelRevision($lockedChannel))) {
                        throw new \RuntimeException('AI 工作台分发目标在审批后已变化。');
                    }
                    $dispatchChannel = $this->aiWorkspaceDispatchGuard->authorizeDistributionDispatch($distribution);
                    $distribution->refresh();
                    $distribution->setRelation('channel', $dispatchChannel);
                    $distribution->loadMissing('article');
                }
                $publisher = $this->publisherManager->forChannel($dispatchChannel);
                $response = match ((string) $distribution->action) {
                    'update' => $publisher->update($distribution, $payload),
                    'delete' => $publisher->delete($distribution),
                    default => $publisher->publish($distribution, $payload),
                };
                $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
                $saved = DB::transaction(function () use ($distribution, $response, $responseMeta): bool {
                    $locked = ArticleDistribution::query()
                        ->whereKey((int) $distribution->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $locked || (string) $locked->status !== 'sending') {
                        return false;
                    }

                    $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
                    unset($existingMeta['ai_workspace_payload']);
                    $locked->forceFill([
                        'status' => 'synced',
                        'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $locked->remote_id,
                        'remote_url' => (string) $locked->action === 'delete'
                            ? null
                            : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $locked->remote_url),
                        'remote_meta' => array_replace($existingMeta, $responseMeta),
                        'last_error_message' => null,
                    ])->save();

                    return true;
                });
                if (! $saved) {
                    $this->log(
                        'warning',
                        '外部分发返回结果时本地任务已停止，保留待人工核对状态',
                        $dispatchChannel->id,
                        $distribution->id,
                        $article->id,
                        ['event' => 'distribution.result_after_task_deletion'],
                    );

                    return false;
                }

                $this->log('info', '文章分发成功', $dispatchChannel->id, $distribution->id, $article->id, $response);

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
        $payload = data_get($distribution->remote_meta, 'ai_workspace_payload');
        $publisher = $this->publisherManager->forChannel($distribution->channel);
        if (! is_array($payload) || ! $publisher instanceof WordPressRestPublisher) {
            return false;
        }
        $response = $publisher->reconcilePublication($distribution, $payload);
        if (! is_array($response)) {
            return false;
        }

        $updated = DB::transaction(function () use ($distribution, $response): bool {
            $locked = ArticleDistribution::query()->whereKey((int) $distribution->id)->lockForUpdate()->firstOrFail();
            if (! in_array((string) $locked->status, ['sending', 'outcome_unknown'], true)) {
                return (string) $locked->status === 'synced';
            }
            $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
            unset($existingMeta['ai_workspace_payload']);
            $locked->forceFill([
                'status' => 'synced',
                'remote_id' => (string) ($response['remote_id'] ?? ''),
                'remote_url' => (string) ($response['remote_url'] ?? ''),
                'remote_meta' => array_replace($existingMeta, (array) ($response['remote_meta'] ?? [])),
                'last_error_message' => null,
                'next_retry_at' => null,
            ])->save();

            return true;
        });
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
            $article = Article::query()
                ->whereKey((int) $candidate->article_id)
                ->when($taskId > 0, fn ($query) => $query->where('task_id', $taskId))
                ->lockForUpdate()
                ->first(['id']);
            $task = $taskId > 0
                ? Task::query()->whereKey($taskId)->lockForUpdate()->first(['id'])
                : null;
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

            $distribution->forceFill([
                'status' => 'sending',
                'attempt_count' => (int) $distribution->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_message' => null,
            ])->save();

            return $distribution;
        });
    }

    public function updateRemoteArticle(ArticleDistribution $distribution): void
    {
        $this->sendImmediateAction($distribution, 'update');
    }

    public function deleteRemoteArticle(ArticleDistribution $distribution): void
    {
        $this->sendImmediateAction($distribution, 'delete');
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

                    $queued = DB::transaction(function () use ($candidate, $channelId): bool {
                        $lockedChannel = DistributionChannel::query()
                            ->whereKey($channelId)
                            ->lockForUpdate()
                            ->first();
                        if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                            return false;
                        }
                        $article = Article::query()
                            ->whereKey((int) $candidate->article_id)
                            ->lockForUpdate()
                            ->first(['id', 'task_id', 'status']);
                        if (! $article || ! in_array((string) $article->status, ['published', 'private'], true)) {
                            return false;
                        }
                        $task = $article->task_id
                            ? Task::query()->whereKey((int) $article->task_id)->lockForUpdate()->first(['id', 'publish_scope'])
                            : null;
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

                        $distribution->forceFill([
                            'action' => 'update',
                            'status' => 'queued',
                            'last_error_message' => null,
                            'next_retry_at' => now(),
                            'idempotency_key' => $this->idempotencyKey((int) $distribution->article_id, $channelId, 'update'),
                        ])->save();
                        ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                            ->onQueue('distribution')
                            ->afterCommit();

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

    private function idempotencyKey(int $articleId, int $channelId, string $action): string
    {
        return 'article-'.$articleId.'-channel-'.$channelId.'-'.$action.'-v1';
    }

    private function sendImmediateAction(ArticleDistribution $distribution, string $action): void
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
            : hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        [$distribution, $channel] = $this->claimImmediateAction($distribution, $action, $payloadHash);

        $this->channelOperationLeaseService->run(
            $channel,
            'article_'.$action,
            function (DistributionChannel $lockedChannel) use ($distribution, $action, $payload, $article): void {
                $publisher = $this->publisherManager->forChannel($lockedChannel);
                $response = $action === 'delete'
                    ? $publisher->delete($distribution)
                    : $publisher->update($distribution, $payload);

                $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
                $saved = DB::transaction(function () use ($distribution, $response, $responseMeta, $action): bool {
                    $article = Article::query()
                        ->whereKey((int) $distribution->article_id)
                        ->lockForUpdate()
                        ->first(['id', 'task_id']);
                    if (! $article) {
                        return false;
                    }
                    $task = $article->task_id
                        ? Task::query()->whereKey((int) $article->task_id)->lockForUpdate()->first(['id'])
                        : null;
                    if ($article->task_id && ! $task) {
                        return false;
                    }
                    $locked = ArticleDistribution::query()
                        ->whereKey((int) $distribution->id)
                        ->where('article_id', (int) $article->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $locked || (string) $locked->status !== 'sending') {
                        return false;
                    }

                    $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
                    $locked->forceFill([
                        'status' => 'synced',
                        'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $locked->remote_id,
                        'remote_url' => $action === 'delete'
                            ? null
                            : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $locked->remote_url),
                        'remote_meta' => array_replace($existingMeta, $responseMeta),
                        'last_error_message' => null,
                    ])->save();

                    return true;
                });
                if (! $saved) {
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
                    ['event' => 'article.'.$action, 'remote_result' => $response]
                );
            },
        );
    }

    /**
     * @return array{ArticleDistribution,DistributionChannel}
     */
    private function claimImmediateAction(ArticleDistribution $candidate, string $action, ?string $payloadHash): array
    {
        return DB::transaction(function () use ($candidate, $action, $payloadHash): array {
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $article = Article::query()
                ->whereKey((int) $candidate->article_id)
                ->lockForUpdate()
                ->first(['id', 'task_id']);
            $task = $article?->task_id
                ? Task::query()->whereKey((int) $article->task_id)->lockForUpdate()->first(['id'])
                : null;
            $distributions = ArticleDistribution::query()
                ->where('article_id', (int) $candidate->article_id)
                ->where('distribution_channel_id', (int) $candidate->distribution_channel_id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $distribution = $distributions->firstWhere('action', $action)
                ?? $distributions->firstWhere('id', (int) $candidate->id);
            if (! $channel
                || ! $article
                || ($article->task_id && ! $task)
                || ! $distribution
                || (int) $distribution->article_id !== (int) $article->id) {
                throw new \RuntimeException('分发记录缺少文章或渠道');
            }
            if ((string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
                $message = (string) $channel->status === DistributionChannel::STATUS_DELETING
                    ? __('admin.distribution.delete.operation_blocked')
                    : __('admin.distribution.delete.channel_unavailable_error');

                throw new \RuntimeException($message);
            }

            $distribution->forceFill([
                'action' => $action,
                'status' => 'sending',
                'attempt_count' => (int) $distribution->attempt_count + 1,
                'last_attempt_at' => now(),
                'last_error_message' => null,
                'payload_hash' => $payloadHash,
                'idempotency_key' => $this->idempotencyKey((int) $distribution->article_id, (int) $channel->id, $action),
            ])->save();

            return [$distribution, $channel];
        });
    }

    /**
     * Build an immutable payload from the row-locked article snapshot that passed the risk gate.
     *
     * @return array<string, mixed>
     */
    private function buildVerifiedPayload(Article $article, string $trigger): array
    {
        $result = DB::transaction(function () use ($article, $trigger): Article {
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedArticle->load([
                'category:id,name,slug',
                'author:id,name',
                'task:id,name,publish_scope',
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
        if ($article->task === null) {
            return in_array((string) $article->status, ['published', 'private'], true);
        }

        if (! in_array((string) $article->review_status, ['approved', 'auto_approved'], true)) {
            return false;
        }

        $publishScope = (string) ($article->task->publish_scope ?? 'local_and_distribution');
        if ($publishScope === 'local_only') {
            return false;
        }

        return $article->status === 'published'
            || ($publishScope === 'distribution_only' && $article->status === 'private');
    }
}
