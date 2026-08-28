<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleAiQualityGate
{
    public function __construct(
        private readonly ArticleAiQualityPolicyResolver $policyResolver,
        private readonly ArticleAiQualityInspectionService $inspectionService,
        private readonly ArticleAiQualityFingerprint $fingerprint,
    ) {}

    public function check(
        Article $article,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
    ): ?ArticleAiQualityCheck {
        $article = Article::query()->findOrFail((int) $article->id);
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

        $currentFingerprint = $this->fingerprint->make(
            $this->policyResolver->fingerprintInput($article, $policy, $this->inspectionService->rules()),
        );
        $check = ArticleAiQualityCheck::query()
            ->where('article_id', $article->id)
            ->latest('id')
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
                        ->where('decision', 'needs_review')
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
}
