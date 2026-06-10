<?php

namespace App\Jobs;

use App\Models\SiteThemeReplication;
use App\Services\Admin\SiteThemeReplication\ThemeReplicationPipelineService;
use App\Services\Admin\SiteThemeReplicationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IterateSiteThemeReplicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $replicationId,
        public readonly string $feedback,
    ) {}

    public function handle(ThemeReplicationPipelineService $pipeline): void
    {
        $pipeline->iterate($this->replicationId, $this->feedback);
    }

    public function failed(?Throwable $exception = null): void
    {
        $replication = SiteThemeReplication::query()->whereKey($this->replicationId)->first();
        if (! $replication || (string) $replication->status === SiteThemeReplication::STATUS_FAILED) {
            return;
        }

        $message = $exception?->getMessage() ?: __('admin.theme_replication.error.job_failed');
        $replication->forceFill([
            'status' => SiteThemeReplication::STATUS_FAILED,
            'error_message' => mb_substr($message, 0, 2000),
        ])->save();

        app(SiteThemeReplicationService::class)->log($replication, 'error', 'failed', $message);
    }
}
