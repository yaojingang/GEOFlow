<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoArticleDraft extends Model
{
    protected $table = 'geo_article_drafts';

    protected $fillable = [
        'organization_id',
        'geo_writing_task_id',
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
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
