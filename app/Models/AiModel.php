<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class AiModel extends Model
{
    public const ACCESS_SCOPE_USER_CONTENT = 'user_content';

    public const ACCESS_SCOPE_SYSTEM_ONLY = 'system_only';

    protected $table = 'ai_models';

    protected $attributes = [
        'access_scope' => self::ACCESS_SCOPE_USER_CONTENT,
        'failover_priority' => 100,
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $fillable = [
        'name',
        'version',
        'api_key',
        'model_id',
        'model_type',
        'api_url',
        'failover_priority',
        'daily_limit',
        'used_today',
        'usage_date',
        'total_used',
        'status',
        'max_tokens',
        'ai_workspace_structured_output_status',
        'ai_workspace_structured_output_verified_at',
        'ai_workspace_readiness_status',
        'ai_workspace_readiness_profile',
        'ai_workspace_readiness_checked_at',
        'ai_workspace_readiness_expires_at',
        'ai_workspace_readiness_failure_code',
    ];

    protected function casts(): array
    {
        return [
            'owner_admin_id' => 'integer',
            'failover_priority' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'usage_date' => 'date',
            'total_used' => 'integer',
            'max_tokens' => 'integer',
            'ai_workspace_structured_output_verified_at' => 'datetime',
            'ai_workspace_readiness_profile' => 'array',
            'ai_workspace_readiness_checked_at' => 'immutable_datetime',
            'ai_workspace_readiness_expires_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AiModel $model): void {
            if ($model->isDirty(['version', 'model_id', 'model_type', 'api_url', 'api_key', 'status', 'max_tokens'])) {
                $model->ai_workspace_structured_output_status = null;
                $model->ai_workspace_structured_output_verified_at = null;
                if (Schema::hasColumn('ai_models', 'ai_workspace_readiness_status')) {
                    $model->ai_workspace_readiness_status = 'stale';
                    $model->ai_workspace_readiness_profile = null;
                    $model->ai_workspace_readiness_checked_at = null;
                    $model->ai_workspace_readiness_expires_at = null;
                    $model->ai_workspace_readiness_failure_code = null;
                }
            }
        });
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'ai_model_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function chatDefaultForAdmins(): HasMany
    {
        return $this->hasMany(AdminAiSetting::class, 'default_chat_model_id');
    }

    public function embeddingDefaultForAdmins(): HasMany
    {
        return $this->hasMany(AdminAiSetting::class, 'default_embedding_model_id');
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(AiModelUsageEvent::class, 'ai_model_id');
    }

    public function scopeOwnedBy(Builder $query, Admin|int $owner): Builder
    {
        return $query->where('owner_admin_id', $owner instanceof Admin ? $owner->getKey() : $owner);
    }

    public function scopeUserContent(Builder $query): Builder
    {
        return $query->where('access_scope', self::ACCESS_SCOPE_USER_CONTENT);
    }

    public function scopeSystemOnly(Builder $query): Builder
    {
        return $query->where('access_scope', self::ACCESS_SCOPE_SYSTEM_ONLY);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeInFailoverOrder(Builder $query): Builder
    {
        return $query->orderBy('failover_priority')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_model_id');
    }

    public function qualityTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_quality_model_id');
    }

    public function visibilityRuns(): HasMany
    {
        return $this->hasMany(AiVisibilityRun::class, 'ai_model_id');
    }

    public function currentUsage(): int
    {
        return $this->usage_date?->toDateString() === now()->toDateString()
            ? max(0, (int) ($this->used_today ?? 0))
            : 0;
    }

    public function scopeForCurrentUsageDay(Builder $query): Builder
    {
        return $query->whereDate('usage_date', now()->toDateString());
    }
}
