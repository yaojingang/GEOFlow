<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoAiSearchRun extends Model
{
    protected $table = 'geo_ai_search_runs';

    protected $fillable = [
        'organization_id',
        'brand_profile_id',
        'created_by_admin_id',
        'name',
        'status',
        'platform_codes',
        'points_cost',
        'total_questions',
        'completed_questions',
        'failed_questions',
        'average_score',
        'started_at',
        'finished_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'brand_profile_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'platform_codes' => 'array',
            'points_cost' => 'integer',
            'total_questions' => 'integer',
            'completed_questions' => 'integer',
            'failed_questions' => 'integer',
            'average_score' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function brandProfile(): BelongsTo
    {
        return $this->belongsTo(BrandProfile::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(GeoAiSearchQuestion::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GeoAiSearchAnswer::class);
    }
}
