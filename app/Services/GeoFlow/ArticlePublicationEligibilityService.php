<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Support\Arr;

/** Read-only publication conditions; never dispatches a model or changes an article. */
class ArticlePublicationEligibilityService
{
    public function __construct(
        private readonly ArticleAiQualityPolicyResolver $policies,
        private readonly ArticleRiskScanner $riskScanner,
    ) {}

    public function manualReviewRequired(Article $article): bool
    {
        return (bool) ($article->task?->need_review
            ?? data_get($article->ai_quality_policy_snapshot, 'manual_review_required', true));
    }

    public function hasCurrentApproval(Article $article): bool
    {
        if ($article->review_status !== 'approved') {
            return false;
        }
        $review = $article->reviews()->latest('id')->first();

        // Historical approval has no invented reviewer; migration holds its publication intent.
        return $review === null || ($review->review_status === 'approved'
            && ($review->content_hash === null || hash_equals($review->content_hash, $article->reviewContentHash())));
    }

    public function reviewStatus(Article $article): string
    {
        if ($article->review_status === 'rejected') {
            return 'rejected';
        }
        if ($this->hasCurrentApproval($article)) {
            return 'approved';
        }

        return $this->manualReviewRequired($article) ? 'pending' : 'auto_approved';
    }

    /** @return array<string, mixed> */
    public function fence(Article $article, string $origin = 'automatic'): array
    {
        return [
            'workflow_version' => (int) $article->workflow_version,
            'task_id' => $article->task_id ? (int) $article->task_id : null,
            'automation_version' => (int) ($article->task?->automation_version ?? 0),
            'origin' => $origin,
        ];
    }

    /** Legacy callbacks retain reports but cannot advance publication. */
    public function fenceAllows(Article $article, mixed $fence): bool
    {
        if (! is_array($fence) || ! isset($fence['workflow_version'])
            || (int) $fence['workflow_version'] !== (int) $article->workflow_version
            || (int) ($fence['task_id'] ?? 0) !== (int) ($article->task_id ?? 0)
            || (int) ($fence['automation_version'] ?? 0) !== (int) ($article->task?->automation_version ?? 0)
            || in_array($article->publication_intent, ['hold', 'none'], true)
            || $article->review_status === 'rejected') {
            return false;
        }
        $task = $article->task;

        return ! $task || ($task->status === 'active' && (bool) $task->schedule_enabled)
            || (($fence['origin'] ?? '') === 'manual' && $article->publication_intent === 'immediate');
    }

    /** Only an unchanged article with a renewed publication intent may replace a superseded run. */
    public function canRestartOptimizationForCurrentIntent(Article $article, ArticleAiOptimizationRun $run): bool
    {
        if ($run->trigger !== ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
            || ! $this->currentIntentAllowsOptimization($article)
            || ! hash_equals((string) $run->base_article_hash,
                $this->riskScanner->contentHash($this->policies->articleSnapshot($article)))) {
            return false;
        }
        if (in_array($run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
            return ! $this->fenceAllows($article, data_get($run->execution_meta, 'workflow_fence'));
        }

        return ($run->status === ArticleAiOptimizationRun::STATUS_STALE && $run->stop_reason === 'workflow_intent_changed')
            || ($run->status === ArticleAiOptimizationRun::STATUS_CANCELLED && in_array($run->stop_reason, [
                'task_auto_optimization_cancelled', 'task_auto_optimization_disabled', 'optimization_feature_disabled',
            ], true));
    }

    public function currentIntentAllowsOptimization(Article $article): bool
    {
        if (! $article->task?->ai_quality_enabled || ! $article->task?->ai_quality_auto_optimize_enabled
            || ! in_array($article->status, ['draft', 'private'], true)
            || ! in_array($article->publication_intent, ['scheduled', 'immediate'], true)) {
            return false;
        }
        foreach (['ai_quality_optimization', 'ai_quality_optimization_auto_apply'] as $capability) {
            if (! config('geoflow.'.$capability.'_enabled') || (int) config('geoflow.'.$capability.'_percent') !== 100) {
                return false;
            }
        }

        return $this->fenceAllows($article, $this->fence(
            $article, $article->publication_intent === 'immediate' ? 'manual' : 'automatic',
        ));
    }

    public function optimizationBlockReason(Article $article, ?ArticleAiQualityCheck $check, array $policy): ?string
    {
        if (! ($policy['required'] ?? false) || ! $article->task?->ai_quality_auto_optimize_enabled
            || ($check?->status === 'completed' && $check->decision === 'needs_review' && $check->is_overridden)) {
            return null;
        }
        foreach (['ai_quality_optimization', 'ai_quality_optimization_auto_apply'] as $capability) {
            if (! config('geoflow.'.$capability.'_enabled') || (int) config('geoflow.'.$capability.'_percent') !== 100) {
                return 'optimization_capability_unavailable';
            }
        }
        $goal = app(ArticleAiOptimizationPolicy::class)->resolve(
            (string) $article->task->ai_quality_optimization_level,
            (int) ($policy['pass_score'] ?? 85),
        );

        return $check?->status === 'completed' && $check->decision === 'passed'
            && (int) $check->score >= $goal['target_score'] ? null : 'optimization_target_not_met';
    }

    public function qualityBasisCurrent(Article $article, ArticleAiQualityCheck $check, array $policy): bool
    {
        try {
            $this->policies->assertExecutable($policy);
            $inspection = app(ArticleAiQualityInspectionService::class);

            return hash_equals((string) $check->input_fingerprint, $inspection->currentFingerprint(
                $article, $policy, $inspection->rules(), app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id),
            )) && $inspection->retrievalBasisMatches($check, $policy, $inspection->rules());
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function evaluate(Article $article): array
    {
        $policy = $this->policies->resolve($article);
        $task = $article->task;
        $reasons = [];
        $reviewStatus = $this->reviewStatus($article);
        if ($reviewStatus === 'rejected') {
            $reasons[] = 'manual_rejected';
        } elseif ($reviewStatus === 'pending') {
            $reasons[] = 'manual_review_required';
        }
        $scan = $article->latestRiskScan;
        if (! $scan || ! $this->riskScanner->isFresh($article, $scan)) {
            $reasons[] = 'risk_check_required';
        } elseif ($scan->status !== 'clean' && ! ($scan->status === 'warning' && $scan->is_overridden)) {
            $reasons[] = 'risk_'.$scan->status;
        }
        $check = $article->latestAiQualityCheck;
        if ($policy['required'] ?? false) {
            if (! $check) {
                $reasons[] = 'ai_quality_queued';
            } elseif (! $this->qualityBasisCurrent($article, $check, $policy)) {
                $reasons[] = 'ai_quality_stale';
            } elseif ($check->status !== 'completed') {
                $reasons[] = $check->status === 'failed' && data_get($check->execution_meta, 'technical_retry.next_at')
                    ? 'ai_quality_retry_waiting' : 'ai_quality_'.$check->status;
            } elseif ($check->inspection_scope === 'fallback_sampled' && ! app(ArticleAiQualityGate::class)->sampledResultCanAuthorize($check, $policy)) {
                $reasons[] = 'ai_quality_sampled_stale';
            } elseif ($check->decision !== 'passed' && ! ($check->decision === 'needs_review' && $check->is_overridden)) {
                $reasons[] = 'ai_quality_'.$check->decision;
            }
            if ($optimizationReason = $this->optimizationBlockReason($article, $check, $policy)) {
                $reasons[] = $optimizationReason;
            }
            $optimization = $article->latestAiOptimizationRun;
            if ($optimization?->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO && ! $task?->ai_quality_auto_optimize_enabled) {
                $optimization = null;
            }
            if ($optimization && in_array($optimization->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                $reasons[] = 'optimization_'.$optimization->status;
            } elseif ($optimization && $check && in_array($optimization->status, ['needs_review', 'failed', 'stale'], true)) {
                $stillApplies = in_array((int) $check->id, array_filter([(int) $optimization->source_check_id, (int) $optimization->best_check_id, (int) $optimization->final_check_id]), true)
                    || ! $check->created_at || ! $optimization->updated_at || $check->created_at->lessThanOrEqualTo($optimization->updated_at);
                if ($stillApplies && ($optimization->trigger !== ArticleAiOptimizationRun::TRIGGER_TASK_AUTO || $optimizationReason !== null)) {
                    $reasons[] = 'optimization_'.$optimization->status;
                }
            }
        }
        if ($article->publication_intent === 'hold') {
            $reasons[] = 'manual_hold';
        } elseif ($article->publication_intent === 'none' && $article->status !== 'published'
            && ! ($article->status === 'private' && $task?->publish_scope === 'distribution_only')) {
            $reasons[] = 'publication_not_requested';
        }
        if ($article->publication_intent === 'scheduled' && $task) {
            if ($task->status !== 'active' || ! $task->schedule_enabled) {
                $reasons[] = 'task_paused';
            }
            if ($task->next_publish_at?->isFuture()) {
                $reasons[] = 'publication_interval';
            }
        }
        $state = match (true) {
            $article->status === 'published' => 'published',
            in_array('manual_hold', $reasons, true) => 'held',
            in_array('ai_quality_retry_waiting', $reasons, true) => 'retry_waiting',
            in_array('ai_quality_failed', $reasons, true) => 'execution_error',
            in_array('manual_rejected', $reasons, true) => 'content_needs_changes',
            in_array('ai_quality_running', $reasons, true) => 'quality_running',
            in_array('ai_quality_queued', $reasons, true) || in_array('ai_quality_stale', $reasons, true) => 'quality_waiting',
            in_array('manual_review_required', $reasons, true) => 'manual_review',
            $reasons === [] || $reasons === ['publication_interval'] => 'ready',
            default => 'blocked',
        };

        return [
            'delivery_handoff' => Arr::only((array) $article->latestPublicationHandoff?->context, ['status', 'attempts', 'next_at', 'error']),
            'status' => $article->status,
            'state' => $state,
            'review_required' => $this->manualReviewRequired($article),
            'review_status' => $reviewStatus,
            'risk_status' => $scan?->status,
            'ai_quality_required' => (bool) ($policy['required'] ?? false),
            'ai_quality_status' => $check?->status,
            'ai_quality_decision' => $check?->decision,
            'publication_intent' => $article->publication_intent,
            'workflow_version' => (int) $article->workflow_version,
            'automation_version' => (int) ($task?->automation_version ?? 0),
            'config_version' => (int) ($task?->ai_quality_config_version ?? $article->ai_quality_policy_version ?? 1),
            'next_publish_at' => $task?->next_publish_at?->toIso8601String(),
            'blocking_reasons' => array_values(array_unique($reasons)),
            'ai_quality_gate_reasons' => (array) $check?->gate_reasons,
            'technical_retry' => data_get($check?->execution_meta, 'technical_retry'),
            'workflow_apply' => data_get($check?->execution_meta, 'workflow_apply'),
            'can_publish' => $reasons === [] && $article->publication_intent !== 'none',
            'actions' => array_values(array_filter(['approve', 'reject', 'hold', 'private', $task ? 'schedule' : null, 'publish'])),
        ];
    }
}
