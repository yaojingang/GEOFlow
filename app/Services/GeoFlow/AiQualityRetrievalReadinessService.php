<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Support\Collection;

class AiQualityRetrievalReadinessService
{
    public function __construct(private readonly ArticleAiQualityRolloutPolicy $rolloutPolicy) {}

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @return array{
     *   highest_available_mode:?string,
     *   knowledge_bases:list<array<string,mixed>>,
     *   modes:array<string,array{available:bool,blockers:list<array<string,mixed>>}>
     * }
     */
    public function inspect(array $knowledgeBaseIds): array
    {
        $ids = collect($knowledgeBaseIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $ids->all())
            ->with([
                'factLibrary:id,knowledge_base_id,serving_status,active_revision_id,active_hash,active_fact_count,source_hash',
                'factLibrary.activeRevision:id,library_id,library_hash,source_hash',
            ])
            ->get([
                'id',
                'name',
                'ai_quality_content_hash',
                'ai_quality_content_length',
                'risk_level',
                'review_status',
                'chunk_sync_status',
                'chunk_source_hash',
                'chunk_serving_generation',
                'chunk_serving_source_hash',
                'chunk_manifest_hash',
            ])
            ->keyBy('id');

        $chunkCounts = KnowledgeChunk::query()
            ->whereIn('knowledge_base_id', $ids->all())
            ->selectRaw('knowledge_base_id, generation_key, COUNT(*) AS aggregate')
            ->groupBy('knowledge_base_id', 'generation_key')
            ->get()
            ->groupBy('knowledge_base_id');

        $rollout = $this->rolloutPolicy->state();
        $rows = $ids->map(function (int $id) use ($knowledgeBases, $chunkCounts, $rollout): array {
            $knowledgeBase = $knowledgeBases->get($id);
            if (! $knowledgeBase) {
                return [
                    'id' => $id,
                    'name' => '知识库 #'.$id,
                    'modes' => $this->missingKnowledgeBaseModes(),
                ];
            }

            return [
                'id' => $id,
                'name' => (string) $knowledgeBase->name,
                'modes' => $this->inspectKnowledgeBase(
                    $knowledgeBase,
                    $chunkCounts->get($id, collect()),
                    $rollout,
                ),
            ];
        })->all();

        $modes = [];
        foreach (AiQualityRetrievalMode::values() as $mode) {
            $blockers = [];
            if ($rows === []) {
                $blockers[] = [
                    'knowledge_base_id' => null,
                    'knowledge_base_name' => null,
                    'code' => 'knowledge_base_required',
                    'message' => '请先选择至少一个知识库',
                ];
            }

            foreach ($rows as $row) {
                $modeState = $row['modes'][$mode];
                if ($modeState['available']) {
                    continue;
                }

                foreach ($modeState['blockers'] as $blocker) {
                    $blockers[] = [
                        'knowledge_base_id' => $row['id'],
                        'knowledge_base_name' => $row['name'],
                        ...$blocker,
                    ];
                }
            }

            $modes[$mode] = [
                'available' => $blockers === [],
                'blockers' => $blockers,
            ];
        }

        $highest = collect(AiQualityRetrievalMode::values())
            ->first(static fn (string $mode): bool => $modes[$mode]['available']);

        return [
            'highest_available_mode' => $highest,
            'knowledge_bases' => $rows,
            'modes' => $modes,
        ];
    }

    /**
     * @param  Collection<int,object>  $chunkCounts
     * @param  array<string,mixed>  $rollout
     * @return array<string,array{available:bool,blockers:list<array{code:string,message:string}>}>
     */
    private function inspectKnowledgeBase(KnowledgeBase $knowledgeBase, Collection $chunkCounts, array $rollout): array
    {
        $contentHash = trim((string) $knowledgeBase->ai_quality_content_hash);
        $broadBlockers = [];
        if ((int) $knowledgeBase->ai_quality_content_length < 1 || $contentHash === '') {
            $broadBlockers[] = ['code' => 'knowledge_content_empty', 'message' => '知识库正文为空'];
        }
        if ((string) $knowledgeBase->risk_level === 'high'
            && ! in_array((string) $knowledgeBase->review_status, ['reviewed', 'approved'], true)) {
            $broadBlockers[] = ['code' => 'knowledge_review_required', 'message' => '高风险知识库需要先完成审核'];
        }

        $chunkBlockers = $broadBlockers;
        $generation = trim((string) $knowledgeBase->chunk_serving_generation);
        $servingSourceHash = trim((string) $knowledgeBase->chunk_serving_source_hash);
        $servingChunkCount = $chunkCounts
            ->first(static fn (object $row): bool => (string) $row->generation_key === $generation)?->aggregate ?? 0;
        if ((string) $knowledgeBase->chunk_sync_status !== 'ready') {
            $chunkBlockers[] = ['code' => 'chunk_sync_not_ready', 'message' => '切片同步尚未完成'];
        }
        if ($generation === '' || $servingSourceHash === '') {
            $chunkBlockers[] = ['code' => 'chunk_serving_generation_missing', 'message' => '切片服务代次尚未建立'];
        } elseif (! hash_equals($contentHash, $servingSourceHash)
            || ! hash_equals((string) $knowledgeBase->chunk_source_hash, $servingSourceHash)) {
            $chunkBlockers[] = ['code' => 'chunk_source_stale', 'message' => '切片来源与当前正文不一致'];
        }
        if ((int) $servingChunkCount < 1) {
            $chunkBlockers[] = ['code' => 'chunk_missing', 'message' => '当前服务代次没有可用切片'];
        }

        $atomicBlockers = $chunkBlockers;
        $library = $knowledgeBase->factLibrary;
        $revision = $library?->activeRevision;
        if (! $library || (string) $library->serving_status !== 'ready' || ! $revision || (int) $library->active_fact_count < 1) {
            $atomicBlockers[] = ['code' => 'atomic_library_not_ready', 'message' => '原子事实库尚未发布可用版本'];
        } elseif (! hash_equals((string) $library->source_hash, $servingSourceHash)
            || ! hash_equals((string) $revision->source_hash, $servingSourceHash)
            || ! hash_equals((string) $library->active_hash, (string) $revision->library_hash)) {
            $atomicBlockers[] = ['code' => 'atomic_source_stale', 'message' => '原子事实版本与当前切片来源不一致'];
        }
        if ((int) ($rollout['atomic_fact_percent'] ?? 0) !== 100
            || (bool) ($rollout['atomic_fact_frozen'] ?? true)
            || (bool) ($rollout['frozen'] ?? true)) {
            $atomicBlockers[] = ['code' => 'atomic_rollout_not_ready', 'message' => '数据已就绪，正式质检仍在灰度验证'];
        }

        return [
            AiQualityRetrievalMode::KNOWLEDGE_BROAD => $this->state($broadBlockers),
            AiQualityRetrievalMode::CHUNK => $this->state($chunkBlockers),
            AiQualityRetrievalMode::ATOMIC_FIRST => $this->state($atomicBlockers),
        ];
    }

    /** @return array<string,array{available:bool,blockers:list<array{code:string,message:string}>}> */
    private function missingKnowledgeBaseModes(): array
    {
        $state = $this->state([['code' => 'knowledge_base_missing', 'message' => '知识库不存在或已删除']]);

        return array_fill_keys(AiQualityRetrievalMode::values(), $state);
    }

    /**
     * @param  list<array{code:string,message:string}>  $blockers
     * @return array{available:bool,blockers:list<array{code:string,message:string}>}
     */
    private function state(array $blockers): array
    {
        return ['available' => $blockers === [], 'blockers' => $blockers];
    }
}
