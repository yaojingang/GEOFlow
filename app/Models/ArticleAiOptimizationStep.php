<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleAiOptimizationStep extends Model
{
    protected $fillable = [
        'run_id',
        'round_index',
        'input_check_id',
        'output_check_id',
        'ai_model_id',
        'request_key',
        'status',
        'rejection_code',
        'rejection_message',
        'selected_causes',
        'patch_plan',
        'applied_patch',
        'validation',
        'before_hash',
        'after_hash',
        'before_score',
        'after_score',
        'before_decision',
        'after_decision',
        'usage_meta',
        'execution_meta',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'planning',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'round_index' => 'integer',
            'input_check_id' => 'integer',
            'output_check_id' => 'integer',
            'ai_model_id' => 'integer',
            'selected_causes' => 'array',
            'patch_plan' => 'array',
            'applied_patch' => 'array',
            'validation' => 'array',
            'before_score' => 'integer',
            'after_score' => 'integer',
            'usage_meta' => 'array',
            'execution_meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ArticleAiOptimizationRun::class, 'run_id');
    }

    public function inputCheck(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'input_check_id');
    }

    public function outputCheck(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'output_check_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }
}
