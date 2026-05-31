<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoCitationOccurrence extends Model
{
    protected $table = 'geo_citation_occurrences';

    protected $fillable = [
        'organization_id',
        'geo_citation_source_id',
        'geo_ai_search_run_id',
        'geo_ai_search_question_id',
        'geo_ai_search_answer_id',
        'geo_keyword_opportunity_id',
        'platform_code',
        'url',
        'domain',
        'cited_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_citation_source_id' => 'integer',
            'geo_ai_search_run_id' => 'integer',
            'geo_ai_search_question_id' => 'integer',
            'geo_ai_search_answer_id' => 'integer',
            'geo_keyword_opportunity_id' => 'integer',
            'cited_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(GeoCitationSource::class, 'geo_citation_source_id');
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchRun::class, 'geo_ai_search_run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchQuestion::class, 'geo_ai_search_question_id');
    }

    public function searchAnswer(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchAnswer::class, 'geo_ai_search_answer_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(GeoKeywordOpportunity::class, 'geo_keyword_opportunity_id');
    }
}
