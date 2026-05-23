<?php

namespace App\Services\GeoFlow;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\Task;
use Illuminate\Support\Collection;
use Throwable;

class DistributionOrchestrator
{
    public function __construct(
        private readonly DistributionPayloadBuilder $payloadBuilder,
        private readonly DistributionPublisherManager $publisherManager
    ) {}

    /**
     * @param  list<int>  $channelIds
     */
    public function syncTaskChannels(Task $task, array $channelIds): void
    {
        $ids = DistributionChannel::query()
            ->whereIn('id', $channelIds)
            ->where('status', 'active')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $task->distributionChannels()->syncWithPivotValues($ids, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
        ]);
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

            $articleModel->loadMissing('task.distributionChannels');
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

            $payload = $this->payloadBuilder->build($articleModel);
            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

            foreach ($channels as $channel) {
                $distribution = ArticleDistribution::query()->updateOrCreate(
                    [
                        'article_id' => (int) $articleModel->id,
                        'distribution_channel_id' => (int) $channel->id,
                        'action' => $action,
                    ],
                    [
                        'status' => 'queued',
                        'next_retry_at' => now(),
                        'payload_hash' => $payloadHash,
                        'idempotency_key' => $this->idempotencyKey((int) $articleModel->id, (int) $channel->id, $action),
                    ]
                );

                $this->log('info', '文章已进入分发队列', $channel->id, $distribution->id, $articleModel->id, [
                    'event' => 'distribution.queued',
                ]);
                ProcessArticleDistributionJob::dispatch((int) $distribution->id)
                    ->onQueue('distribution')
                    ->afterCommit();
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
        return $this->publisherManager->forChannel($channel)->health($channel);
    }

    public function process(ArticleDistribution $distribution): void
    {
        $distribution->loadMissing(['article', 'channel']);
        $article = $distribution->article;
        $channel = $distribution->channel;
        if (! $article || ! $channel) {
            throw new \RuntimeException('分发记录缺少文章或渠道');
        }

        $distribution->forceFill([
            'status' => 'sending',
            'attempt_count' => (int) $distribution->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error_message' => null,
        ])->save();

        $payload = $this->payloadBuilder->build($article);
        if ((string) $distribution->action === 'update') {
            $payload['event'] = 'article.update';
        }

        $publisher = $this->publisherManager->forChannel($channel);
        $response = match ((string) $distribution->action) {
            'update' => $publisher->update($distribution, $payload),
            'delete' => $publisher->delete($distribution),
            default => $publisher->publish($distribution, $payload),
        };
        $distribution->forceFill([
            'status' => 'synced',
            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
            'remote_url' => (string) $distribution->action === 'delete'
                ? null
                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
            'last_error_message' => null,
        ])->save();

        $this->log('info', '文章分发成功', $channel->id, $distribution->id, $article->id, $response);
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
        $count = 0;

        ArticleDistribution::query()
            ->with('article:id,status')
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('action', '!=', 'delete')
            ->whereHas('article', function ($query): void {
                $query->whereIn('status', ['published', 'private']);
            })
            ->orderBy('id')
            ->chunkById(100, function ($distributions) use (&$count, $channel): void {
                foreach ($distributions as $distribution) {
                    if (! $distribution instanceof ArticleDistribution || ! $distribution->article) {
                        continue;
                    }

                    $distribution->forceFill([
                        'action' => 'update',
                        'status' => 'queued',
                        'last_error_message' => null,
                        'next_retry_at' => now(),
                        'idempotency_key' => $this->idempotencyKey((int) $distribution->article_id, (int) $channel->id, 'update'),
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
                (int) $channel->id,
                null,
                null,
                ['event' => 'target.content_refresh_queued', 'count' => $count]
            );
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

        $payload = $action === 'delete' ? [] : $this->payloadBuilder->build($article);
        if ($action === 'update') {
            $payload['event'] = 'article.update';
        }
        $payloadHash = $action === 'delete'
            ? null
            : hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

        $distribution->forceFill([
            'action' => $action,
            'status' => 'sending',
            'attempt_count' => (int) $distribution->attempt_count + 1,
            'last_attempt_at' => now(),
            'last_error_message' => null,
            'payload_hash' => $payloadHash,
            'idempotency_key' => $this->idempotencyKey((int) $article->id, (int) $channel->id, $action),
        ])->save();

        $publisher = $this->publisherManager->forChannel($channel);
        $response = $action === 'delete'
            ? $publisher->delete($distribution)
            : $publisher->update($distribution, $payload);

        $distribution->forceFill([
            'status' => 'synced',
            'remote_id' => is_scalar($response['remote_id'] ?? null) ? (string) $response['remote_id'] : $distribution->remote_id,
            'remote_url' => $action === 'delete'
                ? null
                : (is_scalar($response['remote_url'] ?? null) ? (string) $response['remote_url'] : $distribution->remote_url),
            'last_error_message' => null,
        ])->save();

        $this->log(
            'info',
            $action === 'delete' ? '远端文章副本已删除' : '远端文章已更新',
            (int) $channel->id,
            (int) $distribution->id,
            (int) $article->id,
            ['event' => 'article.'.$action, 'remote_result' => $response]
        );
    }
}
