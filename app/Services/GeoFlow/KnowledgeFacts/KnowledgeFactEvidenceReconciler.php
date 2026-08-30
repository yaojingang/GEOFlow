<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\DB;

class KnowledgeFactEvidenceReconciler
{
    public function reconcile(int $knowledgeBaseId, string $sourceHash): void
    {
        DB::transaction(function () use ($knowledgeBaseId, $sourceHash): void {
            $knowledgeBase = KnowledgeBase::query()->with('factLibrary')->whereKey($knowledgeBaseId)->lockForUpdate()->first();
            if (! $knowledgeBase?->factLibrary) {
                return;
            }
            $library = $knowledgeBase->factLibrary;
            $evidences = DB::table('knowledge_fact_evidences as e')->join('knowledge_fact_values as v', 'v.id', '=', 'e.value_id')->join('knowledge_facts as f', 'f.id', '=', 'v.fact_id')->where('f.library_id', $library->id)->whereNull('e.knowledge_chunk_id')->select('e.*')->get();
            $relinked = 0;
            foreach ($evidences as $evidence) {
                $locator = json_decode((string) ($evidence->source_locator_json ?? ''), true) ?: [];
                $chunkId = DB::table('knowledge_chunks')->where('knowledge_base_id', $knowledgeBaseId)->where('source_hash', $sourceHash)->where('content_hash', $evidence->content_hash)
                    ->when(isset($locator['section_path']), fn ($query) => $query->where('section_path', $locator['section_path']))->value('id');
                if ($chunkId) {
                    DB::table('knowledge_fact_evidences')->where('id', $evidence->id)->update(['knowledge_chunk_id' => $chunkId, 'source_hash' => $sourceHash, 'updated_at' => now()]);
                    $relinked++;
                }
            }
            $unresolved = DB::table('knowledge_fact_evidences as e')->join('knowledge_fact_values as v', 'v.id', '=', 'e.value_id')->join('knowledge_facts as f', 'f.id', '=', 'v.fact_id')->where('f.library_id', $library->id)->whereNull('e.knowledge_chunk_id')->count();
            $library->forceFill(['source_hash' => $sourceHash, 'serving_status' => $unresolved > 0 ? 'stale' : ($library->active_revision_id ? 'ready' : 'unavailable'), 'active_health_json' => ['relinked' => $relinked, 'unresolved' => $unresolved, 'checked_at' => now()->toIso8601String()]])->save();
        }, 3);
    }

    public function markStale(int $knowledgeBaseId, string $reason): void
    {
        KnowledgeBase::query()->find($knowledgeBaseId)?->factLibrary()?->update(['serving_status' => 'stale', 'active_health_json' => ['reason' => $reason, 'checked_at' => now()->toIso8601String()]]);
    }
}
