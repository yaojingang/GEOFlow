<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiWorkspaceExternalOperation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'run_id', 'step_id', 'execution_key', 'capability_key', 'target_type', 'target_id',
        'status', 'request_digest', 'target_digest', 'request_payload', 'remote_result', 'error_message',
        'dispatched_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'remote_result' => 'array',
            'dispatched_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiWorkspaceRun::class, 'run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(AiWorkspaceStep::class, 'step_id');
    }
}
