<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleRiskScan extends Model
{
    protected $fillable = [
        'article_id',
        'status',
        'match_count',
        'matches',
        'content_hash',
        'dictionary_hash',
        'trigger',
        'admin_id',
        'scanned_at',
    ];

    protected $attributes = [
        'match_count' => 0,
        'is_overridden' => false,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'match_count' => 'integer',
            'matches' => 'array',
            'admin_id' => 'integer',
            'scanned_at' => 'datetime',
            'is_overridden' => 'boolean',
            'overridden_by_admin_id' => 'integer',
            'overridden_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'overridden_by_admin_id');
    }
}
