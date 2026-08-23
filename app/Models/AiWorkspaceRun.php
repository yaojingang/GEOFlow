<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkspaceRun extends Model
{
    use HasUuids;

    public const TERMINAL_STATES = [
        'completed', 'partially_completed', 'failed', 'cancelled', 'outcome_unknown', 'rejected',
    ];

    protected $fillable = [
        'id', 'conversation_id', 'admin_id', 'admin_username_snapshot', 'admin_auth_version', 'parent_run_id', 'request_key', 'mode', 'state',
        'prompt', 'intent', 'prompt_versions', 'resolution_score', 'candidate_capabilities', 'known_parameters',
        'missing_parameters', 'plan', 'plan_version', 'plan_digest', 'capability_versions',
        'parameter_digest', 'target_digest', 'risk_level', 'answer', 'status_message',
        'system_operations_executed', 'event_sequence', 'state_version', 'failure_code',
        'failure_message', 'resolution_lease_owner', 'resolution_lease_expires_at', 'resolution_attempts',
        'cancel_requested_at', 'started_at', 'finished_at', 'payload_pruned_at',
    ];

    protected function casts(): array
    {
        return [
            'resolution_score' => 'float',
            'admin_auth_version' => 'integer',
            'prompt_versions' => 'array',
            'candidate_capabilities' => 'array',
            'known_parameters' => 'array',
            'missing_parameters' => 'array',
            'plan' => 'array',
            'capability_versions' => 'array',
            'plan_version' => 'integer',
            'event_sequence' => 'integer',
            'state_version' => 'integer',
            'resolution_attempts' => 'integer',
            'system_operations_executed' => 'boolean',
            'resolution_lease_expires_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload_pruned_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AiWorkspaceStep::class, 'run_id')->orderBy('position');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AiWorkspaceApproval::class, 'run_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AiWorkspaceArtifact::class, 'run_id');
    }

    public function externalOperations(): HasMany
    {
        return $this->hasMany(AiWorkspaceExternalOperation::class, 'run_id');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->state, self::TERMINAL_STATES, true);
    }
}
