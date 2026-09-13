<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshHostedSitePermalinkUrls implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        private readonly int $channelId,
        private readonly int $policyRevision,
    ) {}

    /** @return list<string> */
    public function tags(): array
    {
        return [
            'hosted-site-permalink:'.$this->channelId,
            'article-permalink-revision:'.$this->policyRevision,
        ];
    }

    public function handle(HostedSiteUrlGenerator $urls): void
    {
        $channel = DistributionChannel::query()->with('hostedSiteProfile')->find($this->channelId);
        if (! $channel instanceof DistributionChannel || ! $this->isCurrentRevision($channel)) {
            return;
        }

        ArticleDistribution::query()
            ->where('distribution_channel_id', $channel->id)
            ->where('status', 'synced')
            ->where('action', '!=', 'delete')
            ->with('article.category')
            ->chunkById(200, function ($distributions) use ($channel, $urls): bool {
                $freshChannel = DistributionChannel::query()->with('hostedSiteProfile')->find($channel->id);
                if (! $freshChannel instanceof DistributionChannel || ! $this->isCurrentRevision($freshChannel)) {
                    return false;
                }

                foreach ($distributions as $distribution) {
                    if ($distribution->article instanceof Article) {
                        $distribution->forceFill([
                            'remote_url' => $urls->article($freshChannel, $distribution->article),
                        ])->save();
                    }
                }

                return true;
            });
    }

    private function isCurrentRevision(DistributionChannel $channel): bool
    {
        $settings = is_array($channel->site_settings) ? $channel->site_settings : [];
        $policy = ArticlePermalinkPolicy::fromRaw($settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);

        return $channel->isHostedSite() && $policy->revision === $this->policyRevision;
    }
}
