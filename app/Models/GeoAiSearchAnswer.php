<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoAiSearchAnswer extends Model
{
    protected $table = 'geo_ai_search_answers';

    protected $fillable = [
        'geo_ai_search_run_id',
        'geo_ai_search_question_id',
        'geo_keyword_opportunity_id',
        'platform_code',
        'prompt',
        'raw_answer',
        'status',
        'error_message',
        'brand_mentioned',
        'competitors_mentioned',
        'citations',
        'source_urls',
        'visibility_score',
        'analysis_json',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'geo_ai_search_run_id' => 'integer',
            'geo_ai_search_question_id' => 'integer',
            'geo_keyword_opportunity_id' => 'integer',
            'brand_mentioned' => 'boolean',
            'competitors_mentioned' => 'array',
            'citations' => 'array',
            'source_urls' => 'array',
            'visibility_score' => 'integer',
            'analysis_json' => 'array',
            'answered_at' => 'datetime',
        ];
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchRun::class, 'geo_ai_search_run_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchQuestion::class, 'geo_ai_search_question_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(GeoKeywordOpportunity::class, 'geo_keyword_opportunity_id');
    }

    public function citationOccurrences(): HasMany
    {
        return $this->hasMany(GeoCitationOccurrence::class, 'geo_ai_search_answer_id');
    }
}
