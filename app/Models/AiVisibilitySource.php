<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiVisibilitySource extends Model
{
    protected $fillable = [
        'ai_visibility_run_id',
        'source_type',
        'citation_key',
        'title',
        'url',
        'domain',
        'site_name',
        'snippet',
        'summary',
        'content_excerpt',
        'published_at',
        'rank',
        'rank_score',
        'authority_level',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'ai_visibility_run_id' => 'integer',
            'published_at' => 'datetime',
            'rank' => 'integer',
            'rank_score' => 'float',
            'metadata_json' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiVisibilityRun::class, 'ai_visibility_run_id');
    }
}
