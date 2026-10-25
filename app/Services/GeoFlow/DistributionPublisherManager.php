<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\Distribution\PlatformWeb\BrowserExtensionPublisher;
use RuntimeException;

class DistributionPublisherManager
{
    public function __construct(
        private readonly GeoFlowAgentPublisher $geoFlowAgentPublisher,
        private readonly WordPressRestPublisher $wordPressRestPublisher,
        private readonly GenericHttpApiPublisher $genericHttpApiPublisher,
        private readonly HostedSitePublisher $hostedSitePublisher,
        private readonly BrowserExtensionPublisher $browserExtensionPublisher,
    ) {}

    public function forChannel(DistributionChannel $channel): DistributionPublisherInterface
    {
        return match ($channel->channelType()) {
            'geoflow_agent' => $this->geoFlowAgentPublisher,
            'wordpress_rest' => $this->wordPressRestPublisher,
            'generic_http_api' => $this->genericHttpApiPublisher,
            DistributionChannel::TYPE_HOSTED_SITE => $this->hostedSitePublisher,
            DistributionChannel::TYPE_PLATFORM_WEB => $this->browserExtensionPublisher,
            default => throw new RuntimeException('不支持的分发渠道类型：'.(string) $channel->channel_type),
        };
    }
}
