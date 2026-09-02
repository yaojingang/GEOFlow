<?php

namespace App\Jobs;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
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
        public readonly string $systemPurpose,
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
            $coordinator->markFailed($this->knowledgeBaseId, $this->syncToken, 'knowledge_embedding_profile_incompatible');

            return;
        }
        try {
            $syncService->finalizeStagingSync(
                $this->knowledgeBaseId,
                $this->syncToken,
                SystemAiIdentity::fromKnowledgeIndexPurpose($this->systemPurpose),
            );
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $coordinator->markFailed($this->knowledgeBaseId, $this->syncToken, $exception->getMessage());
        }
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
