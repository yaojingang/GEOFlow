<?php

namespace App\Jobs;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareKnowledgeChunkSyncJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $knowledgeBaseId,
        public readonly string $syncToken,
        public readonly bool $requireRealEmbedding = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->knowledgeBaseId.':'.$this->syncToken;
    }

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

        $knowledgeBase = KnowledgeBase::query()->whereKey($this->knowledgeBaseId)->first(['id', 'content']);
        if (! $knowledgeBase) {
            return;
        }

        $syncService->prepareStagingSync(
            $this->knowledgeBaseId,
            (string) $knowledgeBase->content,
            $this->syncToken,
        );

        if (! $coordinator->isCurrent($this->knowledgeBaseId, $this->syncToken)) {
            $syncService->discardStagingSync($this->knowledgeBaseId, $this->syncToken);

            return;
        }

        EmbedKnowledgeChunkBatchJob::dispatch(
            $this->knowledgeBaseId,
            $this->syncToken,
            0,
            $this->requireRealEmbedding,
        )->onQueue('knowledge');
    }

    public function failed(?Throwable $exception = null): void
    {
        app(KnowledgeChunkSyncCoordinator::class)->markFailed(
            $this->knowledgeBaseId,
            $this->syncToken,
            $exception?->getMessage() ?: 'Knowledge chunk preparation failed.',
        );
    }
}
