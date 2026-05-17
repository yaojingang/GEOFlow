<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoScore extends Model
{
    protected $table = 'geo_scores';

    protected $fillable = [
        'geo_answer_id',
        'brand_mentioned',
        'is_recommended',
        'rank_position',
        'competitors_mentioned',
        'citations',
        'score',
        'analysis_json',
    ];

    protected function casts(): array
    {
        return [
            'geo_answer_id' => 'integer',
            'brand_mentioned' => 'boolean',
            'is_recommended' => 'boolean',
            'rank_position' => 'integer',
            'competitors_mentioned' => 'array',
            'citations' => 'array',
            'score' => 'integer',
            'analysis_json' => 'array',
        ];
    }

    public function geoAnswer(): BelongsTo
    {
        return $this->belongsTo(GeoAnswer::class);
    }
}
