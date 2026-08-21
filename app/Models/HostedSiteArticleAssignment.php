<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HostedSiteArticleAssignment extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'article_id',
        'hosted_site_profile_id',
        'status',
        'content_fingerprint',
        'capacity_date',
        'reservation_expires_at',
        'assigned_at',
        'published_at',
        'withdrawn_at',
        'last_error_message',
    ];

    protected $attributes = [
        'status' => self::STATUS_RESERVED,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'hosted_site_profile_id' => 'integer',
            'capacity_date' => 'date:Y-m-d',
            'reservation_expires_at' => 'datetime',
            'assigned_at' => 'datetime',
            'published_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HostedSiteProfile::class, 'hosted_site_profile_id');
    }

    public function allocationRequest(): HasOne
    {
        return $this->hasOne(HostedSiteAllocationRequest::class);
    }
}
