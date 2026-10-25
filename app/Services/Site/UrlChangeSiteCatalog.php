<?php

namespace App\Services\Site;

use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;

final class UrlChangeSiteCatalog
{
    public function sites(string $operation, ?int $targetId, bool $requireAvailable = true): array
    {
        $sites = [];
        if ($operation !== 'hosted') {
            $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)->value('setting_value'));
            $sites[] = ['key' => 'primary', 'label' => __('url_change.primary'), 'base_url' => rtrim((string) config('geoflow.site_url', config('app.url')), '/'), 'policy' => $policy->toArray()];
        }
        if ($operation !== 'primary') {
            $channels = DistributionChannel::query()->whereHas('hostedSiteProfile')->with('hostedSiteProfile');
            if ($operation === 'hosted') {
                $channels->whereKey($targetId);
            } elseif (in_array($operation, ['category', 'article_category'], true)) {
                $channels->whereHas('hostedSiteProfile.assignments.article', function ($query) use ($operation, $targetId): void {
                    $query->withTrashed()->where($operation === 'category' ? 'articles.category_id' : 'articles.id', $targetId);
                });
            }
            foreach ($channels->orderBy('id')->get() as $channel) {
                abort_if($requireAvailable && ($channel->status === 'deleting' || $channel->hostedSiteProfile->serving_status === HostedSiteProfile::SERVING_ARCHIVED), 409, __('url_change.errors.site_unavailable'));
                $policy = ArticlePermalinkPolicy::fromRaw(($channel->site_settings ?? [])[ArticlePermalinkPolicy::SETTING_KEY] ?? null);
                $sites[] = ['key' => 'hosted:'.$channel->id, 'label' => $channel->hostedSiteProfile->hostname, 'base_url' => 'https://'.$channel->hostedSiteProfile->hostname, 'channel_id' => (int) $channel->id, 'profile_id' => (int) $channel->hostedSiteProfile->id, 'policy' => $policy->toArray()];
            }
        }
        abort_if($sites === [], 404);
        $environment = hash('sha256', json_encode([
            config('geoflow.admin_base_path'), config('app.timezone'), ArticlePermalinkPattern::reservedFirstSegments(),
        ], JSON_THROW_ON_ERROR));
        foreach ($sites as &$site) {
            $site['environment'] = $environment;
        }
        unset($site);

        return $sites;
    }
}
