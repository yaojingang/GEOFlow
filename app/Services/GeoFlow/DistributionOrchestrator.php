<?php

namespace App\Services\GeoFlow;

use App\Ai\Workspace\AiPayloadDigest;
use App\Ai\Workspace\AiWorkspaceChannelRevision;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\DistributionTaskRevisionMismatch;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
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
    public function enqueueForArticle(
        int|Article $article,
        string $action = 'publish',
        array $aiWorkspaceGuard = [],
        bool $throwOnFailure = false,
    ): array {
        return $this->enqueueForArticleSelection($article, $action, $aiWorkspaceGuard, null, $throwOnFailure);
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
                        ),
                        'remote_meta' => $remoteMeta,
                    ])->save();

                    $this->log('info', '文章已进入分发队列', $lockedChannel->id, $distribution->id, $articleModel->id, [
                        'event' => 'distribution.queued',
                        'strategy' => (string) ($lockedTask->distribution_strategy ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
                        'ai_quality_guard' => $qualityCheck !== null ? $this->qualityGuardAudit($qualityCheck) : null,
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
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
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
        if ((string) $currentDistribution->action !== 'delete' && ! $this->isDistributableSnapshot($article)) {
            throw new \RuntimeException('文章当前状态不允许分发');
        }

        if ((string) $currentDistribution->action !== 'delete'
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
        if (! $this->qualityGuardMatches($currentDistribution, $qualityCheck)) {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_basis_changed',
                '分发绑定的 AI 质检依据已变化，请重新入队。',
                $qualityCheck,
            );
        }
        $payload = $immutablePayload;
        $payloadHash = $this->payloadHash($payload);
        if (! hash_equals((string) $currentDistribution->payload_hash, $payloadHash)) {
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
                $response = DB::transaction(function () use ($distribution, $payload, $lockedChannel): ?array {
                    $committedEpoch = $this->aiQualityRolloutPolicy->acquireDistributionLeaseEpoch();
                    $lockedArticle = Article::query()
                        ->whereKey((int) $distribution->article_id)
                        ->lockForUpdate()
                        ->first();
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
                        $guard = data_get($locked->remote_meta, 'ai_quality_guard');
                        $qualityCheck = $this->publicationQualityGate->check($lockedArticle, 'distribution_send_fenced');
                        $guardEpochMatches = ! is_array($guard)
                            || (int) ($guard['rollout_epoch'] ?? 0) === $committedEpoch;
                        if (! $guardEpochMatches || ! $this->qualityGuardMatches($locked, $qualityCheck)) {
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
                    } catch (Throwable $exception) {
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
                    $locked->forceFill([
                        'status' => 'synced',
                        'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $locked->remote_id,
                        'remote_url' => (string) $locked->action === 'delete'
                            ? null
                            : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $locked->remote_url),
                        'remote_meta' => array_replace($existingMeta, $responseMeta),
                        'last_error_message' => null,
                    ])->save();

                    return ['saved' => true, 'response' => $response];
                }, 3);
                if (($response['deferred_exception'] ?? null) instanceof Throwable) {
                    throw $response['deferred_exception'];
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

        $updated = DB::transaction(function () use ($distribution, $response): bool {
            $locked = ArticleDistribution::query()->whereKey((int) $distribution->id)->lockForUpdate()->firstOrFail();
            if (! in_array((string) $locked->status, ['sending', 'outcome_unknown'], true)) {
                return (string) $locked->status === 'synced';
            }
            $existingMeta = is_array($locked->remote_meta) ? $locked->remote_meta : [];
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

                    $queued = DB::transaction(function () use (
                        $candidate,
                        $channelId,
                        $payload,
                        $payloadHash,
                        $articleUpdatedAt,
                        $qualityCheck,
                    ): bool {
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
                            ->first(['id', 'task_id', 'status', 'updated_at']);
                        if (! $article
                            || ! in_array((string) $article->status, ['published', 'private'], true)
                            || $article->updated_at?->toISOString() !== $articleUpdatedAt) {
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

                        $remoteMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
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

    private function idempotencyKey(int $articleId, int $channelId, string $action, ?string $payloadHash = null): string
    {
        $key = 'article-'.$articleId.'-channel-'.$channelId.'-'.$action.'-v1';

        return $payloadHash === null || $payloadHash === ''
            ? $key
            : $key.'-'.substr($payloadHash, 0, 16);
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
                'idempotency_key' => $this->idempotencyKey(
                    (int) $distribution->article_id,
                    (int) $channel->id,
                    $action,
                    $payloadHash,
                ),
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
