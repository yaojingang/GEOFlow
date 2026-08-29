<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Models\Admin;
use App\Models\Article;
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
                return $check;
            }

            $reason = $this->normalizeReason($overrideReason);
            $admin = $adminId ? Admin::query()->find($adminId) : null;
            if ($allowExistingOverride && $reason !== '' && $admin
                && (int) $check->score >= (int) $check->manual_override_min_score) {
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
