<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PublicFact extends Model
{
    protected $fillable = [
        'fact_code',
        'entity_type',
        'statement',
        'evidence_level',
        'evidence_label',
        'evidence_url',
        'visibility',
        'status',
        'owner_name',
        'effective_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'evidence_level' => 'integer',
            'effective_at' => 'date',
            'expires_at' => 'date',
            'metadata' => 'array',
        ];
    }

    public function pages(): BelongsToMany
    {
        return $this->belongsToMany(PublicPage::class, 'public_fact_page');
    }

    public function isPublishable(): bool
    {
        return $this->evidence_level >= 3
            && $this->visibility === 'public'
            && in_array($this->status, ['approved', 'published'], true)
            && ($this->expires_at === null || $this->expires_at->isToday() || $this->expires_at->isFuture());
    }
}
