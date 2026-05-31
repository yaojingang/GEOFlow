<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeoCitationPageSnapshot extends Model
{
    protected $table = 'geo_citation_page_snapshots';

    protected $fillable = [
        'geo_citation_source_id',
        'url',
        'domain',
        'title',
        'description',
        'content_summary',
        'content_text',
        'http_status',
        'crawl_status',
        'error_message',
        'content_hash',
        'crawled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'geo_citation_source_id' => 'integer',
            'http_status' => 'integer',
            'crawled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function citationSource(): BelongsTo
    {
        return $this->belongsTo(GeoCitationSource::class, 'geo_citation_source_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(GeoReferenceContentScore::class, 'geo_citation_page_snapshot_id');
    }

    public function analyses(): HasMany
    {
        return $this->hasMany(GeoReferenceContentAnalysis::class, 'geo_citation_page_snapshot_id');
    }

    public function latestScore(): HasOne
    {
        return $this->hasOne(GeoReferenceContentScore::class, 'geo_citation_page_snapshot_id')->latestOfMany();
    }
}
