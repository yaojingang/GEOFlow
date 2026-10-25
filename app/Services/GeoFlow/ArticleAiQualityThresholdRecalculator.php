<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\Task;
use App\Support\GeoFlow\AiQualityRetrievalBasis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticleAiQualityThresholdRecalculator
{
    /** @return list<int> Articles whose complete result was safely reused. */
    public function forTask(int $taskId): array
    {
        $reused = [];
        foreach (Article::query()->where('task_id', $taskId)->where('status', '!=', 'published')->lazyById(100) as $candidate) {
            try {
                $success = DB::transaction(function () use ($taskId, $candidate): bool {
                    $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
                    $article = Article::query()->whereKey($candidate->id)->lockForUpdate()->first();
                    if (! $task || ! $article || (int) $article->task_id !== $taskId) {
                        return false;
                    }
                    $article->setRelation('task', $task);
                    $source = $article->latestAiQualityCheck()->lockForUpdate()->first();
                    if (! $source || $source->status !== 'completed' || $source->inspection_scope !== 'full'
                        || ! is_array($source->issues) || ! is_array($source->uncertainties) || ! is_array($source->dimension_scores)
                        || ! $source->knowledge_coverage || $source->truncated_issue_count
                        || data_get($source->execution_meta, 'raw_model_output_truncated')) {
                        return false;
                    }
                    $resolver = app(ArticleAiQualityPolicyResolver::class);
                    $policy = $resolver->resolve($article);
                    if (! ($policy['required'] ?? false)) {
                        return false;
                    }
                    $oldPolicy = array_replace($policy, [
                        'pass_score' => $source->pass_score,
                        'manual_override_min_score' => $source->manual_override_min_score,
                        'policy_version' => data_get($source->execution_meta, 'policy_snapshot.policy_version', 1),
                    ]);
                    $inspection = app(ArticleAiQualityInspectionService::class);
                    $selection = app(ArticleAiQualityVersionPolicy::class)->selection((int) $article->id);
                    if (! hash_equals((string) $source->input_fingerprint, $inspection->currentFingerprint($article, $oldPolicy, $inspection->rules(), $selection))
                        || ! $inspection->retrievalBasisMatches($source, $oldPolicy, $inspection->rules())) {
                        return false;
                    }
                    $scorer = app($source->scoring_version === 'v2' ? ArticleAiQualityScorerV2::class : ArticleAiQualityScorer::class);
                    $input = $source->only(['issues', 'uncertainties', 'knowledge_coverage', 'truncated_issue_count', 'promotion_context']);
                    $oldScore = $scorer->score($input, (int) $source->pass_score, (int) $source->manual_override_min_score);
                    if ((int) $oldScore['score'] !== (int) $source->score) {
                        return false;
                    }
                    $score = $scorer->score($input, (int) $policy['pass_score'], (int) $policy['manual_override_min_score']);
                    // Score-derived reasons must follow the new threshold; execution blockers remain.
                    $externalReasons = array_diff((array) $source->gate_reasons, ['score_below_threshold', 'confirmed_hard_blocker', 'model_output_truncated']);
                    $gateReasons = array_values(array_unique(array_merge($externalReasons, (array) ($score['gate_reasons'] ?? []))));
                    if ($score['decision'] === 'passed' && $gateReasons !== []) {
                        $score['decision'] = 'needs_review';
                    }
                    $meta = (array) $source->execution_meta;
                    $meta['policy_snapshot'] = $resolver->snapshot($policy);
                    $meta['score_policy'] = ['version' => ArticleAiQualityScorePolicy::VERSION, 'adjustments' => $score['score_adjustments'] ?? []];
                    $basis = (array) ($meta['retrieval_basis'] ?? []);
                    $newBasis = AiQualityRetrievalBasis::make(
                        (string) $source->requested_retrieval_mode, (int) $policy['policy_version'],
                        (array) ($basis['knowledge_sources'] ?? []), (array) ($basis['rollout'] ?? []),
                        (string) ($basis['strategy_version'] ?? ''), (array) ($basis['execution_options'] ?? []),
                    );
                    $meta['retrieval_basis'] = $newBasis->toArray();
                    $meta['threshold_recalculation'] = ['source_check_id' => (int) $source->id, 'at' => now()->toIso8601String()];
                    $meta['workflow_fence'] = app(ArticlePublicationEligibilityService::class)->fence(
                        $article, $article->publication_intent === 'immediate' ? 'manual' : 'automatic',
                    );
                    $meta['workflow_apply'] = ['status' => 'pending', 'attempts' => 0];
                    unset($meta['technical_retry'], $meta['publication_committed']);
                    if ($article->publication_intent !== 'immediate') {
                        unset($meta['requested_workflow_state']);
                    }
                    $check = $source->replicate();
                    $check->forceFill([
                        'request_key' => (string) Str::uuid(), 'active_dedupe_key' => null, 'supersedes_check_id' => $source->id,
                        'input_fingerprint' => $inspection->currentFingerprint($article, $policy, $inspection->rules(), $selection),
                        'retrieval_basis_hash' => $newBasis->hash(),
                        'pass_score' => $policy['pass_score'], 'manual_override_min_score' => $policy['manual_override_min_score'],
                        'decision' => $score['decision'], 'score' => $score['score'], 'gate_reasons' => $gateReasons,
                        'dimension_scores' => $score['dimension_scores'], 'issues' => $score['issues'], 'uncertainties' => $score['uncertainties'],
                        'execution_meta' => $meta, 'usage_meta' => [], 'is_overridden' => false,
                        'override_reason' => null, 'overridden_by' => null, 'overridden_by_name' => null, 'overridden_at' => null,
                        'started_at' => now(), 'finished_at' => now(),
                    ])->save();
                    foreach ($source->sources as $sourceRecord) {
                        $copy = $sourceRecord->replicate();
                        $copy->article_ai_quality_check_id = $check->id;
                        $copy->save();
                    }
                    app(AiQualityAuditService::class)->record('article_quality_thresholds_recalculated', [
                        'task_id' => $taskId, 'article_id' => (int) $article->id, 'article_ai_quality_check_id' => (int) $check->id,
                        'before_hash' => $source->input_fingerprint, 'after_hash' => $check->input_fingerprint,
                        'metadata' => ['source_check_id' => (int) $source->id],
                    ]);

                    return true;
                });
            } catch (\Throwable $exception) {
                report($exception);
                $success = false;
            }
            if ($success) {
                $reused[] = (int) $candidate->id;
            }
        }

        return $reused;
    }
}
