<?php

namespace App\Jobs;

use App\Services\Topics\TopicSitemapManifest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class BuildTopicSitemapManifest implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly string $siteKey) {}

    public function handle(TopicSitemapManifest $manifest): void
    {
        if (! $manifest->buildStep($this->siteKey)) {
            self::dispatch($this->siteKey)->onQueue('geoflow')->delay(now()->addSecond());
        }
    }
}
