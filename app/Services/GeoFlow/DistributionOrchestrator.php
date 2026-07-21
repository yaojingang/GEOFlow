<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleRiskGateException;
use App\Exceptions\DistributionTaskRevisionMismatch;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class DistributionOrchestrator
{
    public function __construct(
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionPublisherManager $publisherManager,
        private readonly TaskDistributionChannelSelector $channelSelector,
        private readonly ArticleRiskGate $articleRiskGate,
        private readonly DistributionChannelOperationLeaseService $channelOperationLeaseService,
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

    public function enqueueForArticle(int|Article $article, string $action = 'publish'): void
    {
        try {
            $articleModel = $article instanceof Article
                ? $article
                : Article::query()->whereKey($article)->first();

            if (! $articleModel || ! $articleModel->task_id) {
                return;
            }

            $articleModel->load('task.distributionChannels');
            $publishScope = (string) ($articleModel->task?->publish_scope ?? 'local_and_distribution');
            if ($publishScope === 'local_only') {
                return;
            }
            $canDistribute = $articleModel->status === 'published'
                || ($publishScope === 'distribution_only' && in_array((string) $articleModel->status, ['private', 'published'], true));
            if (! $canDistribute) {
                return;
            }

            $channels = $articleModel->task?->distributionChannels
                ?->where('status', 'active') ?? new Collection;

            if ($channels->isEmpty()) {
                return;
            }

            $channels = $this->channelSelector->selectChannelsForArticle($articleModel, $channels, $action);

            if ($channels->isEmpty()) {
                return;
            }

            $payload = $action === 'delete'
                ? $this->payloadBuilder->build($articleModel)
                : $this->buildVerifiedPayload($articleModel, 'distribution_enqueue');
            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            foreach ($channels as $channel) {
                DB::transaction(function () use ($channel, $articleModel, $action, $payloadHash): void {
                    $lockedChannel = DistributionChannel::query()
                        ->whereKey((int) $channel->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                        return;
                    }

                    $distribution = ArticleDistribution::query()
                        ->where('article_id', (int) $articleModel->id)
                        ->where('distribution_channel_id', (int) $lockedChannel->id)
                        ->where('action', $action)
                        ->lockForUpdate()
                        ->first();
                    if ($distribution && (string) $distribution->status === 'sending') {
                        return;
                    }
                    $distribution ??= new ArticleDistribution([
                        'article_id' => (int) $articleModel->id,
                        'distribution_channel_id' => (int) $lockedChannel->id,
                        'action' => $action,
                    ]);
                    $distribution->forceFill([
                        'status' => 'queued',
                        'next_retry_at' => now(),
                        'payload_hash' => $payloadHash,
                        'idempotency_key' => $this->idempotencyKey((int) $articleModel->id, (int) $lockedChannel->id, $action),
                    ])->save();

                    $this->log('info', '文章已进入分发队列', $lockedChannel->id, $distribution->id, $articleModel->id, [
                        'event' => 'distribution.queued',
                        'strategy' => (string) ($articleModel->task?->distribution_strategy ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
                    ]);
                    ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                        ->onQueue('distribution')
                        ->afterCommit();
                });
            }
        } catch (Throwable $e) {
            $this->log('error', '文章分发入队失败：'.$e->getMessage(), null, null, $article instanceof Article ? (int) $article->id : $article, [
                'event' => 'distribution.enqueue_failed',
            ]);
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
        if (! $currentDistribution || ! $currentDistribution->article) {
            return false;
        }
        $article = $currentDistribution->article;

        $payload = (string) $currentDistribution->action === 'delete'
            ? []
            : $this->buildVerifiedPayload($article, 'distribution_send');
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
                $publisher = $this->publisherManager->forChannel($lockedChannel);
                $response = match ((string) $distribution->action) {
                    'update' => $publisher->update($distribution, $payload),
                    'delete' => $publisher->delete($distribution),
                    default => $publisher->publish($distribution, $payload),
                };
                $existingMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
                $distribution->forceFill([
                    'status' => 'synced',
                    'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
                    'remote_url' => (string) $distribution->action === 'delete'
                        ? null
                        : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
                    'remote_meta' => array_replace($existingMeta, $responseMeta),
                    'last_error_message' => null,
                ])->save();

                $this->log('info', '文章分发成功', $lockedChannel->id, $distribution->id, $article->id, $response);

                return true;
            },
        );
    }

    public function claimForProcessing(int $distributionId): ?ArticleDistribution
    {
        $candidate = ArticleDistribution::query()
            ->select(['id', 'distribution_channel_id'])
            ->whereKey($distributionId)
            ->first();
        if (! $candidate) {
            return null;
        }

        return DB::transaction(function () use ($candidate): ?ArticleDistribution {
            $channel = DistributionChannel::query()
                ->whereKey((int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            $distribution = ArticleDistribution::query()
                ->whereKey((int) $candidate->id)
                ->where('distribution_channel_id', (int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            if (! $distribution || (string) $distribution->status !== 'queued') {
                return null;
            }

            if (! $channel || (string) $channel->status !== DistributionChannel::STATUS_ACTIVE) {
                $distribution->forceFill([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => __('admin.distribution.delete.channel_unavailable_error'),
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
        return DB::transaction(function () use ($channel): int {
            $lockedChannel = DistributionChannel::query()
                ->whereKey((int) $channel->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedChannel || (string) $lockedChannel->status !== DistributionChannel::STATUS_ACTIVE) {
                return 0;
            }

            $count = 0;
            ArticleDistribution::query()
                ->with('article:id,status')
                ->where('distribution_channel_id', (int) $lockedChannel->id)
                ->where('action', '!=', 'delete')
                ->where('status', '!=', 'sending')
                ->whereHas('article', function ($query): void {
                    $query->whereIn('status', ['published', 'private']);
                })
                ->orderBy('id')
                ->chunkById(100, function ($distributions) use (&$count, $lockedChannel): void {
                    foreach ($distributions as $distribution) {
                        if (! $distribution instanceof ArticleDistribution || ! $distribution->article) {
                            continue;
                        }

                        $distribution->forceFill([
                            'action' => 'update',
                            'status' => 'queued',
                            'last_error_message' => null,
                            'next_retry_at' => now(),
                            'idempotency_key' => $this->idempotencyKey((int) $distribution->article_id, (int) $lockedChannel->id, 'update'),
                        ])->save();
                        ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                            ->onQueue('distribution')
                            ->afterCommit();
                        $count++;
                    }
                });

            if ($count > 0) {
                $this->log(
                    'info',
                    '目标站点内容刷新已入队',
                    (int) $lockedChannel->id,
                    null,
                    null,
                    ['event' => 'target.content_refresh_queued', 'count' => $count]
                );
            }

            return $count;
        });
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

                $existingMeta = is_array($distribution->remote_meta) ? $distribution->remote_meta : [];
                $responseMeta = is_array($response['remote_meta'] ?? null) ? $response['remote_meta'] : [];
                $distribution->forceFill([
                    'status' => 'synced',
                    'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
                    'remote_url' => $action === 'delete'
                        ? null
                        : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
                    'remote_meta' => array_replace($existingMeta, $responseMeta),
                    'last_error_message' => null,
                ])->save();

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
            $distribution = ArticleDistribution::query()
                ->whereKey((int) $candidate->id)
                ->where('distribution_channel_id', (int) $candidate->distribution_channel_id)
                ->lockForUpdate()
                ->first();
            if (! $channel || ! $distribution) {
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
        $result = DB::transaction(function () use ($article, $trigger): Article|ArticleRiskGateException {
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

            try {
                $this->articleRiskGate->check($lockedArticle, $trigger);
            } catch (ArticleRiskGateException $exception) {
                return $exception;
            }

            return clone $lockedArticle;
        });

        if ($result instanceof ArticleRiskGateException) {
            throw $result;
        }

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
