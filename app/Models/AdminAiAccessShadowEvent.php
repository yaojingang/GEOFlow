<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AdminAiAccessShadowEvent extends Model
{
    public const UPDATED_AT = null;

    public const COMPARISON_MATCHED = 'matched';

    public const COMPARISON_DIFFERENT = 'different';

    public const COMPARISON_SAFE_MISSING = 'safe_missing';

    public const COMPARISON_LEGACY_MISSING = 'legacy_missing';

    public const COMPARISON_BOTH_MISSING = 'both_missing';

    public const MODEL_SOURCE_PERSONAL = 'personal';

    public const MODEL_SOURCE_SHARED = 'shared';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Administrator AI access shadow events are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Administrator AI access shadow events are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'execution_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'legacy_preferred_model_id' => 'integer',
            'safe_preferred_model_id' => 'integer',
            'inaccessible_legacy_model_count' => 'integer',
            'missing_owner_model_count' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
