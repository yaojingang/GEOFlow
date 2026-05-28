<?php

namespace App\Models;

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
        'total_used',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'failover_priority' => 'integer',
            'daily_limit' => 'integer',
            'used_today' => 'integer',
            'total_used' => 'integer',
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
}
