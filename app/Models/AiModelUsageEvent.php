<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AiModelUsageEvent extends Model
{
    public const UPDATED_AT = null;

    public const EXECUTION_SCOPE_INTERACTIVE_ADMIN = 'interactive_admin';

    public const EXECUTION_SCOPE_PERSISTED_ADMIN = 'persisted_admin';

    public const EXECUTION_SCOPE_SYSTEM = 'system';

    public const MODEL_SOURCE_PERSONAL = 'personal';

    public const MODEL_SOURCE_SHARED = 'shared';

    public const MODEL_SOURCE_SYSTEM = 'system';

    public const STATUS_STARTED = 'started';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISCARDED = 'discarded';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('AI model usage events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('AI model usage events are immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'ai_model_id' => 'integer',
            'config_owner_admin_id' => 'integer',
            'execution_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'source_id' => 'string',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'estimated_cost' => 'decimal:8',
        ];
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function configOwnerAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'config_owner_admin_id');
    }

    public function executionAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'execution_admin_id');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
