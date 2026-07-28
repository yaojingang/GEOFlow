<?php

namespace App\Jobs;

use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class FinalizeKnowledgeChunkSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $knowledgeBaseId,
        public readonly string $syncToken,
    ) {}

    public function tags(): array
    {
        return ['knowledge', 'knowledge_base:'.$this->knowledgeBaseId];
    }

    public function handle(KnowledgeChunkSyncService $syncService): void
    {
        $syncService->finalizeStagingSync($this->knowledgeBaseId, $this->syncToken);
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeChunkSyncCoordinator::class)->markFailed(
            $this->knowledgeBaseId,
            $this->syncToken,
            $exception?->getMessage() ?: 'Knowledge chunk finalization failed.',
        );
    }
}
