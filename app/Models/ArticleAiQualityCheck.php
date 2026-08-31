<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleAiQualityCheck extends Model
{
    protected $table = 'article_ai_quality_checks';

    protected $fillable = [
        'article_id',
        'task_id',
        'task_run_id',
        'prompt_id',
        'ai_model_id',
        'supersedes_check_id',
        'request_key',
        'active_dedupe_key',
        'status',
        'attempt_count',
        'decision',
        'score',
        'pass_score',
        'manual_override_min_score',
        'summary',
        'promotion_context',
        'knowledge_coverage',
        'dimension_scores',
        'issues',
        'uncertainties',
        'segment_count',
        'completed_segment_count',
        'article_snapshot',
        'fact_candidates_snapshot',
        'evidence_snapshot',
        'prompt_template_snapshot',
        'advertising_rules_snapshot',
        'model_snapshot',
        'raw_model_output',
        'article_content_hash',
        'prompt_hash',
        'knowledge_hash',
        'input_fingerprint',
        'algorithm_version',
        'gate_applied',
        'evaluation_mode',
        'inspection_scope',
        'requested_retrieval_mode',
        'effective_retrieval_mode',
        'retrieval_strategy_version',
        'retrieval_failure_code',
        'retrieval_basis_hash',
        'fallback_trigger_code',
        'baseline_check_id',
        'scoring_version',
        'confidence',
        'gate_reasons',
        'truncated_issue_count',
        'error_code',
        'error_message',
        'usage_meta',
        'execution_meta',
        'coverage_meta',
        'is_overridden',
        'override_reason',
        'overridden_by',
        'overridden_by_name',
        'overridden_at',
        'started_at',
        'primary_deadline_at',
        'sampled_deadline_at',
        'deadline_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'queued',
        'attempt_count' => 0,
        'pass_score' => 85,
        'manual_override_min_score' => 70,
        'segment_count' => 0,
        'completed_segment_count' => 0,
        'is_overridden' => false,
        'gate_applied' => true,
        'evaluation_mode' => 'primary',
        'inspection_scope' => 'full',
        'scoring_version' => 'v1',
        'truncated_issue_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'task_id' => 'integer',
            'task_run_id' => 'integer',
            'prompt_id' => 'integer',
            'ai_model_id' => 'integer',
            'supersedes_check_id' => 'integer',
            'baseline_check_id' => 'integer',
            'attempt_count' => 'integer',
            'score' => 'integer',
            'pass_score' => 'integer',
            'manual_override_min_score' => 'integer',
            'dimension_scores' => 'array',
            'issues' => 'array',
            'uncertainties' => 'array',
            'segment_count' => 'integer',
            'completed_segment_count' => 'integer',
            'article_snapshot' => 'array',
            'fact_candidates_snapshot' => 'array',
            'evidence_snapshot' => 'array',
            'advertising_rules_snapshot' => 'array',
            'model_snapshot' => 'array',
            'raw_model_output' => 'array',
            'usage_meta' => 'array',
            'execution_meta' => 'array',
            'coverage_meta' => 'array',
            'gate_applied' => 'boolean',
            'confidence' => 'float',
            'gate_reasons' => 'array',
            'truncated_issue_count' => 'integer',
            'is_overridden' => 'boolean',
            'overridden_at' => 'datetime',
            'started_at' => 'datetime',
            'primary_deadline_at' => 'datetime',
            'sampled_deadline_at' => 'datetime',
            'deadline_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function supersededCheck(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_check_id');
    }

    public function baselineCheck(): BelongsTo
    {
        return $this->belongsTo(self::class, 'baseline_check_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(ArticleAiQualitySegment::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ArticleAiQualityCheckSource::class);
    }

    public function sourceOptimizationRuns(): HasMany
    {
        return $this->hasMany(ArticleAiOptimizationRun::class, 'source_check_id');
    }
}
