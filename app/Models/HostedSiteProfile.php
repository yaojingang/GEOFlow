<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HostedSiteProfile extends Model
{
    public const SERVING_MAINTENANCE = 'maintenance';

    public const SERVING_ONLINE = 'online';

    public const SERVING_ARCHIVED = 'archived';

    public const INDEXING_NOINDEX = 'noindex';

    public const INDEXING_INDEX = 'index';

    public const QUALITY_PENDING = 'pending';

    public const QUALITY_PASSED = 'passed';

    public const QUALITY_BLOCKED = 'blocked';

    protected $fillable = [
        'distribution_channel_id',
        'hostname',
        'root_domain',
        'topic',
        'locale',
        'timezone',
        'daily_publish_limit',
        'publish_weight',
        'min_publish_interval_minutes',
        'min_articles_before_index',
        'serving_status',
        'indexing_status',
        'quality_status',
        'settings_version',
        'consecutive_publish_failures',
        'cooldown_until',
        'last_published_at',
        'activated_at',
        'indexed_at',
        'archived_at',
    ];

    protected $attributes = [
        'topic' => '',
        'locale' => 'zh_CN',
        'timezone' => 'Asia/Shanghai',
        'daily_publish_limit' => 3,
        'publish_weight' => 100,
        'min_publish_interval_minutes' => 360,
        'min_articles_before_index' => 10,
        'serving_status' => self::SERVING_MAINTENANCE,
        'indexing_status' => self::INDEXING_NOINDEX,
        'quality_status' => self::QUALITY_PENDING,
        'settings_version' => 1,
        'consecutive_publish_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'distribution_channel_id' => 'integer',
            'daily_publish_limit' => 'integer',
            'publish_weight' => 'integer',
            'min_publish_interval_minutes' => 'integer',
            'min_articles_before_index' => 'integer',
            'settings_version' => 'integer',
            'consecutive_publish_failures' => 'integer',
            'cooldown_until' => 'datetime',
            'last_published_at' => 'datetime',
            'activated_at' => 'datetime',
            'indexed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(DistributionChannel::class, 'distribution_channel_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HostedSiteArticleAssignment::class);
    }
}
