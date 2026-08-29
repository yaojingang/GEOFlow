<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Database\Eloquent\Builder;

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
        ];
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
