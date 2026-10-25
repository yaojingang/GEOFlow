<?php

namespace App\Jobs;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionChannelOperationLeaseService;
use App\Services\Site\LinkPoolRecommendationService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshLinkPoolBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $siteKey, public readonly int $articleId) {}

    public function uniqueId(): string
    {
        return $this->siteKey.':'.$this->articleId;
    }

    public function handle(
        LinkPoolRecommendationService $recommendations,
        LinkPoolSiteService $sites,
        DistributionChannelOperationLeaseService $leases,
    ): void {
        $profile = $sites->profile($this->siteKey);
        if ($this->siteKey !== 'primary') {
            $channel = $profile?->channel;
            if (! $channel instanceof DistributionChannel) {
                return;
            }
            try {
                $leases->run($channel, 'link_pool_refresh', function () use ($recommendations): void {
                    $recommendations->refresh($this->siteKey, $this->articleId);
                });
            } catch (DistributionChannelDeletionBlocked) {
                return;
            }

            return;
        }

        $recommendations->refresh($this->siteKey, $this->articleId);
    }
}
