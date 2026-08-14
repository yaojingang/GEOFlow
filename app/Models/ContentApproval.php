<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentApproval extends Model
{
    public const GATES = ['facts', 'medical', 'compliance', 'brand'];

    protected $fillable = [
        'public_page_id',
        'content_hash',
        'gate',
        'decision',
        'reviewer_id',
        'reviewer_name',
        'note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PublicPage::class, 'public_page_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewer_id');
    }
}
