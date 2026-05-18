<?php

namespace App\Jobs;

use App\Models\GeoCitationSource;
use App\Services\Geo\GeoReferencePageCrawler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GeoBatchCrawlCitationSourcesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $sourceIds
     */
    public function __construct(
        public readonly int $organizationId,
        public readonly array $sourceIds
    ) {}

    public function handle(GeoReferencePageCrawler $crawler): void
    {
        GeoCitationSource::query()
            ->where('organization_id', $this->organizationId)
            ->whereIn('id', $this->sourceIds)
            ->each(function (GeoCitationSource $source) use ($crawler): void {
                try {
                    $snapshot = $crawler->crawl($source);
                    $source->forceFill([
                        'title' => $snapshot->title ?: $source->title,
                        'status' => $snapshot->crawl_status === 'succeeded' ? 'crawled' : 'crawl_failed',
                        'metadata' => array_merge((array) $source->metadata, [
                            'last_crawl_status' => $snapshot->crawl_status,
                            'last_crawl_snapshot_id' => $snapshot->id,
                            'last_crawl_error' => $snapshot->error_message,
                        ]),
                    ])->save();
                } catch (Throwable $exception) {
                    $source->forceFill([
                        'status' => 'crawl_failed',
                        'metadata' => array_merge((array) $source->metadata, [
                            'last_crawl_status' => 'failed',
                            'last_crawl_error' => mb_substr($exception->getMessage(), 0, 1000),
                        ]),
                    ])->save();
                }
            });
    }
}
