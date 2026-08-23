<?php

namespace App\Services\AiWorkspace;

use App\Models\AiWorkspaceRun;

final class AiWorkspaceSnapshot
{
    /** @return array<string,mixed> */
    public function make(AiWorkspaceRun $run): array
    {
        $run->loadMissing(['steps', 'approvals', 'artifacts']);

        return [
            'id' => (string) $run->id,
            'conversation_id' => (string) $run->conversation_id,
            'mode' => (string) $run->mode,
            'state' => (string) $run->state,
            'status_message' => $run->status_message,
            'intent' => $run->intent,
            'prompt_versions' => $run->prompt_versions ?? [],
            'resolution_score' => $run->resolution_score,
            'candidate_capabilities' => $run->candidate_capabilities ?? [],
            'known_parameters' => $run->known_parameters ?? [],
            'missing_parameters' => $run->missing_parameters ?? [],
            'plan' => $run->plan,
            'plan_version' => (int) $run->plan_version,
            'risk_level' => (string) $run->risk_level,
            'answer' => $run->answer,
            'system_operations_executed' => (bool) $run->system_operations_executed,
            'payload_pruned' => $run->payload_pruned_at !== null,
            'failure' => $run->failure_code === null ? null : [
                'code' => (string) $run->failure_code,
                'message' => (string) $run->failure_message,
            ],
            'version' => (int) $run->state_version,
            'sequence' => (int) $run->event_sequence,
            'cancel_requested_at' => $run->cancel_requested_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
            'steps' => $run->steps->map(fn ($step): array => [
                'id' => (string) $step->id,
                'position' => (int) $step->position,
                'capability' => (string) $step->capability_key,
                'capability_name' => (string) ($step->capability_name ?: $step->capability_key),
                'capability_version' => (string) $step->capability_version,
                'state' => (string) $step->state,
                'risk_level' => (string) $step->risk_level,
                'execution_scope' => (string) $step->execution_scope,
                'approval_policy' => (string) $step->approval_policy,
                'parameters' => $step->parameters ?? [],
                'depends_on' => $step->depends_on ?? [],
                'input_bindings' => $step->input_bindings ?? [],
                'bindings_resolved_at' => $step->bindings_resolved_at?->toISOString(),
                'target_summary' => $step->target_summary ?? [],
                'result_summary' => $step->result_summary,
                'requires_approval' => (bool) $step->requires_approval,
                'attempts' => (int) $step->attempts,
                'error_message' => $step->error_message,
            ])->values()->all(),
            'approvals' => $run->approvals->map(static fn ($approval): array => [
                'id' => (string) $approval->id,
                'step_id' => $approval->step_id,
                'capability' => (string) $approval->capability_key,
                'status' => (string) $approval->status,
                'plan_version' => (int) $approval->plan_version,
                'expires_at' => $approval->expires_at?->toISOString(),
                'decided_at' => $approval->decided_at?->toISOString(),
            ])->values()->all(),
            'artifacts' => $run->artifacts->map(static fn ($artifact): array => [
                'id' => (string) $artifact->id,
                'step_id' => $artifact->step_id,
                'type' => (string) $artifact->type,
                'name' => (string) $artifact->name,
                'content' => $artifact->content,
                'payload' => $artifact->payload,
                'source_route' => $artifact->source_route,
                'source_url' => $artifact->source_url,
                'created_at' => $artifact->created_at?->toISOString(),
            ])->values()->all(),
        ];
    }
}
