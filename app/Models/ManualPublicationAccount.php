<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualPublicationAccount extends Model
{
    public const PLATFORM_ZHIHU = 'zhihu';

    public const PLATFORM_XIAOHONGSHU = 'xiaohongshu';

    public const PLATFORM_WEIBO = 'weibo';

    public const PLATFORM_WECHAT = 'wechat';

    public const PLATFORM_DOUYIN = 'douyin';

    public const PLATFORM_BILIBILI = 'bilibili';

    public const PLATFORM_REDDIT = 'reddit';

    public const PLATFORM_X = 'x';

    public const PLATFORM_LINKEDIN = 'linkedin';

    public const PLATFORM_CUSTOM = 'custom';

    public const PLATFORMS = [
        self::PLATFORM_ZHIHU,
        self::PLATFORM_XIAOHONGSHU,
        self::PLATFORM_WEIBO,
        self::PLATFORM_WECHAT,
        self::PLATFORM_DOUYIN,
        self::PLATFORM_BILIBILI,
        self::PLATFORM_REDDIT,
        self::PLATFORM_X,
        self::PLATFORM_LINKEDIN,
        self::PLATFORM_CUSTOM,
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'persona_id',
        'platform',
        'custom_platform',
        'account_name',
        'profile_url',
        'notes',
        'is_active',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'persona_id' => 'integer',
            'is_active' => 'boolean',
            'created_by_admin_id' => 'integer',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(ManualPublicationPersona::class, 'persona_id');
    }

    public function publications(): HasMany
    {
        return $this->hasMany(ManualPublication::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function platformLabelKey(): string
    {
        return 'admin.manual_publications.platform.'.$this->platform;
    }
}
