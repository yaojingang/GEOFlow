<?php

namespace App\Ai\Workspace;

use App\Models\AiWorkspaceRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AiWorkspaceRunEvent
{
    public const PROTOCOL_VERSION = 1;

    /** @var list<string> */
    public const TERMINAL_TYPES = [
        'run.completed',
        'run.partially_completed',
        'run.failed',
        'run.cancelled',
        'run.outcome_unknown',
        'run.rejected',
    ];

    /** @var list<string> */
    private const TYPES = [
        'run.received', 'run.queued', 'run.started', 'run.cancel_requested',
        'run.completed', 'run.partially_completed', 'run.failed', 'run.cancelled', 'run.outcome_unknown', 'run.rejected',
        'resolution.started', 'resolution.completed', 'resolution.failed',
        'model.requested', 'model.first_token', 'model.completed', 'model.failed', 'model.cancelled',
        'plan.drafted', 'plan.validated', 'plan.revised', 'plan.rejected',
        'approval.requested', 'approval.approved', 'approval.rejected', 'approval.expired',
        'step.queued', 'step.started', 'step.completed', 'step.failed', 'step.skipped',
        'capability.call_started', 'capability.call_completed', 'capability.call_failed',
        'artifact.created',
        'external.prepared', 'external.dispatched', 'external.confirmed', 'external.outcome_unknown',
        'authorization.revoked', 'runtime.disabled', 'lease.lost',
        'context.snapshot', 'run.followup_queued', 'run.steered', 'run.child_created', 'run.children_joined', 'legacy.trace',
    ];

    /**
     * @param  array<string,string|int|float|bool|array<array-key,mixed>|null>  $payload
     */
    public function __construct(
        public string $type,
        public string $correlationId,
        public ?string $causationId,
        public string $actorType,
        public ?string $actorId,
        public array $payload,
        public string $visibility,
        public CarbonImmutable $occurredAt,
    ) {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported AI workspace run event type: '.$type);
        }
        if (! Str::isUuid($correlationId)) {
            throw new InvalidArgumentException('AI workspace run event correlation ID must be a UUID.');
        }
        if ($causationId !== null && ! Str::isUuid($causationId)) {
            throw new InvalidArgumentException('AI workspace run event causation ID must be a UUID.');
        }
        if (! in_array($visibility, ['user', 'log_only'], true)) {
            throw new InvalidArgumentException('Unsupported AI workspace run event visibility: '.$visibility);
        }
    }

    /** @param array<string,mixed> $presentation */
    public static function fromRun(AiWorkspaceRun $run, array $presentation, ?string $causationId = null): self
    {
        $type = (string) ($presentation['event_type'] ?? self::typeForState((string) $run->state));
        $correlationId = (string) ($presentation['correlation_id'] ?? $run->id);
        $actorType = (string) ($presentation['actor_type'] ?? 'system');
        $actorId = Arr::get($presentation, 'actor_id');
        $visibility = (string) ($presentation['visibility'] ?? Arr::get($presentation, 'detail.visibility', 'user'));

        return new self(
            type: $type,
            correlationId: $correlationId,
            causationId: isset($presentation['causation_id']) ? (string) $presentation['causation_id'] : $causationId,
            actorType: $actorType !== '' ? $actorType : 'system',
            actorId: is_scalar($actorId) && (string) $actorId !== '' ? (string) $actorId : null,
            payload: (array) ($presentation['payload'] ?? []),
            visibility: $visibility,
            occurredAt: CarbonImmutable::now(),
        );
    }

    public function isTerminal(): bool
    {
        return in_array($this->type, self::TERMINAL_TYPES, true);
    }

    private static function typeForState(string $state): string
    {
        return match ($state) {
            'received' => 'run.received',
            'clarifying' => 'resolution.completed',
            'answering' => 'model.requested',
            'planning' => 'plan.drafted',
            'validating_plan' => 'plan.validated',
            'awaiting_approval', 'awaiting_step_approval' => 'approval.requested',
            'queued' => 'run.queued',
            'running' => 'run.started',
            'cancel_requested' => 'run.cancel_requested',
            'completed' => 'run.completed',
            'partially_completed' => 'run.partially_completed',
            'failed' => 'run.failed',
            'cancelled' => 'run.cancelled',
            'outcome_unknown' => 'run.outcome_unknown',
            'rejected' => 'run.rejected',
            default => 'legacy.trace',
        };
    }
}
