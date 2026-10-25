<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshHostedSitePermalinkUrls implements ShouldQueue
{
    use Queueable;

    private const BATCH_SIZE = 200;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $channelId,
        private readonly int $policyRevision,
        private readonly int $afterDistributionId = 0,
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'hosted-site-permalink:'.$this->channelId,
            'article-permalink-revision:'.$this->policyRevision,
            'article-distribution-after:'.$this->afterDistributionId,
        ];
    }

    public function handle(HostedSiteUrlGenerator $urls): void
    {
        DB::transaction(fn () => $this->refreshBatch($urls), 3);
    }

    private function refreshBatch(HostedSiteUrlGenerator $urls): void
    {
        $channel = DistributionChannel::query()->lockForUpdate()->with('hostedSiteProfile')->find($this->channelId);
        if (! $channel instanceof DistributionChannel || ! $this->isCurrentRevision($channel)) {
            return;
        }

        $distributions = ArticleDistribution::query()
            ->where('distribution_channel_id', $channel->id)
            ->where('status', 'synced')
            ->where('action', '!=', 'delete')
            ->where('id', '>', $this->afterDistributionId)
            ->with([
                'article' => fn ($article) => $article->withTrashed()->select(['id', 'slug', 'category_id', 'created_at']),
                'article.category:id,slug',
            ])
            ->orderBy('id')
            ->limit(self::BATCH_SIZE + 1)
            ->get();
        $hasMore = $distributions->count() > self::BATCH_SIZE;
        $distributions = $distributions->take(self::BATCH_SIZE);
        if ($distributions->isEmpty()) {
            return;
        }

        $freshChannel = DistributionChannel::query()->with('hostedSiteProfile')->find($channel->id);
        if (! $freshChannel instanceof DistributionChannel || ! $this->isCurrentRevision($freshChannel)) {
            return;
        }

        foreach ($distributions as $distribution) {
            if ($distribution->article instanceof Article) {
                $distribution->forceFill([
                    'remote_url' => $urls->article($freshChannel, $distribution->article),
                ])->save();
            }
        }

        if ($hasMore) {
            self::dispatch(
                $this->channelId,
                $this->policyRevision,
                (int) $distributions->last()->id,
            )->afterCommit();
        }
    }

    private function isCurrentRevision(DistributionChannel $channel): bool
    {
        $settings = is_array($channel->site_settings) ? $channel->site_settings : [];
        $policy = ArticlePermalinkPolicy::fromRaw($settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);

        return $channel->isHostedSite() && $channel->status !== 'deleting' && $policy->revision === $this->policyRevision;
    }
}
