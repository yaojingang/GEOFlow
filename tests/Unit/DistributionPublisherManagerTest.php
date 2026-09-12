<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\GenericHttpApiPublisher;
use App\Services\GeoFlow\GeoFlowAgentPublisher;
use App\Services\GeoFlow\WordPressRestPublisher;
use Tests\TestCase;

class DistributionPublisherManagerTest extends TestCase
{
    public function test_it_resolves_geoflow_agent_publisher_by_default(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'geoflow_agent']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(GeoFlowAgentPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_wordpress_rest_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'wordpress_rest']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(WordPressRestPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_generic_http_api_publisher(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'generic_http_api']);
        $manager = app(DistributionPublisherManager::class);

        $this->assertInstanceOf(GenericHttpApiPublisher::class, $manager->forChannel($channel));
    }

    public function test_it_resolves_platform_web_channel_config_from_catalog(): void
    {
        $channel = new DistributionChannel([
            'channel_type' => DistributionChannel::TYPE_PLATFORM_WEB,
            'channel_config' => [
                'platform' => 'toutiao',
                'manual_publication_account_id' => 7,
            ],
        ]);

        $this->assertSame('platform_web', $channel->channelType());
        $this->assertTrue($channel->isPlatformWeb());

        $this->assertSame([
            'platform' => 'toutiao',
            'manual_publication_account_id' => 7,
            'execution_mode' => 'draft',
            'editor_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/publish',
            'append_source_link' => false,
        ], $channel->resolvedPlatformWebConfig());
    }

    public function test_it_falls_back_to_default_platform_for_unsupported_platform_web_config(): void
    {
        $channel = new DistributionChannel([
            'channel_type' => 'platform_web',
            'channel_config' => [
                'platform' => 'weird',
                'append_source_link' => '1',
                'editor_url' => 'https://mp.sohu.com/custom',
            ],
        ]);

        $resolved = $channel->resolvedPlatformWebConfig();

        $this->assertSame('toutiao', $resolved['platform']);
        $this->assertSame('draft', $resolved['execution_mode']);
        $this->assertTrue($resolved['append_source_link']);
        $this->assertSame('https://mp.sohu.com/custom', $resolved['editor_url']);
    }

    public function test_it_normalizes_unknown_channel_type_to_geoflow_agent(): void
    {
        $channel = new DistributionChannel(['channel_type' => 'garbage']);

        $this->assertSame('geoflow_agent', $channel->channelType());
        $this->assertTrue($channel->isGeoFlowAgent());
    }
}
