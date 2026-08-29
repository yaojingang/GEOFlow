<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TaskDistributionChannelSelector
{
    public const STRATEGY_BROADCAST = 'broadcast';

    public const STRATEGY_ROUND_ROBIN = 'round_robin';

    public const STRATEGY_RANDOM_BALANCED = 'random_balanced';

    /**
     * @return list<string>
     */
    public static function strategies(): array
    {
        return [
            self::STRATEGY_BROADCAST,
            self::STRATEGY_ROUND_ROBIN,
            self::STRATEGY_RANDOM_BALANCED,
        ];
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     * @return Collection<int, DistributionChannel>
     */
    public function selectChannelsForArticle(Article $article, Collection $channels, string $action = 'publish'): Collection
    {
        $orderedChannels = collect($channels->all())
            ->filter(static fn ($channel): bool => $channel instanceof DistributionChannel)
            ->sortBy(static fn (DistributionChannel $channel): string => sprintf('%010d-%010d', (int) ($channel->pivot?->sort_order ?? 0), (int) $channel->id))
            ->values();

        if ($orderedChannels->isEmpty() || ! $article->task_id) {
            return collect([]);
        }

        $strategy = $this->normalizeStrategy((string) ($article->task?->distribution_strategy ?? self::STRATEGY_BROADCAST));
        if ($strategy === self::STRATEGY_BROADCAST && $action === 'publish') {
            return $orderedChannels;
        }

        $existingChannelIds = $this->existingChannelIdsForArticle((int) $article->id, $action);
        if (! empty($existingChannelIds)) {
            return $this->channelsByIds($orderedChannels, $existingChannelIds);
        }

        if ($strategy === self::STRATEGY_BROADCAST) {
            return $orderedChannels;
        }

        if ($strategy === self::STRATEGY_RANDOM_BALANCED) {
            return $this->selectRandomBalanced((int) $article->task_id, $orderedChannels);
        }

        return $this->selectRoundRobin((int) $article->task_id, $orderedChannels);
    }

    public function normalizeStrategy(string $strategy): string
    {
        return in_array($strategy, self::strategies(), true) ? $strategy : self::STRATEGY_BROADCAST;
    }

    /**
     * @return list<int>
     */
    private function existingChannelIdsForArticle(int $articleId, string $action): array
    {
        $queryAction = $action === 'publish' ? 'publish' : $action;

        $ids = ArticleDistribution::query()
            ->where('article_id', $articleId)
            ->where('action', $queryAction)
            ->pluck('distribution_channel_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if (! empty($ids) || $action === 'publish') {
            return $ids;
        }

        return ArticleDistribution::query()
            ->where('article_id', $articleId)
            ->where('action', 'publish')
            ->pluck('distribution_channel_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     * @param  list<int>  $ids
     * @return Collection<int, DistributionChannel>
     */
    private function channelsByIds(Collection $channels, array $ids): Collection
    {
        $idSet = collect($ids)->mapWithKeys(static fn (int $id): array => [$id => true]);

        return $channels
            ->filter(static fn (DistributionChannel $channel): bool => isset($idSet[(int) $channel->id]))
            ->values();
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     * @return Collection<int, DistributionChannel>
     */
    private function selectRoundRobin(int $taskId, Collection $channels): Collection
    {
        if ($channels->isEmpty()) {
            return collect([]);
        }

        return DB::transaction(function () use ($taskId, $channels): Collection {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'distribution_cursor']);

            $cursor = max(0, (int) ($task?->distribution_cursor ?? 0));
            $channel = $channels->values()->get($cursor % max(1, $channels->count()));

            if ($task) {
                $task->forceFill([
                    'distribution_cursor' => $cursor + 1,
                ])->save();
            }

            return $channel instanceof DistributionChannel ? collect([$channel]) : collect([]);
        });
    }

    /**
     * @param  Collection<int, DistributionChannel>  $channels
     * @return Collection<int, DistributionChannel>
     */
    private function selectRandomBalanced(int $taskId, Collection $channels): Collection
    {
        if ($channels->isEmpty()) {
            return collect([]);
        }

        $channelIds = $channels->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $counts = ArticleDistribution::query()
            ->join('articles', 'articles.id', '=', 'article_distributions.article_id')
            ->where('articles.task_id', $taskId)
            ->where('article_distributions.action', 'publish')
            ->whereIn('article_distributions.distribution_channel_id', $channelIds)
            ->selectRaw('article_distributions.distribution_channel_id, COUNT(*) as aggregate_count')
            ->groupBy('article_distributions.distribution_channel_id')
            ->pluck('aggregate_count', 'distribution_channel_id')
            ->mapWithKeys(static fn ($count, $id): array => [(int) $id => (int) $count]);

        $minCount = $channels
            ->map(static fn (DistributionChannel $channel): int => (int) ($counts[(int) $channel->id] ?? 0))
            ->min() ?? 0;

        $candidates = $channels
            ->filter(static fn (DistributionChannel $channel): bool => (int) ($counts[(int) $channel->id] ?? 0) === $minCount)
            ->values();

        if ($candidates->isEmpty()) {
            return collect([]);
        }

        return collect([$candidates->random()]);
    }
}
