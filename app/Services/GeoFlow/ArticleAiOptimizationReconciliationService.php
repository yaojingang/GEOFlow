<?php

namespace App\Services\GeoFlow;

use App\Jobs\ProcessArticleAiOptimizationJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ArticleAiOptimizationReconciliationService
{
    public function __construct(
        private ArticleAiOptimizationCoordinator $coordinator,
        private ArticleAiQualityPolicyResolver $qualityPolicyResolver,
        private ArticleRiskScanner $riskScanner,
        private ArticleAiQualityInspectionService $inspectionService,
    ) {}

    /** @return array{examined:int,requeued:int,continued:int,stale:int,needs_review:int,workflow_recovered:int} */
    public function reconcile(int $limit = 500): array
    {
        $limit = max(1, min(500, $limit));
        $counts = [
            'examined' => 0,
            'requeued' => 0,
            'continued' => 0,
            'stale' => 0,
            'needs_review' => 0,
            'workflow_recovered' => 0,
        ];
        $staleAt = now()->subSeconds(max(
            60,
            (int) config('geoflow.ai_quality_optimization_recovery_stale_seconds', 300),
        ));
        $runIds = ArticleAiOptimizationRun::query()
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
            ->when((bool) config('geoflow.ai_quality_optimization_enabled', false), function ($query) use ($staleAt): void {
                $query->where(function ($query) use ($staleAt): void {
                    $query->where(function ($deadline): void {
                        $deadline->where('deadline_at', '<=', now())
                            ->where(function ($eligible): void {
                                $eligible->where('status', '!=', ArticleAiOptimizationRun::STATUS_CANDIDATE_READY)
                                    ->orWhere(function ($candidate): void {
                                        $candidate->where('status', ArticleAiOptimizationRun::STATUS_CANDIDATE_READY)
                                            ->where('trigger', ArticleAiOptimizationRun::TRIGGER_TASK_AUTO);
                                    });
                            });
                    })
                        ->orWhere('lease_expires_at', '<=', now())
                        ->orWhere(function ($stale) use ($staleAt): void {
                            $stale->whereIn('status', [
                                ArticleAiOptimizationRun::STATUS_QUEUED,
                                ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY,
                                ArticleAiOptimizationRun::STATUS_EVALUATING,
                                ArticleAiOptimizationRun::STATUS_APPLYING,
                            ])->where('updated_at', '<=', $staleAt);
                        });
                });
            })
            ->oldest('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($runIds as $runId) {
            $counts['examined']++;
            try {
                $action = $this->reconcileRun((int) $runId);
                if (array_key_exists($action, $counts)) {
                    $counts[$action]++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $counts['workflow_recovered'] += $this->recoverCompletedWorkflows($limit);
        $counts['workflow_recovered'] += $this->recoverWaitingWorkflows($limit);

        return $counts;
    }

    private function reconcileRun(int $runId): string
    {
        $info = ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->first(['article_id', 'task_id']);
        if (! $info) {
            return 'none';
        }
        $stepInfo = ArticleAiOptimizationStep::query()
            ->where('run_id', $runId)
            ->whereNotNull('output_check_id')
            ->latest('round_index')
            ->first(['output_check_id']);
        $candidateCheckIds = ArticleAiOptimizationStep::query()
            ->where('run_id', $runId)
            ->whereNotNull('output_check_id')
            ->orderBy('id')
            ->pluck('output_check_id')
            ->map('intval')
            ->all();

        $result = DB::transaction(function () use ($runId, $info, $stepInfo, $candidateCheckIds): array {
            $article = Article::query()->whereKey((int) $info->article_id)->lockForUpdate()->first();
            if (! $article) {
                return ['action' => 'none'];
            }
            if ($info->task_id) {
                Task::withTrashed()->whereKey((int) $info->task_id)->lockForUpdate()->first();
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! in_array((string) $run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                return ['action' => 'none'];
            }
            $checkIds = array_values(array_unique(array_filter([
                (int) $run->source_check_id,
                (int) $run->best_check_id,
                (int) $run->final_check_id,
                (int) $stepInfo?->output_check_id,
                ...$candidateCheckIds,
            ])));
            $checks = ArticleAiQualityCheck::query()
                ->whereIn('id', $checkIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)) {
                ArticleAiQualityCheck::query()
                    ->whereIn('id', $candidateCheckIds)
                    ->whereIn('status', ['queued', 'running'])
                    ->update([
                        'status' => 'cancelled',
                        'active_dedupe_key' => null,
                        'error_code' => 'optimization_cancelled',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->finish($run, ArticleAiOptimizationRun::STATUS_CANCELLED, 'optimization_feature_disabled');

                return [
                    'action' => 'recover_disabled_workflow',
                    'check_id' => (int) $run->source_check_id,
                ];
            }

            if ((string) $article->status !== 'draft') {
                $this->finish($run, ArticleAiOptimizationRun::STATUS_STALE, 'article_unavailable');

                return ['action' => 'stale'];
            }
            $currentHash = $this->riskScanner->contentHash(
                $this->qualityPolicyResolver->articleSnapshot($article),
            );
            if (! hash_equals((string) $run->base_article_hash, $currentHash)
                && (string) $run->status !== ArticleAiOptimizationRun::STATUS_APPLYING) {
                $this->finish($run, ArticleAiOptimizationRun::STATUS_STALE, 'article_changed');

                return ['action' => 'stale'];
            }
            if ((string) $run->status !== ArticleAiOptimizationRun::STATUS_CANDIDATE_READY
                && $run->deadline_at?->isPast()) {
                $this->finish($run, ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, 'deadline_exceeded');

                return ['action' => 'needs_review'];
            }

            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_CANDIDATE_READY
                && (string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
                && $run->deadline_at?->isPast()) {
                return ['action' => 'retry_auto_apply'];
            }

            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY) {
                if ($run->source_check_id === null) {
                    return ['action' => 'repair_awaiting'];
                }
                $source = $checks->get((int) $run->source_check_id);
                if ($source && (string) $source->status === 'completed') {
                    return ['action' => 'continue', 'check_id' => (int) $source->id];
                }
                if ($source && in_array((string) $source->status, ['failed', 'cancelled', 'stale'], true)) {
                    $this->finish($run, ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, 'full_quality_check_unavailable');

                    return ['action' => 'needs_review'];
                }

                return ['action' => 'none'];
            }

            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_EVALUATING) {
                $candidate = $checks->get((int) $stepInfo?->output_check_id);
                if ($candidate && (string) $candidate->status === 'completed') {
                    return ['action' => 'continue_candidate', 'check_id' => (int) $candidate->id];
                }
                if ($candidate && in_array((string) $candidate->status, ['failed', 'cancelled', 'stale'], true)) {
                    $this->finish($run, ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, 'candidate_quality_unavailable');

                    return ['action' => 'needs_review'];
                }

                return ['action' => 'none'];
            }

            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_APPLYING) {
                $final = $checks->get((int) $run->final_check_id);
                if ($final
                    && (string) $final->evaluation_mode === 'optimization_final'
                    && $run->applied_article_hash
                    && hash_equals((string) $run->applied_article_hash, $currentHash)) {
                    $run->forceFill([
                        'status' => ArticleAiOptimizationRun::STATUS_COMPLETED,
                        'active_dedupe_key' => null,
                        'lease_owner' => null,
                        'lease_expires_at' => null,
                        'finished_at' => $run->finished_at ?: now(),
                    ])->save();

                    return ['action' => 'continue_workflow', 'check_id' => (int) $final->id];
                }
                $this->finish($run, ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW, 'apply_state_uncertain');

                return ['action' => 'needs_review'];
            }

            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_QUEUED
                || ($run->lease_expires_at?->isPast()
                    && in_array((string) $run->status, [
                        ArticleAiOptimizationRun::STATUS_PLANNING,
                        ArticleAiOptimizationRun::STATUS_REWRITING,
                        ArticleAiOptimizationRun::STATUS_VALIDATING,
                    ], true))) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_QUEUED,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                ])->save();

                return ['action' => 'requeue'];
            }

            return ['action' => 'none'];
        }, 3);

        return match ($result['action'] ?? 'none') {
            'requeue' => $this->dispatchRun($runId),
            'continue' => $this->continueSourceCheck((int) ($result['check_id'] ?? 0)),
            'continue_candidate' => $this->continueCandidateCheck((int) ($result['check_id'] ?? 0)),
            'continue_workflow' => $this->continueWorkflow((int) ($result['check_id'] ?? 0)),
            'retry_auto_apply' => $this->retryAutoApply($runId),
            'repair_awaiting' => $this->repairAwaitingRun($runId),
            'recover_disabled_workflow' => $this->recoverDisabledWorkflow((int) ($result['check_id'] ?? 0)),
            default => (string) ($result['action'] ?? 'none'),
        };
    }

    private function dispatchRun(int $runId): string
    {
        $run = ArticleAiOptimizationRun::query()->find($runId);
        if (! $run) {
            return 'none';
        }
        $queue = (string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
            ? (string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk')
            : (string) config('geoflow.ai_quality_optimization_queue', 'ai-content-optimization');
        ProcessArticleAiOptimizationJob::dispatch($runId)
            ->onConnection('redis')
            ->onQueue($queue);

        return 'requeued';
    }

    private function repairAwaitingRun(int $runId): string
    {
        $run = ArticleAiOptimizationRun::query()->with('article')->find($runId);
        $model = AiModel::query()->find((int) data_get($run?->execution_meta, 'optimization_model_id', 0));
        if (! $run || ! $run->article || ! $model) {
            return 'none';
        }

        $this->coordinator->start(
            $run->article,
            (string) $run->strategy,
            $model,
            (string) $run->trigger,
            requestedByAdminId: $run->requested_by_admin_id ? (int) $run->requested_by_admin_id : null,
            dispatch: true,
            requestKey: (string) $run->request_key,
        );

        return 'requeued';
    }

    private function continueSourceCheck(int $checkId): string
    {
        if ($checkId > 0) {
            $this->coordinator->interceptCompletedWorkflow($checkId);
        }

        return 'continued';
    }

    private function continueCandidateCheck(int $checkId): string
    {
        if ($checkId > 0) {
            $this->coordinator->candidateCompleted($checkId);
        }

        return 'continued';
    }

    private function continueWorkflow(int $checkId): string
    {
        if ($checkId > 0) {
            $this->inspectionService->applyCompletedWorkflow($checkId);
        }

        return 'continued';
    }

    private function recoverDisabledWorkflow(int $checkId): string
    {
        if ($checkId > 0) {
            $this->coordinator->recoverWaitingWorkflow($checkId);
        }

        return 'workflow_recovered';
    }

    private function retryAutoApply(int $runId): string
    {
        return $this->coordinator->retryAutoApply($runId) ? 'continued' : 'none';
    }

    private function recoverCompletedWorkflows(int $limit): int
    {
        $checkIds = ArticleAiOptimizationRun::query()
            ->where('status', ArticleAiOptimizationRun::STATUS_COMPLETED)
            ->whereNotNull('final_check_id')
            ->where('updated_at', '>=', now()->subDay())
            ->oldest('updated_at')
            ->limit($limit)
            ->pluck('final_check_id');
        $recovered = 0;
        foreach ($checkIds as $checkId) {
            $check = ArticleAiQualityCheck::query()->find((int) $checkId);
            if (! $check || ! in_array((string) data_get($check->execution_meta, 'workflow_apply.status'), ['pending', 'processing'], true)) {
                continue;
            }
            $this->inspectionService->applyCompletedWorkflow((int) $check->id);
            $recovered++;
        }

        return $recovered;
    }

    private function recoverWaitingWorkflows(int $limit): int
    {
        $checks = ArticleAiQualityCheck::query()
            ->where('status', 'completed')
            ->where('gate_applied', true)
            ->where('execution_meta->workflow_apply->status', 'waiting_optimization')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('article_ai_optimization_runs')
                    ->whereColumn('article_ai_optimization_runs.source_check_id', 'article_ai_quality_checks.id')
                    ->whereIn('article_ai_optimization_runs.status', ArticleAiOptimizationRun::ACTIVE_STATUSES);
            })
            ->oldest('updated_at')
            ->limit($limit)
            ->get(['id']);
        $recovered = 0;
        foreach ($checks as $check) {
            if ($this->coordinator->recoverWaitingWorkflow((int) $check->id)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function finish(ArticleAiOptimizationRun $run, string $status, string $reason): void
    {
        $run->forceFill([
            'status' => $status,
            'stop_reason' => $reason,
            'active_dedupe_key' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'cancelled_at' => $status === ArticleAiOptimizationRun::STATUS_CANCELLED ? now() : null,
            'finished_at' => now(),
        ])->save();
    }
}
