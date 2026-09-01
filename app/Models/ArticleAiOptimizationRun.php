<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleAiOptimizationRun extends Model
{
    public const TRIGGER_TASK_AUTO = 'task_auto';

    public const TRIGGER_ADMIN_MANUAL = 'admin_manual';

    public const TRIGGER_API_MANUAL = 'api_manual';

    public const STATUS_AWAITING_QUALITY = 'awaiting_quality';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PLANNING = 'planning';

    public const STATUS_REWRITING = 'rewriting';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_EVALUATING = 'evaluating';

    public const STATUS_CANDIDATE_READY = 'candidate_ready';

    public const STATUS_APPLYING = 'applying';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STALE = 'stale';

    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_AWAITING_QUALITY,
        self::STATUS_QUEUED,
        self::STATUS_PLANNING,
        self::STATUS_REWRITING,
        self::STATUS_VALIDATING,
        self::STATUS_EVALUATING,
        self::STATUS_CANDIDATE_READY,
        self::STATUS_APPLYING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_NEEDS_REVIEW,
        self::STATUS_FAILED,
        self::STATUS_STALE,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'article_id',
        'task_id',
        'source_check_id',
        'best_check_id',
        'final_check_id',
        'requested_by_admin_id',
        'request_key',
        'trigger',
        'strategy',
        'target_score',
        'max_rounds',
        'completed_rounds',
        'status',
        'stop_reason',
        'error_code',
        'error_message',
        'base_article_hash',
        'candidate_hash',
        'applied_article_hash',
        'policy_hash',
        'active_dedupe_key',
        'lease_owner',
        'lease_expires_at',
        'deadline_at',
        'cancelled_at',
        'started_at',
        'finished_at',
        'usage_meta',
        'execution_meta',
    ];

    protected $attributes = [
        'strategy' => 'excellent_80',
        'target_score' => 85,
        'max_rounds' => 2,
        'completed_rounds' => 0,
        'status' => self::STATUS_QUEUED,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'task_id' => 'integer',
            'source_check_id' => 'integer',
            'best_check_id' => 'integer',
            'final_check_id' => 'integer',
            'requested_by_admin_id' => 'integer',
            'target_score' => 'integer',
            'max_rounds' => 'integer',
            'completed_rounds' => 'integer',
            'lease_expires_at' => 'datetime',
            'deadline_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'usage_meta' => 'array',
            'execution_meta' => 'array',
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

    public function sourceCheck(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'source_check_id');
    }

    public function bestCheck(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'best_check_id');
    }

    public function finalCheck(): BelongsTo
    {
        return $this->belongsTo(ArticleAiQualityCheck::class, 'final_check_id');
    }

    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by_admin_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ArticleAiOptimizationStep::class, 'run_id')->orderBy('round_index');
    }
}
