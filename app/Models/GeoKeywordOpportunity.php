<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoKeywordOpportunity extends Model
{
    protected $table = 'geo_keyword_opportunities';

    protected $fillable = [
        'organization_id',
        'brand_profile_id',
        'source_geo_keyword_id',
        'created_by_admin_id',
        'keyword',
        'intent',
        'cluster_name',
        'status',
        'business_value',
        'visibility_gap',
        'source_availability',
        'local_relevance',
        'opportunity_score',
        'generation_source',
        'rationale',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'brand_profile_id' => 'integer',
            'source_geo_keyword_id' => 'integer',
            'created_by_admin_id' => 'integer',
            'business_value' => 'integer',
            'visibility_gap' => 'integer',
            'source_availability' => 'integer',
            'local_relevance' => 'integer',
            'opportunity_score' => 'integer',
            'metadata' => 'array',
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

    public function sourceKeyword(): BelongsTo
    {
        return $this->belongsTo(GeoKeyword::class, 'source_geo_keyword_id');
    }

    public function searchQuestions(): HasMany
    {
        return $this->hasMany(GeoAiSearchQuestion::class);
    }

    public function searchAnswers(): HasMany
    {
        return $this->hasMany(GeoAiSearchAnswer::class);
    }
}
