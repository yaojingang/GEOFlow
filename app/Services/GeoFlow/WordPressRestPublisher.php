<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use RuntimeException;

class WordPressRestPublisher implements DistributionPublisherInterface
{
    public function health(DistributionChannel $channel): array
    {
        throw new RuntimeException('WordPress 分发渠道尚未实现。');
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        throw new RuntimeException('WordPress 分发渠道尚未实现。');
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        throw new RuntimeException('WordPress 分发渠道尚未实现。');
    }

    public function delete(ArticleDistribution $distribution): array
    {
        throw new RuntimeException('WordPress 分发渠道尚未实现。');
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        throw new RuntimeException('WordPress 分发渠道尚未实现。');
    }
}
