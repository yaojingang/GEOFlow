<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArticleDistribution extends Model
{
    protected $fillable = [
        'article_id',
        'distribution_channel_id',
        'action',
        'status',
        'remote_id',
        'remote_url',
        'idempotency_key',
        'attempt_count',
        'next_retry_at',
        'last_attempt_at',
        'last_error_message',
        'payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'distribution_channel_id' => 'integer',
            'attempt_count' => 'integer',
            'next_retry_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DistributionLog::class, 'article_distribution_id');
    }
}
