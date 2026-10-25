<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ArticleAiQualityReconciliationService
{
    public function __construct(
        private readonly ArticleAiQualityInspectionService $inspection,
        private readonly ArticleAiQualityWorkerLiveness $liveness,
    ) {}

    /** @return array{expired: int, degraded: int, recovered: int, workflows: int} */
    public function convergeExpired(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $staleBefore = now()->subSeconds((int) config('geoflow.ai_quality_recovery_stale_seconds', 60));
        $fullRequestStaleBefore = now()->subSeconds(
            (int) config('geoflow.ai_quality_request_timeout_seconds', 160) + 5,
        );
        $sampledRequestStaleBefore = now()->subSeconds(
            (int) config('geoflow.ai_quality_sampled_request_timeout_seconds', 35) + 5,
        );
        $staleChecks = ArticleAiQualityCheck::query()
            ->where(function ($query) use ($staleBefore, $fullRequestStaleBefore, $sampledRequestStaleBefore): void {
                $query->where(function ($queued) use ($staleBefore): void {
                    $queued->where('status', 'queued')->where('updated_at', '<=', $staleBefore);
                })->orWhere(function ($running) use ($fullRequestStaleBefore, $sampledRequestStaleBefore): void {
                    $running->where('status', 'running')->where(function ($scope) use ($fullRequestStaleBefore, $sampledRequestStaleBefore): void {
                        $scope->where(function ($full) use ($fullRequestStaleBefore): void {
                            $full->where('inspection_scope', 'full')
                                ->where('updated_at', '<=', $fullRequestStaleBefore);
                        })->orWhere(function ($sampled) use ($sampledRequestStaleBefore): void {
                            $sampled->where('inspection_scope', 'fallback_sampled')
                                ->where('updated_at', '<=', $sampledRequestStaleBefore);
                        });
                    });
                });
            })
            ->where(function ($query): void {
                $query->whereNull('deadline_at')->orWhere('deadline_at', '>', now());
            })
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
        $recovered = 0;
        foreach ($staleChecks as $check) {
            if ($this->inspection->recoverStuckCheck($check)) {
                $recovered++;
            }
        }

        $fallbackCutoff = now()->subSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
        $finalChecks = ArticleAiQualityCheck::query()
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($query) use ($fallbackCutoff): void {
                $query->where(function ($sampled): void {
                    $sampled->where('inspection_scope', 'fallback_sampled')
                        ->where(function ($deadline): void {
                            $deadline->where('sampled_deadline_at', '<=', now())
                                ->orWhere(function ($legacy): void {
                                    $legacy->whereNull('sampled_deadline_at')->where('deadline_at', '<=', now());
                                });
                        });
                })
                    ->orWhere(function ($full): void {
                        $full->where('inspection_scope', 'full')->where('deadline_at', '<=', now());
                    })
                    ->orWhere(function ($legacy) use ($fallbackCutoff): void {
                        $legacy->whereNull('deadline_at')->where('created_at', '<=', $fallbackCutoff);
                    });
            })
            ->orderByRaw('COALESCE(deadline_at, created_at)')
            ->limit($limit)
            ->get();

        $expired = 0;
        foreach ($finalChecks as $check) {
            $transitioned = $this->inspection->markFailed(
                $check,
                new ArticleAiQualityRuntimeException($this->liveness->expirationCode($check), true),
            );
            if ($transitioned) {
                $expired++;
            }
        }

        $remaining = max(0, $limit - $finalChecks->count());
        $primaryChecks = $remaining === 0 ? collect() : ArticleAiQualityCheck::query()
            ->whereIn('status', ['queued', 'running'])
            ->where('inspection_scope', 'full')
            ->where('primary_deadline_at', '<=', now())
            ->where('deadline_at', '>', now())
            ->orderBy('primary_deadline_at')
            ->limit($remaining)
            ->get();
        $degraded = 0;
        foreach ($primaryChecks as $check) {
            $exception = new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false);
            if ($this->inspection->tryStartSampledFallback($check, $exception)) {
                $degraded++;

                continue;
            }
            if ($this->inspection->markFailed($check, $exception)) {
                $expired++;
            }
        }

        return [
            'expired' => $expired,
            'degraded' => $degraded,
            'recovered' => $recovered,
            'workflows' => $this->recoverCompletedWorkflows($limit),
            'technical_retries' => $this->retryFailedChecks($limit),
            'delivery_handoffs' => app(ArticlePublicationDeliveryService::class)->recoverPending($limit),
            'delivery_queue_submissions' => app(DistributionOrchestrator::class)->recoverUndispatched($limit),
        ];
    }

    public static function isTransientFailure(string $code): bool
    {
        return in_array($code, [
            'queue_dispatch_failed', 'model_timeout', 'provider_timeout', 'provider_rate_limited',
            'provider_gateway_error', 'provider_circuit_open', 'worker_interrupted',
        ], true);
    }

    /** Resume only the unchanged scheduled retry chain; its attempt budget stays intact. */
    public function resumeForTask(int $taskId): int
    {
        $resumed = 0;
        foreach (ArticleAiQualityCheck::query()->where('task_id', $taskId)->where('status', 'failed')
            ->whereNotNull('execution_meta->technical_retry->next_at')->lazyById(100) as $candidate) {
            $resumed += DB::transaction(function () use ($candidate, $taskId): int {
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
                $article = Article::query()->whereKey($candidate->article_id)->lockForUpdate()->first();
                $check = ArticleAiQualityCheck::query()->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $task || $task->status !== 'active' || ! $task->schedule_enabled || ! $article || ! $check
                    || $check->status !== 'failed' || (int) $article->task_id !== $taskId
                    || $article->publication_intent !== 'scheduled' || $article->review_status === 'rejected'
                    || ! self::isTransientFailure((string) $check->error_code)
                    || $article->latestAiQualityCheck()->value('id') !== $check->id) {
                    return 0;
                }
                $article->setRelation('task', $task);
                $meta = (array) $check->execution_meta;
                $fence = (array) ($meta['workflow_fence'] ?? []);
                $retry = (array) ($meta['technical_retry'] ?? []);
                if (($fence['origin'] ?? '') !== 'automatic' || (int) ($fence['task_id'] ?? 0) !== $taskId
                    || (int) ($fence['workflow_version'] ?? 0) !== (int) $article->workflow_version
                    || (int) ($retry['attempt'] ?? 0) >= 2 || ! empty($retry['replacement_id']) || empty($retry['next_at'])) {
                    return 0;
                }
                $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article);
                if (! ($policy['required'] ?? false) || ! hash_equals((string) $check->input_fingerprint,
                    $this->inspection->currentFingerprint($article, $policy, $this->inspection->rules(),
                        app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id)))) {
                    return 0;
                }
                $meta['workflow_fence'] = app(ArticlePublicationEligibilityService::class)->fence($article);
                $check->update(['execution_meta' => $meta]);

                return 1;
            }, 3);
        }

        return $resumed;
    }

    /** Each failed check owns at most one replacement; the chain permits two additional attempts. */
    public function retryFailedChecks(int $limit = 100, array $articleIds = []): int
    {
        $checks = ArticleAiQualityCheck::query()->where('status', 'failed')
            ->when($articleIds !== [], fn ($query) => $query->whereIn('article_id', $articleIds))
            ->whereNotNull('execution_meta->technical_retry->next_at')
            ->whereNull('execution_meta->technical_retry->replacement_id')
            ->orderBy('id')->lazyById(100);
        $retried = 0;
        foreach ($checks as $candidate) {
            try {
                $retried += DB::transaction(function () use ($candidate): int {
                    $task = $candidate->task_id ? Task::query()->whereKey($candidate->task_id)->lockForUpdate()->first() : null;
                    $article = Article::query()->whereKey($candidate->article_id)->lockForUpdate()->first();
                    $check = ArticleAiQualityCheck::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $article || ! $check || (int) $article->task_id !== (int) $candidate->task_id || $check->status !== 'failed'
                        || ! $this->isTransientFailure((string) $check->error_code)
                        || ($task && ($task->status !== 'active' || ! $task->schedule_enabled))) {
                        return 0;
                    }
                    $article->setRelation('task', $task);
                    $meta = $check->execution_meta ?? [];
                    $retry = $meta['technical_retry'] ?? [];
                    $attempt = (int) ($retry['attempt'] ?? 0);
                    if ($attempt >= 2 || ! empty($retry['replacement_id']) || empty($retry['next_at'])
                        || Carbon::parse($retry['next_at'])->isFuture()
                        || (int) data_get($meta, 'workflow_fence.task_id', 0) !== (int) $article->task_id
                        || (int) data_get($meta, 'workflow_fence.workflow_version', 0) !== (int) $article->workflow_version
                        || (int) data_get($meta, 'workflow_fence.automation_version', 0) !== (int) ($task?->automation_version ?? 0)
                        || $article->latestAiQualityCheck()->value('id') !== $check->id) {
                        return 0;
                    }
                    $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article);
                    if (! ($policy['required'] ?? false)
                        || app(ArticleAiQualityBackfillGuard::class)->pauseReason($policy['model'] ?? null) !== null) {
                        return 0;
                    }
                    if (! hash_equals((string) $check->input_fingerprint, $this->inspection->currentFingerprint($article, $policy, $this->inspection->rules(), app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id)))) {
                        return 0;
                    }
                    $replacement = $this->inspection->createOrReuse($article, trigger: 'technical_retry', dispatch: false, force: true);
                    if (! $replacement || $replacement->id === $check->id) {
                        return 0;
                    }
                    $replacementMeta = $replacement->execution_meta ?? [];
                    $replacementMeta['technical_retry'] = ['attempt' => $attempt + 1, 'root_check_id' => (int) ($retry['root_check_id'] ?? $check->id)];
                    $replacementMeta['workflow_fence'] = $meta['workflow_fence'];
                    $replacementMeta['requested_workflow_state'] = $meta['requested_workflow_state'] ?? null;
                    $replacement->update(['execution_meta' => $replacementMeta]);
                    $meta['technical_retry']['replacement_id'] = (int) $replacement->id;
                    unset($meta['technical_retry']['next_at']);
                    $check->update(['execution_meta' => $meta]);
                    DB::afterCommit(fn () => $this->inspection->dispatchQueuedInspection($replacement));

                    return 1;
                }, 3);
            } catch (\Throwable $exception) {
                report($exception);
            }
            if ($retried >= max(1, min(100, $limit))) {
                break;
            }
        }

        return $retried;
    }

    public function recoverCompletedWorkflows(int $limit = 100): int
    {
        return $this->recoverCompletedWorkflowsQuery(ArticleAiQualityCheck::query(), $limit);
    }

    /** @param list<int> $articleIds */
    public function recoverCompletedWorkflowsForArticles(array $articleIds, int $limit = 100): int
    {
        $ids = collect($articleIds)->map('intval')->filter()->unique()->values()->all();
        if ($ids === []) {
            return 0;
        }

        return $this->recoverCompletedWorkflowsQuery(
            ArticleAiQualityCheck::query()->whereIn('article_id', $ids),
            $limit,
        );
    }

    private function recoverCompletedWorkflowsQuery(Builder $query, int $limit): int
    {
        $staleBefore = now()->subSeconds((int) config('geoflow.ai_quality_recovery_stale_seconds', 60));
        $checks = $query->where('status', 'completed')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereIn('execution_meta->workflow_apply->status', ['pending', 'failed'])
                    ->orWhere(function ($processing) use ($staleBefore): void {
                        $processing->where('execution_meta->workflow_apply->status', 'processing')
                            ->where('updated_at', '<=', $staleBefore);
                    });
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->get();

        foreach ($checks as $check) {
            $this->inspection->applyCompletedWorkflow($check);
        }

        return $checks->count();
    }
}
