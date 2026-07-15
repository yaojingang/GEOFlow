<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SensitiveWord extends Model
{
    public const RULE_CACHE_VERSION_KEY = 'geoflow.article-risk-scanner.rules.version';

    public const UPDATED_AT = null;

    protected $table = 'sensitive_words';

    protected $fillable = [
        'word',
        'severity',
        'category',
        'is_enabled',
        'suggestion',
        'applies_to',
    ];

    protected $attributes = [
        'severity' => 'warning',
        'category' => 'sensitive',
        'is_enabled' => true,
    ];

    protected static function booted(): void
    {
        static::saved(static function (): void {
            DB::afterCommit(static fn (): int => self::bumpRuleCacheVersion());
        });
        static::deleted(static function (): void {
            DB::afterCommit(static fn (): int => self::bumpRuleCacheVersion());
        });
    }

    public static function ruleCacheVersion(): int
    {
        Cache::add(self::RULE_CACHE_VERSION_KEY, 1);

        return max(1, (int) Cache::get(self::RULE_CACHE_VERSION_KEY, 1));
    }

    public static function bumpRuleCacheVersion(): int
    {
        Cache::add(self::RULE_CACHE_VERSION_KEY, 1);

        return max(1, (int) Cache::increment(self::RULE_CACHE_VERSION_KEY));
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'applies_to' => 'array',
        ];
    }
}
