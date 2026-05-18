<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoPublishRetest extends Model
{
    protected $table = 'geo_publish_retests';

    protected $fillable = [
        'organization_id',
        'article_id',
        'geo_article_draft_id',
        'before_score',
        'after_score',
        'status',
        'article_url',
        'summary',
        'metadata',
        'tested_at',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'article_id' => 'integer',
            'geo_article_draft_id' => 'integer',
            'before_score' => 'integer',
            'after_score' => 'integer',
            'metadata' => 'array',
            'tested_at' => 'datetime',
        ];
    }

    public function articleDraft(): BelongsTo
    {
        return $this->belongsTo(GeoArticleDraft::class, 'geo_article_draft_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
