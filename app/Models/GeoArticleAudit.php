<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoArticleAudit extends Model
{
    protected $table = 'geo_article_audits';

    protected $fillable = [
        'organization_id',
        'geo_article_draft_id',
        'article_id',
        'score',
        'passed_checks',
        'failed_checks',
        'suggestions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_article_draft_id' => 'integer',
            'article_id' => 'integer',
            'score' => 'integer',
            'passed_checks' => 'array',
            'failed_checks' => 'array',
            'suggestions' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
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
