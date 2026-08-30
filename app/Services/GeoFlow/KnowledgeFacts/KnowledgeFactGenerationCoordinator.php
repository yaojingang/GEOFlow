<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Jobs\FinalizeKnowledgeFactGenerationJob;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KnowledgeFactGenerationCoordinator
{
    public function __construct(private readonly KnowledgeFactAiGenerator $generator) {}

    public function start(KnowledgeFactLibrary $library, AiModel $model, Admin $admin, string $mode, int $targetCount): KnowledgeFactGenerationRun
    {
        $targetCount = max(1, min((int) config('geoflow.knowledge_fact_generation_max_per_run', 200), $targetCount));
        $run = DB::transaction(function () use ($library, $model, $admin, $mode, $targetCount): KnowledgeFactGenerationRun {
            $locked = KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $locked->load('knowledgeBase');
            if ($locked->knowledgeBase->chunk_sync_status !== 'ready' || $locked->knowledgeBase->chunk_source_hash === '') {
                throw new RuntimeException('knowledge_chunks_not_ready');
            }
            if (KnowledgeFactGenerationRun::query()->where('active_key', $this->activeKey($locked->id))->exists()) {
                throw new RuntimeException('knowledge_fact_generation_active');
            }
            if ($model->status !== 'active' || in_array((string) $model->model_type, ['embedding', 'image'], true)) {
                throw new RuntimeException('knowledge_fact_generation_model_unavailable');
            }
            $run = $locked->generationRuns()->create([
                'mode' => $mode, 'target_count' => $targetCount, 'source_hash' => $locked->knowledgeBase->chunk_source_hash,
                'base_working_version' => $locked->working_version, 'status' => 'queued', 'ai_model_id' => $model->id,
                'created_by_admin_id' => $admin->id, 'request_key' => (string) Str::uuid(), 'active_key' => $this->activeKey($locked->id),
                'result_json' => ['candidates' => [], 'conflicts' => [], 'batches' => []], 'batch_meta_json' => [],
            ]);
            $locked->forceFill(['workflow_status' => 'generating'])->save();

            return $run;
        }, 3);

        $this->dispatch($run);

        return $run;
    }

    public function dispatch(KnowledgeFactGenerationRun $run): void
    {
        if ($run->job_batch_id !== null) {
            return;
        }
        $library = $run->library()->with('knowledgeBase.chunks')->firstOrFail();
        $evidence = $library->knowledgeBase->chunks->map(fn ($chunk) => [
            'evidence_key' => 'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
            'chunk_id' => (string) $chunk->id, 'source_hash' => (string) $chunk->source_hash,
            'content_hash' => (string) $chunk->content_hash, 'section_path' => (string) $chunk->section_path,
            'content' => mb_substr((string) $chunk->content, 0, 6000),
        ])->values()->all();
        if ($evidence === []) {
            $this->failRun($run->id, 'knowledge_fact_generation_no_evidence');

            return;
        }
        $batchSize = (int) config('geoflow.knowledge_fact_generation_batch_size', 25);
        $jobCount = min(8, max(1, (int) ceil($run->target_count / $batchSize)));
        $groups = array_chunk($evidence, max(1, (int) ceil(count($evidence) / $jobCount)));
        $jobs = [];
        foreach (array_slice($groups, 0, $jobCount) as $index => $group) {
            $hash = hash('sha256', json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $jobs[] = new GenerateKnowledgeFactBatchJob($run->id, $index + 1, $hash, $group);
        }
        $runId = $run->id;
        $batch = Bus::batch($jobs)->name("knowledge-facts:{$runId}")->allowFailures()->finally(static function (Batch $batch) use ($runId): void {
            FinalizeKnowledgeFactGenerationJob::dispatch($runId)->onQueue('knowledge');
        })->onQueue('knowledge')->dispatch();
        $run->forceFill(['job_batch_id' => $batch->id, 'status' => 'running', 'started_at' => now()])->save();
    }

    /** @param list<array<string,string>> $evidence */
    public function processBatch(int $runId, int $sequence, string $inputHash, array $evidence): void
    {
        $run = KnowledgeFactGenerationRun::query()->with('aiModel')->findOrFail($runId);
        if (! $run->isActive() || $run->cancel_requested_at !== null) {
            return;
        }
        $facts = $this->generator->generate($run->aiModel, $evidence, min((int) config('geoflow.knowledge_fact_generation_batch_size', 25), $run->target_count));
        DB::transaction(function () use ($runId, $sequence, $inputHash, $facts): void {
            $locked = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive() || $locked->cancel_requested_at !== null) {
                return;
            }
            $result = (array) $locked->result_json;
            $batches = (array) ($result['batches'] ?? []);
            if (isset($batches[(string) $sequence]) && data_get($batches, $sequence.'.input_hash') === $inputHash) {
                return;
            }
            $result['candidates'] = array_slice(array_merge((array) ($result['candidates'] ?? []), $facts), 0, 200);
            $result['batches'][(string) $sequence] = ['input_hash' => $inputHash, 'status' => 'completed', 'candidate_count' => count($facts)];
            $locked->forceFill(['result_json' => $result, 'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))])->save();
        }, 3);
    }

    public function recordBatchFailure(int $runId, int $sequence, string $inputHash, ?Throwable $exception): void
    {
        DB::transaction(function () use ($runId, $sequence, $inputHash, $exception): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! $run->isActive()) {
                return;
            }
            $result = (array) $run->result_json;
            $result['batches'][(string) $sequence] = ['input_hash' => $inputHash, 'status' => 'failed'];
            $run->forceFill(['result_json' => $result, 'error_code' => 'batch_failed', 'error_message' => $exception === null ? 'batch_failed' : 'batch_failed:'.$exception::class])->save();
        });
    }

    public function finalize(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            if (! $run->isActive()) {
                return;
            }
            if ($run->cancel_requested_at !== null) {
                $this->markCancelled($run, $library);

                return;
            }
            $library->load('knowledgeBase');
            if (! hash_equals((string) $run->source_hash, (string) $library->knowledgeBase->chunk_source_hash)) {
                $run->forceFill(['status' => 'obsolete', 'active_key' => null, 'completed_at' => now()])->save();
                $library->forceFill(['workflow_status' => 'review_required'])->save();

                return;
            }
            $result = (array) $run->result_json;
            $conflicts = [];
            $created = 0;
            foreach (array_slice((array) ($result['candidates'] ?? []), 0, $run->target_count) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                if ($library->facts()->where('stable_key', $candidate['stable_key'])->exists()) {
                    $conflicts[] = $candidate;

                    continue;
                }
                $fact = $library->facts()->create([
                    'stable_key' => $candidate['stable_key'], 'label' => $candidate['label'], 'subject' => $candidate['subject'], 'predicate' => $candidate['predicate'],
                    'value_type' => $candidate['value_type'], 'origin_generation_run_id' => $run->id, 'created_by_admin_id' => $run->created_by_admin_id, 'updated_by_admin_id' => $run->created_by_admin_id,
                ]);
                $value = $fact->values()->create([
                    'canonical_value_json' => ['value' => (string) $candidate['canonical_value'], 'unit' => (string) $candidate['unit']],
                    'canonical_answer' => $candidate['canonical_answer'], 'scope_hash' => hash('sha256', '{}'), 'origin_generation_run_id' => $run->id,
                    'created_by_admin_id' => $run->created_by_admin_id, 'updated_by_admin_id' => $run->created_by_admin_id,
                ]);
                foreach (array_values(array_unique((array) ($candidate['evidence_keys'] ?? []))) as $evidenceKey) {
                    if (preg_match('/\Achunk:(\d+):([a-f0-9]{12})\z/', (string) $evidenceKey, $matches) !== 1) {
                        continue;
                    }
                    $chunk = KnowledgeChunk::query()->whereKey((int) $matches[1])->where('knowledge_base_id', $library->knowledge_base_id)->first();
                    if (! $chunk || ! str_starts_with((string) $chunk->content_hash, $matches[2])) {
                        continue;
                    }
                    $excerpt = mb_substr((string) $chunk->content, 0, 5000);
                    $value->evidences()->create([
                        'knowledge_chunk_id' => $chunk->id,
                        'source_hash' => (string) $chunk->source_hash,
                        'content_hash' => (string) $chunk->content_hash,
                        'source_locator_json' => ['section_path' => (string) $chunk->section_path],
                        'excerpt' => $excerpt,
                        'excerpt_hash' => hash('sha256', trim($excerpt)),
                        'is_primary' => true,
                        'created_by_admin_id' => $run->created_by_admin_id,
                    ]);
                }
                $created++;
            }
            $result['conflicts'] = $conflicts;
            $failedBatches = count(array_filter((array) ($result['batches'] ?? []), fn ($batch) => data_get($batch, 'status') === 'failed'));
            $status = $created === 0 && $conflicts === [] ? 'failed' : (($created < $run->target_count || $conflicts !== [] || $failedBatches > 0) ? 'partial' : 'completed');
            $run->forceFill(['status' => $status, 'active_key' => null, 'result_json' => $result, $status === 'failed' ? 'failed_at' : 'completed_at' => now()])->save();
            if ($created > 0) {
                $library->increment('working_version');
            }
            $library->forceFill(['workflow_status' => 'review_required'])->save();
        }, 3);
    }

    public function cancel(KnowledgeFactGenerationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = KnowledgeFactGenerationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                return;
            }
            $locked->forceFill(['cancel_requested_at' => now()])->save();
            if ($locked->job_batch_id) {
                Bus::findBatch($locked->job_batch_id)?->cancel();
            }
        });
    }

    private function failRun(int $runId, string $code): void
    {
        $run = KnowledgeFactGenerationRun::query()->find($runId);
        if ($run) {
            $run->forceFill(['status' => 'failed', 'active_key' => null, 'error_code' => $code, 'failed_at' => now()])->save();
            $run->library()->update(['workflow_status' => 'failed']);
        }
    }

    private function markCancelled(KnowledgeFactGenerationRun $run, KnowledgeFactLibrary $library): void
    {
        $run->forceFill(['status' => 'cancelled', 'active_key' => null, 'cancelled_at' => now()])->save();
        $library->forceFill(['workflow_status' => 'idle'])->save();
    }

    private function activeKey(int $libraryId): string
    {
        return "knowledge-fact-library:{$libraryId}";
    }
}
