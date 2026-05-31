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
        'platform_codes',
        'handoff_payload',
        'status',
        'target_url',
        'error_message',
        'submitted_at',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'geo_article_draft_id' => 'integer',
            'geo_publish_target_id' => 'integer',
            'platform_codes' => 'array',
            'handoff_payload' => 'array',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function articleDraft(): BelongsTo
    {
        return $this->belongsTo(GeoArticleDraft::class, 'geo_article_draft_id');
    }

    public function publishTarget(): BelongsTo
    {
        return $this->belongsTo(GeoPublishTarget::class, 'geo_publish_target_id');
    }
}
