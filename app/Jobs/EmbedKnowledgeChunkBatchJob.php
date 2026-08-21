<?php

namespace App\Jobs;

use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EmbedKnowledgeChunkBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public readonly int $knowledgeBaseId,
        public readonly string $syncToken,
        public readonly int $afterRowId = 0,
        public readonly bool $requireRealEmbedding = false,
    ) {}

    public function tags(): array
    {
        return ['knowledge', 'knowledge_base:'.$this->knowledgeBaseId];
    }

    public function handle(
        KnowledgeChunkSyncCoordinator $coordinator,
        KnowledgeChunkSyncService $syncService,
    ): void {
        if (! $coordinator->isCurrent($this->knowledgeBaseId, $this->syncToken)) {
            return;
        }

        $result = $syncService->embedStagingBatch(
            $this->knowledgeBaseId,
            $this->syncToken,
            $this->afterRowId,
            $this->requireRealEmbedding,
        );

        if ($result === null || $result['done']) {
            FinalizeKnowledgeChunkSyncJob::dispatch(
                $this->knowledgeBaseId,
                $this->syncToken,
            )->onQueue('knowledge');

            return;
        }

        self::dispatch(
            $this->knowledgeBaseId,
            $this->syncToken,
            $result['last_id'],
            $this->requireRealEmbedding,
        )->onQueue('knowledge');
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeChunkSyncCoordinator::class)->markFailed(
            $this->knowledgeBaseId,
            $this->syncToken,
            $exception?->getMessage() ?: 'Knowledge embedding failed.',
        );
    }
}
