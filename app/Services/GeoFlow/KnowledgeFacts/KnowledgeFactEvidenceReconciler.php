<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeFactLibrary;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use Illuminate\Support\Facades\DB;

class KnowledgeFactEvidenceReconciler
{
    public function __construct(
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
    ) {}

    public function reconcile(int $knowledgeBaseId, string $sourceHash): void
    {
        $becameReady = DB::transaction(function () use ($knowledgeBaseId, $sourceHash): bool {
            $library = KnowledgeFactLibrary::query()->where('knowledge_base_id', $knowledgeBaseId)->lockForUpdate()->first();
            if (! $library) {
                return false;
            }
            $knowledgeBase = KnowledgeBase::query()->whereKey($knowledgeBaseId)->firstOrFail();
            if (! hash_equals($knowledgeBase->servingChunkSourceHash(), $sourceHash)) {
                return false;
            }
            $previousServingStatus = (string) $library->serving_status;
            $evidences = DB::table('knowledge_fact_evidences as e')->join('knowledge_fact_values as v', 'v.id', '=', 'e.value_id')->join('knowledge_facts as f', 'f.id', '=', 'v.fact_id')
                ->where('f.library_id', $library->id)->where('f.is_enabled', true)->where('v.review_status', '!=', 'rejected')->whereNull('e.knowledge_chunk_id')->select('e.*')->get();
            $relinked = 0;
            foreach ($evidences as $evidence) {
                $locator = json_decode((string) ($evidence->source_locator_json ?? ''), true) ?: [];
                $chunk = DB::table('knowledge_chunks')->where('knowledge_base_id', $knowledgeBaseId)->where('content_hash', $evidence->content_hash)
                    ->when(
                        filled($knowledgeBase->chunk_serving_generation),
                        fn ($query) => $query->where('generation_key', (string) $knowledgeBase->chunk_serving_generation),
                        fn ($query) => $query->whereNull('generation_key'),
                    )
                    ->when(isset($locator['section_path']), fn ($query) => $query->where('section_path', $locator['section_path']))->first(['id', 'source_hash']);
                if ($chunk) {
                    DB::table('knowledge_fact_evidences')->where('id', $evidence->id)->update(['knowledge_chunk_id' => $chunk->id, 'source_hash' => $chunk->source_hash, 'updated_at' => now()]);
                    $relinked++;
                }
            }
            $workingUnresolved = DB::table('knowledge_fact_evidences as e')->join('knowledge_fact_values as v', 'v.id', '=', 'e.value_id')->join('knowledge_facts as f', 'f.id', '=', 'v.fact_id')
                ->where('f.library_id', $library->id)->where('f.is_enabled', true)->where('v.review_status', '!=', 'rejected')->whereNull('e.knowledge_chunk_id')->count();
            $library->load('activeRevision');
            $servingStatus = $library->activeRevision === null ? 'unavailable' : 'stale';
            if ($library->activeRevision !== null && hash_equals((string) $library->activeRevision->source_hash, $sourceHash)) {
                $currentEvidence = DB::table('knowledge_chunks')
                    ->where('knowledge_base_id', $knowledgeBaseId)
                    ->when(
                        filled($knowledgeBase->chunk_serving_generation),
                        fn ($query) => $query->where('generation_key', (string) $knowledgeBase->chunk_serving_generation),
                        fn ($query) => $query->whereNull('generation_key'),
                    )
                    ->get(['source_hash', 'content_hash'])
                    ->mapWithKeys(fn ($chunk): array => [$chunk->source_hash.'|'.$chunk->content_hash => true]);
                $activeEvidenceCurrent = collect((array) data_get($library->activeRevision->manifest_json, 'facts', []))
                    ->flatMap(fn (array $fact) => (array) ($fact['values'] ?? []))
                    ->flatMap(fn (array $value) => (array) ($value['evidence'] ?? []))
                    ->every(fn (array $evidence): bool => $currentEvidence->has((string) ($evidence['source_hash'] ?? '').'|'.(string) ($evidence['content_hash'] ?? '')));
                $servingStatus = $activeEvidenceCurrent ? 'ready' : 'stale';
            }
            $library->forceFill([
                'serving_status' => $servingStatus,
                'active_health_json' => [
                    'relinked' => $relinked,
                    'working_unresolved' => $workingUnresolved,
                    'active_source_current' => $servingStatus === 'ready',
                    'checked_at' => now()->toIso8601String(),
                ],
            ])->save();

            return $previousServingStatus !== 'ready' && $servingStatus === 'ready';
        }, 3);
        if ($becameReady) {
            $this->qualityInvalidationService->invalidateKnowledgeBase(
                $knowledgeBaseId,
                '原子事实证据已恢复，可以重新执行原子质检',
                ['atomic'],
                'atomic_source_ready',
            );
        }
    }

    public function markStale(int $knowledgeBaseId, string $reason): void
    {
        $library = KnowledgeFactLibrary::query()->where('knowledge_base_id', $knowledgeBaseId)->first();
        if ($library !== null) {
            $library->forceFill(['serving_status' => 'stale', 'active_health_json' => ['reason' => $reason, 'checked_at' => now()->toIso8601String()]])->save();
        }
    }
}
