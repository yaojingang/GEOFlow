<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoReferenceContentAnalysis extends Model
{
    protected $table = 'geo_reference_content_analyses';

    protected $fillable = [
        'organization_id',
        'geo_citation_source_id',
        'geo_citation_page_snapshot_id',
        'geo_reference_content_score_id',
        'article_title',
        'structure_json',
        'analysis_markdown',
        'markdown_path',
        'json_path',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_citation_source_id' => 'integer',
            'geo_citation_page_snapshot_id' => 'integer',
            'geo_reference_content_score_id' => 'integer',
            'structure_json' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function citationSource(): BelongsTo
    {
        return $this->belongsTo(GeoCitationSource::class, 'geo_citation_source_id');
    }

    public function pageSnapshot(): BelongsTo
    {
        return $this->belongsTo(GeoCitationPageSnapshot::class, 'geo_citation_page_snapshot_id');
    }

    public function referenceScore(): BelongsTo
    {
        return $this->belongsTo(GeoReferenceContentScore::class, 'geo_reference_content_score_id');
    }
}
