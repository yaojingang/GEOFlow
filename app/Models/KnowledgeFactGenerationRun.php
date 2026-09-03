<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeFactGenerationRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_OBSOLETE = 'obsolete';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
    ];

    protected $hidden = [
        'model_access_admin_id',
        'model_access_admin_role',
        'ai_config_access_version',
        'requested_ai_model_id',
        'resolved_ai_model_id',
        'resolved_model_source',
        'model_resolved_at',
        'resolver_policy_version',
        'execution_attempt',
        'batch_claims_json',
        'finalizer_lease_token',
        'finalizer_lease_expires_at',
        'prompt_version',
        'result_json',
        'batch_meta_json',
        'coverage_json',
        'usage_json',
        'result_hash',
        'error_message',
    ];

    protected $fillable = ['library_id', 'mode', 'target_count', 'source_hash', 'base_working_version', 'status', 'ai_model_id', 'created_by_admin_id', 'request_key', 'active_key', 'job_batch_id', 'prompt_version', 'result_json', 'batch_meta_json', 'coverage_json', 'usage_json', 'result_hash', 'error_message', 'cancel_requested_at', 'started_at', 'completed_at', 'failed_at', 'cancelled_at', 'diagnostic_payload_pruned_at'];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'prompt_version' => 'knowledge-facts-1.0.0',
        'retryable_failure' => true,
        'execution_attempt' => 1,
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'target_count' => 'integer',
            'base_working_version' => 'integer',
            'ai_model_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'resolver_policy_version' => 'integer',
            'retryable_failure' => 'boolean',
            'execution_attempt' => 'integer',
            'result_json' => 'array',
            'batch_meta_json' => 'array',
            'batch_claims_json' => 'array',
            'coverage_json' => 'array',
            'usage_json' => 'array',
            'model_resolved_at' => 'datetime',
            'finalizer_lease_expires_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'diagnostic_payload_pruned_at' => 'datetime',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFactLibrary::class, 'library_id');
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
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
