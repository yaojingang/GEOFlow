<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoAiSearchQuestion extends Model
{
    protected $table = 'geo_ai_search_questions';

    protected $fillable = [
        'geo_ai_search_run_id',
        'geo_keyword_opportunity_id',
        'question',
        'intent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'geo_ai_search_run_id' => 'integer',
            'geo_keyword_opportunity_id' => 'integer',
        ];
    }

    public function searchRun(): BelongsTo
    {
        return $this->belongsTo(GeoAiSearchRun::class, 'geo_ai_search_run_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(GeoKeywordOpportunity::class, 'geo_keyword_opportunity_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(GeoAiSearchAnswer::class, 'geo_ai_search_question_id');
    }
}
