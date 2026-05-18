<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoReferenceContentScore extends Model
{
    protected $table = 'geo_reference_content_scores';

    protected $fillable = [
        'geo_citation_page_snapshot_id',
        'relevance_score',
        'structure_score',
        'actionability_score',
        'evidence_density_score',
        'brand_competitor_score',
        'total_score',
        'score_reason',
        'suggested_usage',
        'signals',
        'scored_at',
    ];

    protected function casts(): array
    {
        return [
            'geo_citation_page_snapshot_id' => 'integer',
            'relevance_score' => 'integer',
            'structure_score' => 'integer',
            'actionability_score' => 'integer',
            'evidence_density_score' => 'integer',
            'brand_competitor_score' => 'integer',
            'total_score' => 'integer',
            'signals' => 'array',
            'scored_at' => 'datetime',
        ];
    }

    public function pageSnapshot(): BelongsTo
    {
        return $this->belongsTo(GeoCitationPageSnapshot::class, 'geo_citation_page_snapshot_id');
    }
}
