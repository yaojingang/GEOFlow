<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoArticleDraft extends Model
{
    protected $table = 'geo_article_drafts';

    protected $fillable = [
        'organization_id',
        'geo_writing_task_id',
        'article_id',
        'title',
        'summary',
        'content_markdown',
        'content_html',
        'seo_title',
        'seo_description',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'geo_writing_task_id' => 'integer',
            'article_id' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function writingTask(): BelongsTo
    {
        return $this->belongsTo(GeoWritingTask::class, 'geo_writing_task_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(GeoArticleAudit::class, 'geo_article_draft_id');
    }

    public function publishRetests(): HasMany
    {
        return $this->hasMany(GeoPublishRetest::class, 'geo_article_draft_id');
    }

    public function publishRecords(): HasMany
    {
        return $this->hasMany(GeoPublishRecord::class, 'geo_article_draft_id');
    }
}
