<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiWorkspaceApproval extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'run_id', 'step_id', 'capability_key', 'admin_id', 'admin_username_snapshot', 'status', 'plan_version', 'capability_versions',
        'parameter_digest', 'target_digest', 'decision_reason', 'expires_at', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'plan_version' => 'integer',
            'capability_versions' => 'array',
            'expires_at' => 'datetime',
            'decided_at' => 'datetime',
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

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function isValidFor(AiWorkspaceRun $run): bool
    {
        return $this->status === 'approved'
            && $this->expires_at?->isFuture()
            && (int) $this->plan_version === (int) $run->plan_version
            && hash_equals((string) $this->parameter_digest, (string) $run->parameter_digest)
            && hash_equals((string) $this->target_digest, (string) $run->target_digest)
            && $this->capability_versions === $run->capability_versions;
    }
}
