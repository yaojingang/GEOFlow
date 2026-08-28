<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiWorkspaceRunEvent;
use App\Ai\Workspace\AiWorkspaceUrlSanitizer;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceTraceEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

final readonly class AiWorkspaceSnapshot
{
    public function __construct(
        private AiWorkspaceCapabilityPresenter $presenter,
        private AiWorkspaceAnswerPresenter $answerPresenter,
    ) {}

    /** @return array<string,mixed> */
    public function make(AiWorkspaceRun $run, ?string $beforeTrace = null): array
    {
        $run->loadMissing(['steps', 'approvals', 'artifacts', 'childRuns']);
        $traceQuery = $run->traceEvents()->reorder('sequence', 'desc');
        if (is_string($beforeTrace) && Str::isUuid($beforeTrace)) {
            $cursor = AiWorkspaceTraceEvent::query()->where('run_id', $run->id)->whereKey($beforeTrace)->value('sequence');
            if (is_numeric($cursor)) {
                $traceQuery->where('sequence', '<', (int) $cursor);
            }
        }
        $traceEvents = $traceQuery->limit(80)->get()->reverse()->values();
        $projectedTrace = $traceEvents->map(fn (AiWorkspaceTraceEvent $event): array => $this->trace($event))->values()->all();
        $oldestTrace = $traceEvents->first();
        $hasOlderTrace = $oldestTrace instanceof AiWorkspaceTraceEvent
            && $run->traceEvents()->where('sequence', '<', $oldestTrace->sequence)->exists();

        $snapshot = [
            'schema_version' => 2,
            'event_protocol_version' => AiWorkspaceRunEvent::PROTOCOL_VERSION,
            'id' => (string) $run->id,
            'conversation_id' => (string) $run->conversation_id,
            'parent_run_id' => $run->parent_run_id,
            'mode' => (string) $run->mode,
            'state' => (string) $run->state,
            'status_message' => $this->plainText($run->status_message, 500),
            'intent' => $this->plainText($run->intent, 180),
            'prompt_versions' => $run->prompt_versions ?? [],
            'resolution_score' => $run->resolution_score,
            'candidate_capabilities' => $this->safeCapabilityCandidates($run->candidate_capabilities),
            'missing_parameters' => collect($run->missing_parameters ?? [])->map(static fn (mixed $value): string => Str::limit(strip_tags((string) $value), 120, ''))->values()->all(),
            'plan_version' => (int) $run->plan_version,
            'risk_level' => (string) $run->risk_level,
            'answer' => $run->answer,
            'system_operations_executed' => (bool) $run->system_operations_executed,
            'payload_pruned' => $run->payload_pruned_at !== null,
            'failure' => $run->failure_code === null ? null : [
                'code' => (string) $run->failure_code,
                'message' => $this->plainText($run->failure_message, 500),
            ],
            'version' => (int) $run->state_version,
            'sequence' => (int) $run->event_sequence,
            'routing' => [
                'resolution_source' => $run->resolution_source,
                'queue' => $run->queued_at === null ? 'inline' : 'interactive',
                'surface' => 'native',
            ],
            'timings' => $this->timings($run),
            'timing_summary' => $this->timingSummary($run),
            'usage_summary' => $this->safeUsage($run->usage),
            'model_snapshot' => $this->safeModelSnapshot($run->model_snapshot),
            'context_snapshot_digest' => $run->context_snapshot_digest,
            'last_event_at' => $run->last_event_at?->toISOString(),
            'answer_chunk_sequence' => (int) $run->answer_chunk_sequence,
            'answer_is_partial' => (bool) $run->answer_is_partial,
            'cancel_requested_at' => $run->cancel_requested_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
            'created_at' => $run->created_at?->toISOString(),
            'updated_at' => $run->updated_at?->toISOString(),
            'trace_events' => $projectedTrace,
            'projected_trace' => $projectedTrace,
            'older_trace_cursor' => $hasOlderTrace ? (string) $oldestTrace->id : null,
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
                'depends_on' => collect($step->depends_on ?? [])->map(static fn (mixed $value): string => (string) $value)->values()->all(),
                'bindings_resolved_at' => $step->bindings_resolved_at?->toISOString(),
                'input_presentation' => $this->presenter->inputPresentation($step),
                'editable_input' => $this->presenter->editableInput($step),
                'result_presentation' => $this->withDedupeKey($this->presenter->present((array) $step->result_summary ?: null)),
                'result_schema_version' => (int) ($step->result_schema_version ?: data_get($step->result_summary, 'schema_version', 1)),
                'requires_approval' => (bool) $step->requires_approval,
                'attempts' => (int) $step->attempts,
                'error_message' => $this->plainText($step->error_message, 500),
                'timings' => [
                    'queued_at' => $step->queued_at?->toISOString(),
                    'started_at' => $step->started_at?->toISOString(),
                    'first_output_at' => $step->first_output_at?->toISOString(),
                    'finished_at' => $step->finished_at?->toISOString(),
                ],
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
            'artifacts' => $run->artifacts->map(fn ($artifact): array => [
                'id' => (string) $artifact->id,
                'step_id' => $artifact->step_id,
                'type' => (string) $artifact->type,
                'name' => $this->plainText($artifact->name, 180),
                'summary' => $this->plainText($artifact->content, 800),
                'source_route' => $this->plainText($artifact->source_route, 180),
                'source_url' => AiWorkspaceUrlSanitizer::clean($artifact->source_url),
                'dedupe_key' => $this->dedupeKey($artifact->content ?: $artifact->name),
                'created_at' => $artifact->created_at?->toISOString(),
            ])->values()->all(),
            'child_runs' => $run->childRuns->map(static fn (AiWorkspaceRun $child): array => [
                'id' => (string) $child->id,
                'state' => (string) $child->state,
                'mode' => (string) $child->mode,
                'status_message' => Str::limit(strip_tags((string) $child->status_message), 240, ''),
                'created_at' => $child->created_at?->toISOString(),
                'finished_at' => $child->finished_at?->toISOString(),
            ])->values()->all(),
        ];
        $snapshot['answer_presentation'] = $this->answerPresenter->present($run, $snapshot['steps']);

        return $snapshot;
    }

    /** @param array<string,mixed>|null $presentation @return array<string,mixed>|null */
    private function withDedupeKey(?array $presentation): ?array
    {
        if ($presentation === null) {
            return null;
        }

        $presentation['dedupe_key'] = $this->dedupeKey($presentation['summary'] ?? json_encode($presentation));

        return $presentation;
    }

    private function dedupeKey(mixed $value): string
    {
        return hash('sha256', Str::of((string) $value)->squish()->lower()->toString());
    }

    /** @return array<string,mixed> */
    private function trace(AiWorkspaceTraceEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'step_id' => $event->step_id,
            'sequence' => (int) $event->sequence,
            'event_type' => (string) ($event->event_type ?: 'legacy.trace'),
            'event_version' => (int) ($event->event_version ?: 1),
            'correlation_id' => $event->correlation_id ?: $event->run_id,
            'causation_id' => $event->causation_id,
            'source' => ['type' => (string) ($event->actor_type ?: 'system'), 'id' => $event->actor_id],
            'kind' => (string) $event->kind,
            'title' => (string) $event->title,
            'summary' => $this->plainText($event->summary, 500),
            'status' => (string) $event->status,
            'payload' => $this->safeTracePayload($event->payload),
            'detail' => $this->safeTraceDetail($event->detail),
            'visibility' => (string) ($event->visibility ?: data_get($event->detail, 'visibility', 'user')),
            'occurred_at' => $event->occurred_at?->toISOString() ?? $event->created_at?->toISOString(),
            'started_at' => $event->started_at?->toISOString(),
            'finished_at' => $event->finished_at?->toISOString(),
        ];
    }

    /** @return array<string,?string> */
    private function timings(AiWorkspaceRun $run): array
    {
        return [
            'created_at' => $run->created_at?->toISOString(), 'queued_at' => $run->queued_at?->toISOString(),
            'resolution_started_at' => $run->resolution_started_at?->toISOString(), 'resolution_finished_at' => $run->resolution_finished_at?->toISOString(),
            'started_at' => $run->started_at?->toISOString(), 'first_token_at' => $run->first_token_at?->toISOString(),
            'finished_at' => $run->finished_at?->toISOString(),
        ];
    }

    /** @return array<string,int> */
    private function timingSummary(AiWorkspaceRun $run): array
    {
        $queueWait = $this->durationMs($run->queued_at ?? $run->created_at, $run->resolution_started_at);

        return array_filter([
            'queue_ms' => $queueWait,
            'queue_wait_ms' => $queueWait,
            'first_event_ms' => $this->durationMs(
                $run->created_at,
                $run->first_token_at ?? $run->resolution_started_at ?? $run->last_event_at,
            ),
            'resolution_ms' => $this->durationMs($run->resolution_started_at, $run->resolution_finished_at),
            'ttft_ms' => $this->durationMs($run->resolution_started_at ?? $run->started_at, $run->first_token_at),
            'total_ms' => $this->durationMs($run->created_at, $run->finished_at ?? $run->last_event_at),
        ], static fn (?int $value): bool => $value !== null);
    }

    private function durationMs(?CarbonInterface $start, ?CarbonInterface $finish): ?int
    {
        if (! $start instanceof CarbonInterface || ! $finish instanceof CarbonInterface || $finish->lessThan($start)) {
            return null;
        }

        return (int) round($start->diffInMilliseconds($finish));
    }

    /** @return array<string,int|float> */
    private function safeUsage(mixed $usage): array
    {
        return collect(is_array($usage) ? $usage : [])->only(['prompt_tokens', 'completion_tokens', 'total_tokens', 'cache_read_tokens', 'cache_write_tokens', 'model_calls'])
            ->filter(static fn (mixed $value): bool => is_int($value) || is_float($value))->all();
    }

    /** @return list<array<string,string|int|float>|string> */
    private function safeCapabilityCandidates(mixed $candidates): array
    {
        return collect(is_array($candidates) ? $candidates : [])->map(function (mixed $candidate): array|string|null {
            if (is_string($candidate)) {
                return Str::limit(strip_tags($candidate), 120, '');
            }
            if (! is_array($candidate)) {
                return null;
            }

            return collect($candidate)->only(['key', 'name', 'score'])
                ->filter(static fn (mixed $value): bool => is_string($value) || is_int($value) || is_float($value))
                ->map(static fn (mixed $value): string|int|float => is_string($value) ? Str::limit(strip_tags($value), 120, '') : $value)
                ->all();
        })->filter(static fn (mixed $value): bool => $value !== null && $value !== '')->values()->all();
    }

    /** @return array<string,string|int|float|bool|null> */
    private function safeModelSnapshot(mixed $snapshot): array
    {
        return collect(is_array($snapshot) ? $snapshot : [])->only(['provider', 'model', 'model_id', 'model_type', 'readiness_status', 'prompt_version'])
            ->filter(static fn (mixed $value): bool => is_scalar($value) || $value === null)->all();
    }

    private function plainText(mixed $value, int $limit): string
    {
        return Str::limit(trim(strip_tags((string) $value)), $limit, '');
    }

    /** @return array<string,string|int|float|bool|null|array<array-key,string|int|float|bool|null>> */
    private function safeTracePayload(mixed $payload): array
    {
        $allowed = [
            'resolution_source', 'model_id', 'provider', 'model', 'prompt_version', 'context_digest',
            'capability', 'capability_version', 'position', 'risk_level', 'execution_scope', 'approval_policy',
            'attempts', 'artifact_type', 'artifact_id', 'external_operation_id', 'failure_code', 'delivery_mode',
            'parent_run_id', 'child_run_id', 'budget', 'usage', 'timings',
        ];

        return collect(is_array($payload) ? $payload : [])->only($allowed)
            ->map(function (mixed $value): mixed {
                if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                    return $value;
                }
                if (is_string($value)) {
                    return $this->plainText($value, 240);
                }
                if (is_array($value)) {
                    return collect($value)->take(20)->map(function (mixed $nested): mixed {
                        if (is_bool($nested) || is_int($nested) || is_float($nested) || $nested === null) {
                            return $nested;
                        }

                        return is_string($nested) ? $this->plainText($nested, 180) : null;
                    })->filter(static fn (mixed $nested): bool => $nested !== null)->all();
                }

                return null;
            })->filter(static fn (mixed $value): bool => $value !== null)->all();
    }

    /** @return array<string,string|int|float|bool> */
    private function safeTraceDetail(mixed $detail): array
    {
        return collect(is_array($detail) ? $detail : [])->only([
            'capability', 'capability_name', 'position', 'risk_level', 'execution_scope', 'approval_policy', 'attempts', 'artifact_type', 'visibility',
        ])->map(function (mixed $value): mixed {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                return $value;
            }

            return is_string($value) ? $this->plainText($value, 180) : null;
        })->filter(static fn (mixed $value): bool => $value !== null)->all();
    }
}
