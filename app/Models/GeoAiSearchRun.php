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
        'target_keyword_hit_rate',
        'keyword_hit_rate',
        'previous_keyword_hit_rate',
        'baseline_keyword_hit_rate',
        'keyword_hit_rate_delta',
        'keyword_hit_count',
        'keyword_check_count',
        'optimization_directions',
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
            'target_keyword_hit_rate' => 'integer',
            'keyword_hit_rate' => 'integer',
            'previous_keyword_hit_rate' => 'integer',
            'baseline_keyword_hit_rate' => 'integer',
            'keyword_hit_rate_delta' => 'integer',
            'keyword_hit_count' => 'integer',
            'keyword_check_count' => 'integer',
            'optimization_directions' => 'array',
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

    public function citationOccurrences(): HasMany
    {
        return $this->hasMany(GeoCitationOccurrence::class, 'geo_ai_search_run_id');
    }
}
