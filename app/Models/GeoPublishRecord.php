<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoPublishRecord extends Model
{
    protected $table = 'geo_publish_records';

    protected $fillable = [
        'geo_article_draft_id',
        'geo_publish_target_id',
        'status',
        'target_url',
        'error_message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'geo_article_draft_id' => 'integer',
            'geo_publish_target_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function articleDraft(): BelongsTo
    {
        return $this->belongsTo(GeoArticleDraft::class, 'geo_article_draft_id');
    }
}
