<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributionLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'distribution_channel_id',
        'article_distribution_id',
        'article_id',
        'level',
        'event',
        'message',
        'context',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'distribution_channel_id' => 'integer',
            'article_distribution_id' => 'integer',
            'article_id' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }

    public function articleDistribution(): BelongsTo
    {
        return $this->belongsTo(ArticleDistribution::class, 'article_distribution_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }
}
