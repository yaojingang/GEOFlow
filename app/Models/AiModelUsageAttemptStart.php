<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class AiModelUsageAttemptStart extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('AI model usage attempt starts are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('AI model usage attempt starts are immutable.');
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
        ];
    }
}
