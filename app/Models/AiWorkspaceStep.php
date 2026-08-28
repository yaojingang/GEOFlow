<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiWorkspaceStep extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'run_id', 'position', 'capability_key', 'capability_name', 'capability_version', 'state',
        'risk_level', 'execution_scope', 'approval_policy', 'result_contract', 'parameters', 'depends_on', 'input_bindings', 'bindings_resolved_at', 'target_summary', 'result_summary',
        'idempotency_key', 'requires_approval', 'external_operation', 'attempts', 'max_attempts',
        'lease_owner', 'lease_expires_at', 'error_message', 'started_at', 'finished_at',
        'queued_at', 'first_output_at', 'result_schema_version',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'parameters' => 'array',
            'depends_on' => 'array',
            'input_bindings' => 'array',
            'bindings_resolved_at' => 'datetime',
            'result_contract' => 'array',
            'target_summary' => 'array',
            'result_summary' => 'array',
            'requires_approval' => 'boolean',
            'external_operation' => 'boolean',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'queued_at' => 'immutable_datetime',
            'first_output_at' => 'immutable_datetime',
            'result_schema_version' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiWorkspaceRun::class, 'run_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(AiWorkspaceApproval::class, 'step_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(AiWorkspaceArtifact::class, 'step_id');
    }

    public function externalOperations(): HasMany
    {
        return $this->hasMany(AiWorkspaceExternalOperation::class, 'step_id');
    }
}
