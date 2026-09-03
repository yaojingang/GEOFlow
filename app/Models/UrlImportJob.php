<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UrlImportJob extends Model
{
    protected $table = 'url_import_jobs';

    protected $attributes = [
        'retryable_failure' => true,
        'execution_attempt' => 0,
    ];

    protected $hidden = [
        'execution_lease_token',
    ];

    protected $fillable = [
        'url',
        'normalized_url',
        'source_domain',
        'page_title',
        'status',
        'current_step',
        'progress_percent',
        'options_json',
        'result_json',
        'error_message',
        'error_code',
        'retryable_failure',
        'created_by',
        'model_access_admin_id',
        'model_access_admin_role',
        'ai_config_access_version',
        'requested_ai_model_id',
        'requested_ai_model_snapshot',
        'resolver_policy_version',
        'resolved_ai_model_id',
        'resolved_ai_model_snapshot',
        'resolved_model_source',
        'model_resolved_at',
        'execution_lease_token',
        'execution_attempt',
        'lease_expires_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'progress_percent' => 'integer',
            'retryable_failure' => 'boolean',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolver_policy_version' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'model_resolved_at' => 'datetime',
            'execution_attempt' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UrlImportJobLog::class, 'job_id');
    }

    public function executionAdmin(): BelongsTo
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
