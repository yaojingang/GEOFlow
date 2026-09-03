<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnterpriseKnowledgeProject extends Model
{
    protected $attributes = [
        'retryable_failure' => true,
        'execution_attempt' => 0,
    ];

    protected $hidden = [
        'execution_lease_token',
    ];

    protected $fillable = [
        'name',
        'description',
        'status',
        'draft_content',
        'structured_json',
        'validation_json',
        'published_knowledge_base_id',
        'ai_model_id',
        'error_message',
        'error_code',
        'retryable_failure',
        'created_by_admin_id',
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
    ];

    protected function casts(): array
    {
        return [
            'published_knowledge_base_id' => 'integer',
            'ai_model_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'retryable_failure' => 'boolean',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolver_policy_version' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'execution_attempt' => 'integer',
            'model_resolved_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeSource::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(EnterpriseKnowledgeRevision::class)->latest();
    }

    public function publishedKnowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'published_knowledge_base_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
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

    /**
     * @return list<array<string,mixed>>
     */
    public function validationItems(): array
    {
        $decoded = json_decode((string) ($this->validation_json ?? ''), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function structuredData(): array
    {
        $decoded = json_decode((string) ($this->structured_json ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function draftGenerationProgress(): array
    {
        $progress = $this->structuredData()['draft_generation'] ?? [];

        return is_array($progress) ? $progress : [];
    }
}
