<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleAiQualityGate
{
    public function __construct(
        private readonly ArticleAiQualityPolicyResolver $policyResolver,
        private readonly ArticleAiQualityInspectionService $inspectionService,
        private readonly ArticleAiQualityVersionPolicy $versionPolicy,
    ) {}

    public function check(
        Article $article,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
    ): ?ArticleAiQualityCheck {
        $result = DB::transaction(function () use ($article, $trigger, $adminId, $overrideReason, $allowExistingOverride) {
            try {
                return $this->checkLocked(
                    $article,
                    $trigger,
                    $adminId,
                    $overrideReason,
                    $allowExistingOverride,
                );
            } catch (ArticleAiQualityGateException $exception) {
                return $exception;
            }
        });
        if ($result instanceof ArticleAiQualityGateException) {
            throw $result;
        }

        return $result;
    }

    private function checkLocked(
        Article $article,
        string $trigger,
        ?int $adminId,
        ?string $overrideReason,
        bool $allowExistingOverride,
    ): ?ArticleAiQualityCheck {
        $article = Article::query()->whereKey((int) $article->id)->lockForUpdate()->firstOrFail();
        if ($article->task_id) {
            $task = Task::withTrashed()
                ->whereKey((int) $article->task_id)
                ->lockForUpdate()
                ->first();
            if ($task instanceof Task) {
                $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                $article->setRelation('task', $task);
            }
        }
        $optimization = ArticleAiOptimizationRun::query()
            ->where('article_id', (int) $article->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();
        $explicitOverride = in_array($trigger, ['admin_ai_quality_override', 'api_ai_quality_override'], true);
        if ($optimization
            && in_array((string) $optimization->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)
            && ! $explicitOverride) {
            throw new ArticleAiQualityGateException(
                'article_ai_optimization_pending',
                'AI 内容优化正在进行，候选复检并应用后可继续发布。',
                $optimization->bestCheck ?: $optimization->sourceCheck,
            );
        }
        $policy = $this->policyResolver->resolve($article);
        if (! ($policy['required'] ?? false)) {
            return null;
        }

        try {
            $this->policyResolver->assertExecutable($policy);
        } catch (\Throwable) {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_failed',
                'AI 质检配置不可用，文章已暂停发布。',
            );
        }
        $policy['model_candidates'] = $this->policyResolver->modelCandidates($policy);
        $versionSelection = $this->versionPolicy->selection((int) $article->id);

        $currentFingerprint = $this->inspectionService->currentFingerprint(
            $article,
            $policy,
            $this->inspectionService->rules(),
            $versionSelection,
        );
        $check = ArticleAiQualityCheck::query()
            ->where('article_id', $article->id)
            ->where('gate_applied', true)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        $optimizationStillApplies = $optimization && $check && (
            in_array((int) $check->id, array_filter([
                (int) $optimization->source_check_id,
                (int) $optimization->best_check_id,
                (int) $optimization->final_check_id,
            ]), true)
            || ! $check->created_at
            || ! $optimization->updated_at
            || $check->created_at->lessThanOrEqualTo($optimization->updated_at)
        );
        if ($optimizationStillApplies && (string) $optimization->status === ArticleAiOptimizationRun::STATUS_STALE) {
            throw new ArticleAiQualityGateException(
                'article_ai_optimization_stale',
                'AI 优化候选已经过期，请重新质检或启动新的优化。',
                $optimization->sourceCheck,
            );
        }
        if ($optimizationStillApplies && (string) $optimization->status === ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW) {
            throw new ArticleAiQualityGateException(
                'article_ai_optimization_needs_review',
                'AI 优化未达到目标，文章需要人工检查。',
                $optimization->bestCheck ?: $optimization->sourceCheck,
            );
        }
        if ($optimizationStillApplies && (string) $optimization->status === ArticleAiOptimizationRun::STATUS_FAILED) {
            throw new ArticleAiQualityGateException(
                'article_ai_optimization_failed',
                'AI 优化执行异常，文章需要人工检查或重新启动优化。',
                $optimization->bestCheck ?: $optimization->sourceCheck,
            );
        }

        if ($check === null) {
            $check = $this->inspectionService->createOrReuse($article, trigger: $trigger);
            throw new ArticleAiQualityGateException(
                'article_ai_quality_pending',
                '文章正在等待 AI 质检，质检通过后可继续发布。',
                $check,
            );
        }

        if (! hash_equals((string) $check->input_fingerprint, $currentFingerprint)) {
            if (in_array((string) $check->status, ['queued', 'running', 'completed', 'failed'], true)) {
                $check->forceFill(['status' => 'stale', 'active_dedupe_key' => null])->save();
            }
            $replacement = $this->inspectionService->createOrReuse($article, trigger: $trigger, force: true);
            throw new ArticleAiQualityGateException(
                'article_ai_quality_stale',
                '文章或质检依据已经变化，系统正在重新质检。',
                $replacement ?: $check,
            );
        }

        if (in_array((string) $check->status, ['queued', 'running'], true)) {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_pending',
                'AI 质检仍在进行，文章已暂停发布。',
                $check,
            );
        }
        if ($check->status === 'stale') {
            $replacement = $this->inspectionService->createOrReuse($article, trigger: $trigger, force: true);
            throw new ArticleAiQualityGateException(
                'article_ai_quality_stale',
                'AI 质检结果已过期，系统正在重新质检。',
                $replacement ?: $check,
            );
        }
        if ($check->status === 'failed' || $check->decision === 'error') {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_failed',
                'AI 质检执行异常，文章已暂停发布，请重新质检。',
                $check,
            );
        }
        if ($check->status !== 'completed') {
            throw new ArticleAiQualityGateException(
                'article_ai_quality_pending',
                '文章尚未完成 AI 质检。',
                $check,
            );
        }

        if ((string) $check->inspection_scope === 'fallback_sampled'
            && ! $this->sampledResultCanAuthorize($check, $policy)) {
            ArticleAiQualityCheck::query()
                ->whereKey((int) $check->id)
                ->where('status', 'completed')
                ->where('inspection_scope', 'fallback_sampled')
                ->update([
                    'status' => 'stale',
                    'decision' => null,
                    'active_dedupe_key' => null,
                    'error_code' => 'sampling_policy_disabled',
                    'error_message' => '抽样质检授权或覆盖条件已失效，需要执行全文质检。',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            $replacement = $this->inspectionService->createOrReuse(
                $article,
                trigger: $trigger,
                force: true,
            );
            throw new ArticleAiQualityGateException(
                'article_ai_quality_sampled_stale',
                '抽样质检结果已失效，系统正在执行全文质检。',
                $replacement ?: $check->fresh(),
            );
        }

        if ($check->decision === 'passed') {
            return $check;
        }
        if ($check->decision === 'needs_review') {
            if ($allowExistingOverride && $check->is_overridden) {
                if ($explicitOverride && $optimization
                    && in_array((string) $optimization->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                    $this->cancelOptimizationForOverride($optimization, $adminId);
                }

                return $check;
            }

            $reason = $this->normalizeReason($overrideReason);
            $admin = $adminId ? Admin::query()->find($adminId) : null;
            if ($allowExistingOverride && $reason !== '' && $admin
                && (int) $check->score >= (int) $check->manual_override_min_score) {
                if ($explicitOverride && $optimization
                    && in_array((string) $optimization->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                    $this->cancelOptimizationForOverride($optimization, (int) $admin->id);
                }
                DB::transaction(function () use ($check, $admin, $reason): void {
                    ArticleAiQualityCheck::query()
                        ->whereKey($check->id)
                        ->where('status', 'completed')
                        ->where('decision', 'needs_review')
                        ->where('input_fingerprint', (string) $check->input_fingerprint)
                        ->where('is_overridden', false)
                        ->update([
                            'is_overridden' => true,
                            'override_reason' => $reason,
                            'overridden_by' => $admin->id,
                            'overridden_by_name' => $admin->name,
                            'overridden_at' => now(),
                            'updated_at' => now(),
                        ]);
                });

                return $check->fresh();
            }
        }

        throw new ArticleAiQualityGateException(
            'article_ai_quality_blocked',
            $check->decision === 'blocked'
                ? 'AI 质检发现严重问题，文章禁止发布。'
                : 'AI 质检未通过，文章需要人工审核。',
            $check,
        );
    }

    private function cancelOptimizationForOverride(ArticleAiOptimizationRun $run, ?int $adminId): void
    {
        $candidateIds = ArticleAiOptimizationStep::query()
            ->where('run_id', (int) $run->id)
            ->whereNotNull('output_check_id')
            ->orderBy('id')
            ->pluck('output_check_id');
        ArticleAiQualityCheck::query()
            ->whereIn('id', $candidateIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        ArticleAiOptimizationStep::query()
            ->where('run_id', (int) $run->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        ArticleAiQualityCheck::query()
            ->whereIn('id', $candidateIds)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'cancelled',
                'active_dedupe_key' => null,
                'error_code' => 'optimization_cancelled_by_quality_override',
                'error_message' => 'AI 优化已由人工质检放行操作取消。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_CANCELLED,
            'stop_reason' => 'quality_override',
            'active_dedupe_key' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'cancelled_at' => now(),
            'finished_at' => now(),
            'execution_meta' => array_replace($executionMeta, [
                'cancelled_by_quality_override_at' => now()->toIso8601String(),
                'cancelled_by_quality_override_admin_id' => $adminId,
            ]),
        ])->save();
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = Str::squish((string) $reason);

        return mb_strlen($reason, 'UTF-8') >= 4 ? mb_substr($reason, 0, 1000, 'UTF-8') : '';
    }

    /** @param array<string,mixed> $policy */
    private function sampledResultCanAuthorize(ArticleAiQualityCheck $check, array $policy): bool
    {
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $policySnapshot = is_array($executionMeta['policy_snapshot'] ?? null)
            ? $executionMeta['policy_snapshot']
            : [];
        $coverage = is_array($check->coverage_meta) ? $check->coverage_meta : [];

        return (bool) ($policySnapshot['timeout_sampling_enabled'] ?? false)
            && (bool) ($policy['timeout_sampling_enabled'] ?? false)
            && (string) ($policySnapshot['sampling_algorithm_version'] ?? '') === ArticleAiQualitySampleBuilder::ALGORITHM_VERSION
            && (int) ($policySnapshot['sampling_max_characters'] ?? 0) === (int) config('geoflow.ai_quality_sampled_max_characters', 6000)
            && (int) ($policySnapshot['sampling_max_ranges'] ?? 0) === (int) config('geoflow.ai_quality_sampled_max_ranges', 12)
            && (string) ($policySnapshot['risk_scan_algorithm_version'] ?? '') === ArticleRiskScanner::SCAN_ALGORITHM_VERSION
            && (string) ($coverage['algorithm_version'] ?? '') === ArticleAiQualitySampleBuilder::ALGORITHM_VERSION
            && $this->versionPolicy->sampledAutoReleaseEnabled()
            && (bool) ($coverage['safe_for_auto_release'] ?? false)
            && ! (bool) ($coverage['mandatory_overflow'] ?? true)
            && (int) ($coverage['mandatory_claims_total'] ?? -1) === (int) ($coverage['mandatory_claims_covered'] ?? -2)
            && array_values($coverage['regions_covered'] ?? []) === ['front', 'middle', 'back']
            && (string) ($coverage['deterministic_risk_status'] ?? 'clean') !== 'blocked'
            && (int) $check->score >= (int) $check->pass_score
            && array_values(is_array($check->gate_reasons) ? $check->gate_reasons : []) === [];
    }
}
