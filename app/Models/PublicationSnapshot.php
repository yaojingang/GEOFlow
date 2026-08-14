<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationSnapshot extends Model
{
    protected $fillable = [
        'public_page_id',
        'content_hash',
        'version',
        'payload',
        'is_active',
        'published_by',
        'published_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload' => 'array',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PublicPage::class, 'public_page_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'published_by');
    }
}
