<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoCitationSource extends Model
{
    protected $table = 'geo_citation_sources';

    protected $fillable = [
        'organization_id',
        'geo_ai_search_answer_id',
        'url',
        'domain',
        'title',
        'platform_name',
        'status',
        'citation_count',
        'first_seen_at',
        'last_seen_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_ai_search_answer_id' => 'integer',
            'citation_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function searchAnswer(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchAnswer::class, 'geo_ai_search_answer_id');
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(GeoCitationOccurrence::class, 'geo_citation_source_id');
    }

    public function pageSnapshots(): HasMany
    {
        return $this->hasMany(GeoCitationPageSnapshot::class, 'geo_citation_source_id');
    }

    public function referenceAnalyses(): HasMany
    {
        return $this->hasMany(GeoReferenceContentAnalysis::class, 'geo_citation_source_id');
    }

    public function latestPageSnapshot(): HasOne
    {
        return $this->hasOne(GeoCitationPageSnapshot::class, 'geo_citation_source_id')->latestOfMany();
    }

    public function latestReferenceAnalysis(): HasOne
    {
        return $this->hasOne(GeoReferenceContentAnalysis::class, 'geo_citation_source_id')->latestOfMany();
    }
}
