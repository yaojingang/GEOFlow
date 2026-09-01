<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiQualityAuditEvent extends Model
{
    protected $fillable = [
        'event_uuid',
        'correlation_id',
        'event_type',
        'occurred_at',
        'task_id',
        'article_id',
        'article_ai_quality_check_id',
        'admin_id',
        'api_token_id',
        'authorization_result',
        'policy_version',
        'before_hash',
        'after_hash',
        'basis_hash',
        'reason_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'task_id' => 'integer',
            'article_id' => 'integer',
            'article_ai_quality_check_id' => 'integer',
            'admin_id' => 'integer',
            'api_token_id' => 'integer',
            'policy_version' => 'integer',
            'metadata' => 'array',
        ];
    }
}
