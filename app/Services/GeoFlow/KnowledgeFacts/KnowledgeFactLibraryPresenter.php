<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Models\KnowledgeFactValue;
use Illuminate\Support\Facades\Bus;

class KnowledgeFactLibraryPresenter
{
    /** @return array<string,int|string|bool|null> */
    public function summary(KnowledgeFactLibrary $library): array
    {
        $facts = $library->facts();
        $values = KnowledgeFactValue::query()->whereHas('fact', fn ($query) => $query->where('library_id', $library->id));

        return [
            'fact_count' => (clone $facts)->count(),
            'enabled_count' => (clone $facts)->where('is_enabled', true)->count(),
            'pending_count' => (clone $facts)->where('review_status', '!=', 'reviewed')->count()
                + (clone $values)->where('review_status', '!=', 'reviewed')->count(),
            'conflict_count' => (clone $values)->where('conflict_status', '!=', 'clear')->count(),
            'active_version' => $library->activeRevision?->version,
            'serving_status' => (string) $library->serving_status,
            'workflow_status' => (string) $library->workflow_status,
            'ready' => $library->serving_status === 'ready' && $library->active_revision_id !== null,
        ];
    }

    /** @return array{ready:bool,blockers:list<string>} */
    public function publishReadiness(KnowledgeFactLibrary $library): array
    {
        $blockers = [];
        $enabled = $library->facts()->where('is_enabled', true);
        if ((clone $enabled)->doesntExist()) {
            $blockers[] = '至少需要一条已启用事实。';
        }
        if ((clone $enabled)->where('review_status', '!=', 'reviewed')->exists()) {
            $blockers[] = '仍有事实指标等待审核。';
        }
        $values = KnowledgeFactValue::query()->whereHas('fact', fn ($query) => $query->where('library_id', $library->id)->where('is_enabled', true));
        if ((clone $values)->where('review_status', '!=', 'reviewed')->exists()) {
            $blockers[] = '仍有标准答案等待审核。';
        }
        if ((clone $values)->where('conflict_status', '!=', 'clear')->exists()) {
            $blockers[] = '仍有冲突等待处理。';
        }
        if ((clone $enabled)->whereDoesntHave('values.evidences')->exists()) {
            $blockers[] = '部分事实缺少可定位证据。';
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers];
    }

    /** @return array<string,mixed> */
    public function generationRun(KnowledgeFactGenerationRun $run, int $knowledgeBaseId): array
    {
        $batch = $run->job_batch_id ? Bus::findBatch($run->job_batch_id) : null;
        $batchProgress = $batch?->progress() ?? 0;
        $stage = (string) $run->status;
        $progress = match ($run->status) {
            'queued' => 8,
            'running' => $batchProgress >= 100 ? 92 : max(15, min(88, 15 + (int) floor($batchProgress * 0.73))),
            default => 100,
        };
        if ($run->status === 'running' && $batchProgress >= 100) {
            $stage = 'finalizing';
        }

        $startedAt = $run->started_at ?? $run->created_at;

        return [
            'id' => (int) $run->id,
            'status' => (string) $run->status,
            'stage' => $stage,
            'active' => $run->isActive(),
            'mode' => (string) $run->mode,
            'target_count' => (int) $run->target_count,
            'progress_percent' => $progress,
            'candidate_count' => count((array) data_get($run->result_json, 'candidates', [])),
            'conflict_count' => count((array) data_get($run->result_json, 'conflicts', [])),
            'elapsed_seconds' => $startedAt?->diffInSeconds($run->completed_at ?? now()) ?? 0,
            'batch' => [
                'total' => (int) ($batch?->totalJobs ?? 0),
                'completed' => (int) (($batch?->totalJobs ?? 0) - ($batch?->pendingJobs ?? 0)),
                'failed' => (int) ($batch?->failedJobs ?? 0),
            ],
            'actionable_error' => $run->isActive() ? null : $this->actionableError($run),
            'next_poll_ms' => $run->isActive() ? 2000 : null,
            'status_url' => route('admin.knowledge-bases.fact-generation.show', [$knowledgeBaseId, $run->id]),
            'cancel_url' => route('admin.knowledge-bases.fact-generation.cancel', [$knowledgeBaseId, $run->id]),
        ];
    }

    private function actionableError(KnowledgeFactGenerationRun $run): ?string
    {
        return match ((string) $run->status) {
            'failed' => __('admin.knowledge_facts.dialog.actionable_failed'),
            'obsolete' => __('admin.knowledge_facts.dialog.actionable_obsolete'),
            'cancelled' => __('admin.knowledge_facts.dialog.actionable_cancelled'),
            default => null,
        };
    }
}
