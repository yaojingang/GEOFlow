<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionPublisherManager;
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
}
