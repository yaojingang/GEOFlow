<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostedSiteAllocationRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ALLOCATING = 'allocating';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'article_id',
        'task_id',
        'hosted_site_profile_id',
        'hosted_site_article_assignment_id',
        'status',
        'attempt_count',
        'next_attempt_at',
        'last_attempt_at',
        'last_error_code',
        'last_error_message',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'attempt_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'task_id' => 'integer',
            'hosted_site_profile_id' => 'integer',
            'hosted_site_article_assignment_id' => 'integer',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(
            HostedSiteArticleAssignment::class,
            'hosted_site_article_assignment_id'
        );
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HostedSiteProfile::class, 'hosted_site_profile_id');
    }
}
