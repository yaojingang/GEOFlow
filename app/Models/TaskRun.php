<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskRun extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'task_runs';

    protected $hidden = [
        'execution_lease_token',
    ];

    protected $fillable = [
        'task_id',
        'status',
        'article_id',
        'error_message',
        'duration_ms',
        'meta',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'task_id' => 'integer',
            'article_id' => 'integer',
            'duration_ms' => 'integer',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'model_resolved_at' => 'datetime',
            'resolver_policy_version' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function modelAccessAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'model_access_admin_id');
    }

    public function requestedAiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'requested_ai_model_id');
    }

    public function resolvedAiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'resolved_ai_model_id');
    }
}
