<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;

interface DistributionPublisherInterface
{
    /**
     * @return array<string,mixed>
     */
    public function health(DistributionChannel $channel): array;

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function publish(ArticleDistribution $distribution, array $payload): array;

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function update(ArticleDistribution $distribution, array $payload): array;

    /**
     * @return array<string,mixed>
     */
    public function delete(ArticleDistribution $distribution): array;

    /**
     * @return array<string,mixed>
     */
    public function syncSiteSettings(DistributionChannel $channel): array;
}
