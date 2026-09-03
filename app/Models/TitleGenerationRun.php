<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TitleGenerationRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $hidden = [
        'dispatch_token',
        'lease_token',
        'model_access_admin_id',
        'model_access_admin_role',
        'ai_config_access_version',
        'requested_ai_model_id',
        'resolved_ai_model_id',
        'resolved_model_source',
        'resolver_policy_version',
        'custom_prompt',
    ];

    protected $fillable = [
        'title_library_id',
        'keyword_library_id',
        'ai_model_id',
        'created_by_admin_id',
        'status',
        'active_key',
        'requested_count',
        'batch_size',
        'batch_sequence',
        'requested_from_model_count',
        'generated_count',
        'saved_count',
        'duplicate_count',
        'invalid_count',
        'batch_count',
        'consecutive_empty_batches',
        'model_request_budget',
        'model_request_count',
        'title_style',
        'custom_prompt',
        'locale',
        'keyword_snapshot',
        'available_at',
        'dispatch_token',
        'lease_token',
        'lease_expires_at',
        'batch_attempt_count',
        'manual_retry_count',
        'failure_code',
        'last_error',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'batch_size' => 50,
        'batch_sequence' => 0,
        'requested_from_model_count' => 0,
        'generated_count' => 0,
        'saved_count' => 0,
        'duplicate_count' => 0,
        'invalid_count' => 0,
        'batch_count' => 0,
        'consecutive_empty_batches' => 0,
        'model_request_count' => 0,
        'batch_attempt_count' => 0,
        'manual_retry_count' => 0,
        'retryable_failure' => true,
        'locale' => 'zh_CN',
    ];

    protected function casts(): array
    {
        return [
            'title_library_id' => 'integer',
            'keyword_library_id' => 'integer',
            'ai_model_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'resolver_policy_version' => 'integer',
            'requested_count' => 'integer',
            'batch_size' => 'integer',
            'batch_sequence' => 'integer',
            'requested_from_model_count' => 'integer',
            'generated_count' => 'integer',
            'saved_count' => 'integer',
            'duplicate_count' => 'integer',
            'invalid_count' => 'integer',
            'batch_count' => 'integer',
            'consecutive_empty_batches' => 'integer',
            'model_request_budget' => 'integer',
            'model_request_count' => 'integer',
            'batch_attempt_count' => 'integer',
            'manual_retry_count' => 'integer',
            'retryable_failure' => 'boolean',
            'keyword_snapshot' => 'array',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'model_resolved_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function titleLibrary(): BelongsTo
    {
        return $this->belongsTo(TitleLibrary::class);
    }

    public function keywordLibrary(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class);
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
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

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_PARTIAL, self::STATUS_FAILED, self::STATUS_CANCELLED], true)
            && $this->ai_model_id !== null
            && (bool) $this->retryable_failure
            && (string) $this->failure_code !== 'request_budget_exhausted'
            && (int) $this->manual_retry_count < (int) config('geoflow.title_ai_max_manual_retries', 3);
    }

    public function progressPercent(): int
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return 100;
        }

        $requestedCount = max(1, (int) $this->requested_count);

        return min(99, (int) floor(((int) $this->saved_count * 100) / $requestedCount));
    }
}
