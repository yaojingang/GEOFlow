<?php

namespace App\Jobs;

use App\Services\Site\LinkPoolMetricsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecordLinkPoolHtmlServed implements ShouldQueue
{
    use Queueable;

    /** @param list<int> $placementIds */
    public function __construct(public readonly array $placementIds, public readonly string $deliveryId) {}

    public function handle(LinkPoolMetricsService $metrics): void
    {
        $metrics->recordHtmlServed($this->placementIds, $this->deliveryId);
    }
}
