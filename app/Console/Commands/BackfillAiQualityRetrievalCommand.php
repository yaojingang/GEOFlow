<?php

namespace App\Console\Commands;

use App\Models\ArticleAiQualityCheck;
use App\Models\KnowledgeBase;
use App\Models\Task;
use App\Services\GeoFlow\AiQualityRetrievalReadinessService;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAiQualityRetrievalCommand extends Command
{
    protected $signature = 'geoflow:backfill-ai-quality-retrieval
        {--batch=200 : Rows processed per batch}
        {--dry-run : Report eligible rows without changing data}';

    protected $description = 'Backfill legacy AI quality modes, source ledgers, and serving chunk generations';

    public function __construct(private readonly AiQualityRetrievalReadinessService $readinessService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batch = max(1, min(1000, (int) $this->option('batch')));
        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'tasks' => 0,
            'tasks_deferred' => 0,
            'checks' => 0,
            'checks_staled' => 0,
            'sources' => 0,
            'knowledge_bases' => 0,
            'readiness_projections' => 0,
            'atomic_fact_counts' => 0,
        ];

        KnowledgeBase::query()
            ->where('ai_quality_content_hash', '')
            ->select('id')
            ->orderBy('id')
            ->chunkById($batch, function ($knowledgeBases) use (&$counts, $dryRun): void {
                foreach ($knowledgeBases as $knowledgeBase) {
                    $updated = DB::transaction(function () use ($knowledgeBase, $dryRun): bool {
                        $locked = KnowledgeBase::query()->whereKey($knowledgeBase->id)->lockForUpdate()->first();
                        if (! $locked || trim((string) $locked->ai_quality_content_hash) !== '') {
                            return false;
                        }
                        if (! $dryRun) {
                            $content = (string) $locked->content;
                            $locked->forceFill([
                                'ai_quality_content_hash' => hash('sha256', $content),
                                'ai_quality_content_length' => mb_strlen($content, 'UTF-8'),
                            ])->save();
                        }

                        return true;
                    }, 3);
                    $counts['readiness_projections'] += $updated ? 1 : 0;
                }
            });

        DB::table('knowledge_fact_libraries')
            ->whereNotNull('active_revision_id')
            ->where('active_fact_count', 0)
            ->select(['id', 'active_revision_id'])
            ->orderBy('id')
            ->chunkById($batch, function ($libraries) use (&$counts, $dryRun): void {
                foreach ($libraries as $library) {
                    $updated = DB::transaction(function () use ($library, $dryRun): bool {
                        $locked = DB::table('knowledge_fact_libraries')
                            ->where('id', $library->id)
                            ->lockForUpdate()
                            ->first(['id', 'active_revision_id', 'active_fact_count']);
                        if (! $locked || ! $locked->active_revision_id || (int) $locked->active_fact_count !== 0) {
                            return false;
                        }
                        if (! $dryRun) {
                            $manifest = DB::table('knowledge_fact_library_revisions')
                                ->where('id', $locked->active_revision_id)
                                ->value('manifest_json');
                            $decoded = json_decode((string) $manifest, true);
                            DB::table('knowledge_fact_libraries')->where('id', $locked->id)->update([
                                'active_fact_count' => is_array($decoded['facts'] ?? null)
                                    ? count($decoded['facts'])
                                    : 0,
                            ]);
                        }

                        return true;
                    }, 3);
                    $counts['atomic_fact_counts'] += $updated ? 1 : 0;
                }
            });

        $this->backfillServingGenerations($batch, $dryRun, $counts);

        Task::query()
            ->whereNull('ai_quality_retrieval_mode')
            ->orderBy('id')
            ->chunkById($batch, function ($tasks) use (&$counts, $dryRun): void {
                foreach ($tasks as $task) {
                    $result = DB::transaction(function () use ($task, $dryRun): string {
                        $locked = Task::query()->whereKey($task->id)->lockForUpdate()->first();
                        if (! $locked || filled($locked->ai_quality_retrieval_mode)) {
                            return 'skipped';
                        }
                        $ids = $locked->knowledgeBases()->pluck('knowledge_bases.id')
                            ->map('intval')->filter()->unique()->values();
                        if ($ids->isEmpty() && (int) $locked->knowledge_base_id > 0) {
                            $ids->push((int) $locked->knowledge_base_id);
                        }
                        $readiness = $this->readinessService->inspect($ids->all());
                        if (! (bool) data_get($readiness, 'modes.'.AiQualityRetrievalMode::CHUNK.'.available', false)) {
                            return 'deferred';
                        }
                        if (! $dryRun) {
                            $locked->forceFill([
                                'ai_quality_retrieval_mode' => AiQualityRetrievalMode::CHUNK,
                            ])->save();
                        }

                        return 'updated';
                    }, 3);
                    $counts['tasks'] += $result === 'updated' ? 1 : 0;
                    $counts['tasks_deferred'] += $result === 'deferred' ? 1 : 0;
                }
            });

        ArticleAiQualityCheck::query()
            ->whereNull('requested_retrieval_mode')
            ->orderBy('id')
            ->chunkById($batch, function ($checks) use (&$counts, $dryRun): void {
                foreach ($checks as $check) {
                    $result = DB::transaction(function () use ($check, $dryRun): array {
                        $locked = ArticleAiQualityCheck::query()->whereKey($check->id)->lockForUpdate()->first();
                        if (! $locked || filled($locked->requested_retrieval_mode)) {
                            return ['updated' => false, 'staled' => false];
                        }
                        $mode = (bool) data_get($locked->execution_meta, 'atomic_facts.formal', false)
                            && (string) $locked->evaluation_mode !== 'shadow'
                            ? AiQualityRetrievalMode::ATOMIC_FIRST
                            : AiQualityRetrievalMode::CHUNK;
                        $mustStale = (bool) $locked->gate_applied
                            && in_array((string) $locked->status, ['queued', 'running', 'completed', 'failed'], true)
                            && trim((string) $locked->retrieval_basis_hash) === '';
                        if (! $dryRun) {
                            $locked->forceFill([
                                'requested_retrieval_mode' => $mode,
                                'effective_retrieval_mode' => in_array((string) $locked->status, ['completed', 'failed', 'stale'], true)
                                    ? $mode
                                    : null,
                                'retrieval_strategy_version' => 'legacy-backfill-1.0.0',
                                ...($mustStale ? [
                                    'status' => 'stale',
                                    'active_dedupe_key' => null,
                                    'error_code' => 'retrieval_basis_missing',
                                    'error_message' => '历史质检缺少可验证的召回依据，已失效并等待重新质检。',
                                    'finished_at' => now(),
                                ] : []),
                            ])->save();
                        }

                        return ['updated' => true, 'staled' => $mustStale];
                    }, 3);
                    $counts['checks'] += $result['updated'] ? 1 : 0;
                    $counts['checks_staled'] += $result['staled'] ? 1 : 0;
                }
            });

        ArticleAiQualityCheck::query()
            ->whereDoesntHave('sources')
            ->orderBy('id')
            ->chunkById($batch, function ($checks) use (&$counts, $dryRun): void {
                foreach ($checks as $check) {
                    $created = DB::transaction(function () use ($check, $dryRun): int {
                        $locked = ArticleAiQualityCheck::query()->whereKey($check->id)->lockForUpdate()->first();
                        if (! $locked || $locked->sources()->exists()) {
                            return 0;
                        }
                        $ids = collect(data_get($locked->execution_meta, 'knowledge_base_ids', []))
                            ->map('intval')->filter()->unique()->values();
                        $knowledgeBases = KnowledgeBase::query()
                            ->whereIn('id', $ids)
                            ->with(['factLibrary.activeRevision'])
                            ->get()
                            ->keyBy('id');
                        $kinds = match ((string) $locked->requested_retrieval_mode) {
                            AiQualityRetrievalMode::ATOMIC_FIRST => ['atomic', 'chunk'],
                            AiQualityRetrievalMode::KNOWLEDGE_BROAD => ['raw_content'],
                            default => ['chunk'],
                        };
                        $count = $ids->count() * count($kinds);
                        if ($dryRun) {
                            return $count;
                        }
                        foreach ($ids as $id) {
                            $knowledgeBase = $knowledgeBases->get($id);
                            foreach ($kinds as $kind) {
                                $locked->sources()->create([
                                    'knowledge_base_id' => (int) $id,
                                    'dependency_kind' => $kind,
                                    'knowledge_base_name_snapshot' => (string) ($knowledgeBase?->name ?? '知识库 #'.$id),
                                    'source_hash' => $kind === 'raw_content'
                                        ? hash('sha256', (string) ($knowledgeBase?->content ?? ''))
                                        : ($knowledgeBase?->servingChunkSourceHash() ?: null),
                                    'chunk_serving_generation' => $knowledgeBase?->chunk_serving_generation,
                                    'chunk_manifest_hash' => $knowledgeBase?->chunk_manifest_hash,
                                    'fact_revision_id' => $knowledgeBase?->factLibrary?->active_revision_id,
                                    'fact_library_hash' => $knowledgeBase?->factLibrary?->active_hash,
                                    'readiness_status' => 'legacy_unknown',
                                ]);
                            }
                        }

                        return $count;
                    }, 3);
                    $counts['sources'] += $created;
                }
            });

        $prefix = $dryRun ? 'Eligible' : 'Backfilled';
        foreach ($counts as $kind => $count) {
            $this->line(sprintf('%s %s: %d', $prefix, $kind, $count));
        }

        return self::SUCCESS;
    }

    /** @param array<string,int> $counts */
    private function backfillServingGenerations(int $batch, bool $dryRun, array &$counts): void
    {
        KnowledgeBase::query()
            ->whereNull('chunk_serving_generation')
            ->whereHas('chunks')
            ->orderBy('id')
            ->chunkById($batch, function ($knowledgeBases) use (&$counts, $dryRun): void {
                foreach ($knowledgeBases as $knowledgeBase) {
                    $counts['knowledge_bases']++;
                    if ($dryRun) {
                        continue;
                    }
                    DB::transaction(function () use ($knowledgeBase): void {
                        $locked = KnowledgeBase::query()->whereKey($knowledgeBase->id)->lockForUpdate()->firstOrFail();
                        if (filled($locked->chunk_serving_generation)) {
                            return;
                        }
                        $sourceHash = $locked->servingChunkSourceHash();
                        if ($sourceHash === '') {
                            $sourceHash = hash('sha256', (string) $locked->content);
                        }
                        $generation = 'legacy-'.$locked->id.'-'.substr($sourceHash, 0, 16);
                        $locked->chunks()->whereNull('generation_key')->update([
                            'generation_key' => $generation,
                            'updated_at' => now(),
                        ]);
                        $manifest = $locked->chunks()
                            ->where('generation_key', $generation)
                            ->orderBy('chunk_index')
                            ->orderBy('id')
                            ->get(['chunk_index', 'content_hash', 'source_hash'])
                            ->map(static fn ($chunk): array => [
                                'chunk_index' => (int) $chunk->chunk_index,
                                'content_hash' => (string) $chunk->content_hash,
                                'source_hash' => (string) $chunk->source_hash,
                            ])
                            ->all();
                        $locked->forceFill([
                            'chunk_serving_generation' => $generation,
                            'chunk_serving_source_hash' => $sourceHash,
                            'chunk_manifest_hash' => hash('sha256', json_encode(
                                $manifest,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                            )),
                        ])->save();
                    }, 3);
                }
            });
    }
}
