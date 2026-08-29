<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class KnowledgeMediaAsset extends Model
{
    protected $fillable = [
        'knowledge_base_id',
        'asset_key',
        'asset_version',
        'supersedes_id',
        'section_key',
        'route_name',
        'title',
        'alt_text',
        'caption',
        'keywords_json',
        'storage_path',
        'thumbnail_path',
        'mime_type',
        'width',
        'height',
        'content_hash',
        'locale',
        'official_version',
        'captured_at',
        'captured_app_version',
        'sort_order',
        'is_active',
        'needs_review',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'integer',
            'asset_version' => 'integer',
            'supersedes_id' => 'integer',
            'keywords_json' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'captured_at' => 'datetime',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'needs_review' => 'boolean',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
