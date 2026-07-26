<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiModel extends Model
{
    protected $table = 'ai_models';

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
    ];

    protected function casts(): array
    {
        return [
            'failover_priority' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'usage_date' => 'date',
            'total_used' => 'integer',
            'max_tokens' => 'integer',
        ];
    }

    public function titleLibraries(): HasMany
    {
        return $this->hasMany(TitleLibrary::class, 'ai_model_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ai_model_id');
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
