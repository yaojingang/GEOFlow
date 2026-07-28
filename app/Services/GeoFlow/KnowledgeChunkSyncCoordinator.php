<?php

namespace App\Services\GeoFlow;

use App\Jobs\PrepareKnowledgeChunkSyncJob;
use App\Models\KnowledgeBase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class KnowledgeChunkSyncCoordinator
{
    public function request(
        int $knowledgeBaseId,
        bool $requireRealEmbedding = false,
        bool $force = false,
    ): bool {
        $sync = $this->reserve(
            $knowledgeBaseId,
            $requireRealEmbedding,
            $force,
        );
        if ($sync === null) {
            return false;
        }

        $this->dispatchReserved($knowledgeBaseId, $sync);

        return true;
    }

    public function recoverStale(int $staleSeconds = 600, int $limit = 50): int
    {
        $cutoff = now()->subSeconds(max(60, $staleSeconds));
        $candidates = KnowledgeBase::query()
            ->whereIn('chunk_sync_status', ['pending', 'processing'])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get(['id', 'chunk_sync_require_real_embedding']);
        $recovered = 0;

        foreach ($candidates as $candidate) {
            $knowledgeBaseId = (int) $candidate->id;
            $sync = $this->reserve(
                $knowledgeBaseId,
                (bool) $candidate->chunk_sync_require_real_embedding,
                true,
                $cutoff,
            );
            if ($sync === null) {
                continue;
            }

            try {
                $this->dispatchReserved($knowledgeBaseId, $sync);
                $recovered++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $recovered;
    }

    /**
     * @return array{token:string,require_real_embedding:bool}|null
     */
    private function reserve(
        int $knowledgeBaseId,
        bool $requireRealEmbedding,
        bool $force,
        ?Carbon $staleBefore = null,
    ): ?array {
        return DB::transaction(function () use (
            $knowledgeBaseId,
            $requireRealEmbedding,
            $force,
            $staleBefore,
        ): ?array {
            $knowledgeBase = KnowledgeBase::query()
                ->whereKey($knowledgeBaseId)
                ->lockForUpdate()
                ->first();
            if (! $knowledgeBase) {
                return null;
            }

            if (
                $staleBefore instanceof Carbon
                && (
                    ! in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing'], true)
                    || $knowledgeBase->updated_at?->greaterThan($staleBefore)
                )
            ) {
                return null;
            }

            $sourceHash = hash('sha256', (string) $knowledgeBase->content);
            if (! $force && $this->alreadyScheduledOrCurrent($knowledgeBase, $sourceHash)) {
                return null;
            }

            $token = (string) Str::uuid();
            $knowledgeBase->forceFill([
                'chunk_sync_status' => 'pending',
                'chunk_sync_token' => $token,
                'chunk_source_hash' => $sourceHash,
                'chunk_sync_error' => null,
                'chunk_sync_require_real_embedding' => $requireRealEmbedding,
            ])->save();
            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where('sync_token', '!=', $token)
                ->delete();

            return [
                'token' => $token,
                'require_real_embedding' => $requireRealEmbedding,
            ];
        });
    }

    /**
     * @param  array{token:string,require_real_embedding:bool}  $sync
     */
    private function dispatchReserved(int $knowledgeBaseId, array $sync): void
    {
        $token = (string) $sync['token'];
        try {
            PrepareKnowledgeChunkSyncJob::dispatch(
                $knowledgeBaseId,
                $token,
                (bool) $sync['require_real_embedding'],
            )
                ->onQueue('knowledge')
                ->afterCommit();
        } catch (Throwable $exception) {
            $this->markFailed($knowledgeBaseId, $token, $exception->getMessage());
            throw $exception;
        }
    }

    public function isCurrent(int $knowledgeBaseId, string $syncToken): bool
    {
        return KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->whereIn('chunk_sync_status', ['pending', 'processing'])
            ->exists();
    }

    public function markFailed(int $knowledgeBaseId, string $syncToken, string $message): void
    {
        DB::transaction(function () use ($knowledgeBaseId, $syncToken, $message): void {
            KnowledgeBase::query()
                ->whereKey($knowledgeBaseId)
                ->where('chunk_sync_token', $syncToken)
                ->whereIn('chunk_sync_status', ['pending', 'processing'])
                ->update([
                    'chunk_sync_status' => 'failed',
                    'chunk_sync_error' => mb_substr(trim($message), 0, 2000, 'UTF-8'),
                    'updated_at' => now(),
                ]);

            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where('sync_token', $syncToken)
                ->delete();
        });
    }

    private function alreadyScheduledOrCurrent(KnowledgeBase $knowledgeBase, string $sourceHash): bool
    {
        if (
            in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing'], true)
            && hash_equals((string) $knowledgeBase->chunk_source_hash, $sourceHash)
        ) {
            return true;
        }

        return (string) $knowledgeBase->chunk_sync_status === 'ready'
            && hash_equals((string) $knowledgeBase->chunk_source_hash, $sourceHash);
    }
}
