<?php

namespace App\Jobs;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
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
        public readonly string $systemPurpose = '',
        public readonly bool $requireRealEmbedding = false,
        public readonly string $executionToken = '',
        public readonly int $dispatchOrdinal = 1,
    ) {}

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

        try {
            $result = $syncService->embedStagingBatch(
                $this->knowledgeBaseId,
                $this->syncToken,
                $this->afterRowId,
                $identity,
                $this->requireRealEmbedding,
                $this->executionToken(),
                $this->queueAttempt(),
                $this->dispatchOrdinal(),
            );
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $coordinator->markFailed($this->knowledgeBaseId, $this->syncToken, $exception->getMessage());

            return;
        }

        if ($result === null || $result['done']) {
            FinalizeKnowledgeChunkSyncJob::dispatch(
                $this->knowledgeBaseId,
                $this->syncToken,
                $this->systemPurpose,
            )->onQueue('knowledge');

            return;
        }

        self::dispatch(
            $this->knowledgeBaseId,
            $this->syncToken,
            $result['last_id'],
            $this->systemPurpose,
            $this->requireRealEmbedding,
            (string) Str::uuid(),
            $this->dispatchOrdinal() + 1,
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

    private function queueAttempt(): int
    {
        return $this->job === null ? 1 : max(1, $this->attempts());
    }

    private function executionToken(): string
    {
        return isset($this->executionToken) && $this->executionToken !== ''
            ? $this->executionToken
            : (string) Str::uuid();
    }

    private function dispatchOrdinal(): int
    {
        return isset($this->dispatchOrdinal) ? max(1, $this->dispatchOrdinal) : 1;
    }
}
