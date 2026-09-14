<?php

namespace App\Jobs;

use App\Services\Site\SitemapManifest;
use App\Services\Site\UrlChangeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BuildSitemapManifest implements ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public function __construct(public readonly string $scope) {}

    public function handle(SitemapManifest $manifests, UrlChangeService $changes): void
    {
        $site = $changes->sites($this->scope === 'primary' ? 'primary' : 'hosted', $this->scope === 'primary' ? null : (int) substr($this->scope, 7))[0];
        if (! $manifests->buildStep($site)) {
            self::dispatch($this->scope);
        }
    }
}
