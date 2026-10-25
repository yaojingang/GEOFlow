<?php

namespace App\Services\HostedSites;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Services\Site\ArticlePermalinkService;
use App\Support\Site\ArticlePermalinkPolicy;

class HostedSiteUrlGenerator
{
    public function __construct(private readonly ArticlePermalinkService $articlePermalinks) {}

    public function article(DistributionChannel|HostedSiteProfile $site, Article $article): string
    {
        if ($site instanceof HostedSiteProfile) {
            $site->loadMissing('channel');
            $channel = $site->channel;
            $hostname = (string) $site->hostname;
        } else {
            $channel = $site;
            $site->loadMissing('hostedSiteProfile');
            $hostname = (string) ($site->hostedSiteProfile?->hostname ?? $site->domain);
        }

        $settings = is_array($channel?->site_settings) ? $channel->site_settings : [];
        $policy = ArticlePermalinkPolicy::fromRaw($settings[ArticlePermalinkPolicy::SETTING_KEY] ?? null);

        return 'https://'.$hostname.$this->articlePermalinks->path($article, $policy);
    }
}
