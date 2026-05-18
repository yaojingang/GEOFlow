<?php

namespace App\Jobs;

use App\Models\GeoArticleDraft;
use App\Models\GeoTask;
use App\Services\Geo\GeoPostPublishRetestRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GeoPostPublishRetestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $organizationId,
        public readonly int $taskId,
        public readonly int $draftId
    ) {}

    public function handle(GeoPostPublishRetestRunner $runner): void
    {
        $task = GeoTask::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->taskId)
            ->first();
        $draft = GeoArticleDraft::query()
            ->where('organization_id', $this->organizationId)
            ->whereKey($this->draftId)
            ->first();

        if (! $task || ! $draft) {
            return;
        }

        $runner->run($task, $draft);
    }
}
