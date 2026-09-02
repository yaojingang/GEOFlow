<?php

namespace App\Jobs;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
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
        public readonly string $systemPurpose,
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
        $identity = SystemAiIdentity::fromKnowledgeIndexPurpose($this->systemPurpose);
        if (! $coordinator->isCurrent($this->knowledgeBaseId, $this->syncToken)) {
            $coordinator->markFailed($this->knowledgeBaseId, $this->syncToken, 'knowledge_embedding_profile_incompatible');

            return;
        }

        $knowledgeBase = KnowledgeBase::query()->whereKey($this->knowledgeBaseId)->first(['id', 'content']);
        if (! $knowledgeBase) {
            return;
        }

        try {
            $syncService->prepareStagingSync(
                $this->knowledgeBaseId,
                (string) $knowledgeBase->content,
                $this->syncToken,
                $identity,
            );
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $coordinator->markFailed($this->knowledgeBaseId, $this->syncToken, $exception->getMessage());

            return;
        }

        if (! $coordinator->isCurrent($this->knowledgeBaseId, $this->syncToken)) {
            $syncService->discardStagingSync($this->knowledgeBaseId, $this->syncToken);

            return;
        }

        EmbedKnowledgeChunkBatchJob::dispatch(
            $this->knowledgeBaseId,
            $this->syncToken,
            0,
            $this->systemPurpose,
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
