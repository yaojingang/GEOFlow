<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;

class WordPressMediaSyncService
{
    /**
     * @param  array<string,mixed>  $payload
     */
    public function rewriteContentImages(DistributionChannel $channel, array $payload, string $contentHtml): string
    {
        return $contentHtml;
    }
}
