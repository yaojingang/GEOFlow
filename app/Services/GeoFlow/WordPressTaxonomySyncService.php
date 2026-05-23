<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;

class WordPressTaxonomySyncService
{
    /**
     * @param  array<string,mixed>  $payload
     * @return list<int>
     */
    public function categoryIds(DistributionChannel $channel, array $payload): array
    {
        return [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<int>
     */
    public function tagIds(DistributionChannel $channel, array $payload): array
    {
        return [];
    }
}
