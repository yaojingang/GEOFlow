<?php

namespace App\Jobs;

use App\Services\LinkPool\LinkPoolThemeAdaptationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class AdaptLinkPoolTheme implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $adaptationId) {}

    public function handle(LinkPoolThemeAdaptationService $adaptations): void
    {
        $adaptations->run($this->adaptationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(LinkPoolThemeAdaptationService::class)->recoverFailedTask($this->adaptationId);
    }
}
