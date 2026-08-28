<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkspaceArtifact extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'run_id', 'step_id', 'created_by_admin_id', 'created_by_username_snapshot', 'type', 'name',
        'data_classification', 'content', 'payload', 'source_route', 'source_url', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'expires_at' => 'datetime',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
