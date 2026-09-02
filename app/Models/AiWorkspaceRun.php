<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkspaceRun extends Model
{
    use HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.u';

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
        'resolution_source', 'resolution_started_at', 'resolution_finished_at', 'queued_at', 'first_token_at',
        'answer_chunk_sequence', 'answer_is_partial', 'cancel_requested_at', 'started_at', 'finished_at', 'payload_pruned_at',
        'model_snapshot', 'usage', 'context_snapshot_digest', 'last_event_at',
    ];

    protected $hidden = [
        'resolution_lease_owner',
        'execution_lease_token',
    ];

    protected function casts(): array
    {
        return [
            'resolution_score' => 'float',
            'admin_auth_version' => 'integer',
            'model_access_admin_id' => 'integer',
            'ai_config_access_version' => 'integer',
            'requested_ai_model_id' => 'integer',
            'resolved_ai_model_id' => 'integer',
            'resolver_policy_version' => 'integer',
            'retryable_failure' => 'boolean',
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
            'answer_chunk_sequence' => 'integer',
            'answer_is_partial' => 'boolean',
            'model_snapshot' => 'array',
            'usage' => 'array',
            'system_operations_executed' => 'boolean',
            'resolution_lease_expires_at' => 'datetime',
            'model_resolved_at' => 'datetime',
            'execution_lease_expires_at' => 'datetime',
            'resolution_started_at' => 'datetime',
            'resolution_finished_at' => 'datetime',
            'queued_at' => 'datetime',
            'first_token_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload_pruned_at' => 'datetime',
            'last_event_at' => 'immutable_datetime',
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

    public function modelAccessAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'model_access_admin_id');
    }

    public function requestedAiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'requested_ai_model_id');
    }

    public function resolvedAiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'resolved_ai_model_id');
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function childRuns(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id')->orderBy('created_at');
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

    public function traceEvents(): HasMany
    {
        return $this->hasMany(AiWorkspaceTraceEvent::class, 'run_id')->orderBy('sequence');
    }

    public function isTerminal(): bool
    {
        return in_array((string) $this->state, self::TERMINAL_STATES, true);
    }
}
