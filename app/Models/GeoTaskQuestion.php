<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoTaskQuestion extends Model
{
    protected $table = 'geo_task_questions';

    protected $fillable = [
        'geo_task_id',
        'geo_keyword_id',
        'question',
        'platform_codes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'geo_task_id' => 'integer',
            'geo_keyword_id' => 'integer',
            'platform_codes' => 'array',
        ];
    }

    public function geoTask(): BelongsTo
    {
        return $this->belongsTo(GeoTask::class);
    }

    public function geoKeyword(): BelongsTo
    {
        return $this->belongsTo(GeoKeyword::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GeoAnswer::class);
    }
}
