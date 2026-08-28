<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkspaceTraceEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'run_id', 'step_id', 'sequence', 'kind', 'title', 'summary', 'status', 'detail',
        'event_type', 'event_version', 'correlation_id', 'causation_id', 'actor_type', 'actor_id', 'payload', 'visibility', 'occurred_at',
        'started_at', 'finished_at',
    ];

    protected $attributes = [
        'status' => 'running',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'detail' => 'array',
            'event_version' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
