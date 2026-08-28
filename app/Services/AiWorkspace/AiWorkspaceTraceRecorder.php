<?php

namespace App\Services\AiWorkspace;

use App\Ai\Workspace\AiWorkspaceErrorSanitizer;
use App\Ai\Workspace\AiWorkspaceRunEvent;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceTraceEvent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AiWorkspaceTraceRecorder
{
    /** @var array<string,array{kind:string,title:string,status:string}> */
    private const STATE_PRESENTATION = [
        'received' => ['kind' => 'context', 'title' => '接收请求', 'status' => 'running'],
        'clarifying' => ['kind' => 'clarify', 'title' => '补充信息', 'status' => 'attention'],
        'answering' => ['kind' => 'analysis', 'title' => '生成回答', 'status' => 'running'],
        'planning' => ['kind' => 'plan', 'title' => '生成计划', 'status' => 'running'],
        'validating_plan' => ['kind' => 'guard', 'title' => '校验计划', 'status' => 'running'],
        'awaiting_approval' => ['kind' => 'approval', 'title' => '等待审批', 'status' => 'attention'],
        'awaiting_step_approval' => ['kind' => 'approval', 'title' => '等待续批', 'status' => 'attention'],
        'queued' => ['kind' => 'queue', 'title' => '进入队列', 'status' => 'running'],
        'running' => ['kind' => 'tool', 'title' => '执行工作流', 'status' => 'running'],
        'cancel_requested' => ['kind' => 'stop', 'title' => '停止运行', 'status' => 'attention'],
        'completed' => ['kind' => 'result', 'title' => '完成', 'status' => 'completed'],
        'partially_completed' => ['kind' => 'result', 'title' => '部分完成', 'status' => 'attention'],
        'failed' => ['kind' => 'error', 'title' => '运行失败', 'status' => 'failed'],
        'cancelled' => ['kind' => 'stop', 'title' => '已取消', 'status' => 'stopped'],
        'outcome_unknown' => ['kind' => 'guard', 'title' => '结果待确认', 'status' => 'attention'],
        'rejected' => ['kind' => 'guard', 'title' => '请求已拦截', 'status' => 'failed'],
    ];

    /** @param array<string,mixed>|null $presentation */
    public function recordTransition(AiWorkspaceRun $run, ?array $presentation = null): AiWorkspaceTraceEvent
    {
        $defaults = self::STATE_PRESENTATION[(string) $run->state]
            ?? ['kind' => 'activity', 'title' => '处理请求', 'status' => 'running'];
        $event = array_replace($defaults, $presentation ?? []);
        $eventStatus = $this->status($event['status'] ?? $defaults['status']);
        $causationId = $run->traceEvents()->reorder('sequence', 'desc')->value('id');
        $protocolEvent = AiWorkspaceRunEvent::fromRun(
            $run,
            $event,
            is_string($causationId) && Str::isUuid($causationId) ? $causationId : null,
        );
        $now = $protocolEvent->occurredAt;

        $traceEvent = $run->traceEvents()->firstOrCreate(
            ['sequence' => (int) $run->event_sequence],
            [
                'id' => (string) Str::uuid7(),
                'step_id' => $this->uuidOrNull($event['step_id'] ?? null),
                'kind' => $this->plainText($event['kind'] ?? $defaults['kind'], 40),
                'title' => $this->plainText($event['title'] ?? $defaults['title'], 160),
                'summary' => $this->nullableText($event['summary'] ?? $run->status_message, 500),
                'status' => $eventStatus,
                'detail' => $this->safeDetail((array) ($event['detail'] ?? [])),
                'event_type' => $protocolEvent->type,
                'event_version' => AiWorkspaceRunEvent::PROTOCOL_VERSION,
                'correlation_id' => $protocolEvent->correlationId,
                'causation_id' => $protocolEvent->causationId,
                'actor_type' => $this->plainText($protocolEvent->actorType, 40),
                'actor_id' => $protocolEvent->actorId === null ? null : $this->plainText($protocolEvent->actorId, 120),
                'payload' => $this->safePayload($protocolEvent->payload),
                'visibility' => $protocolEvent->visibility,
                'occurred_at' => $protocolEvent->occurredAt,
                'started_at' => $now,
                'finished_at' => in_array($event['status'] ?? $defaults['status'], ['completed', 'failed', 'stopped'], true) ? $now : null,
            ],
        );

        if ($traceEvent->wasRecentlyCreated) {
            $run->forceFill(['last_event_at' => $protocolEvent->occurredAt])->saveQuietly();
        }

        return $traceEvent;
    }

    public function recordInitial(AiWorkspaceRun $run): AiWorkspaceTraceEvent
    {
        return $this->recordTransition($run, [
            'kind' => 'context',
            'title' => '运行上下文',
            'summary' => '运行记录已建立。',
            'status' => 'completed',
            'detail' => ['visibility' => 'log_only'],
            'event_type' => 'run.received',
            'visibility' => 'log_only',
        ]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function safePayload(array $payload): array
    {
        $safe = [];
        foreach (Arr::only($payload, [
            'resolution_source', 'model_id', 'provider', 'model', 'prompt_version', 'context_digest',
            'capability', 'capability_version', 'position', 'risk_level', 'execution_scope', 'approval_policy',
            'attempts', 'artifact_type', 'artifact_id', 'external_operation_id', 'failure_code', 'delivery_mode',
            'parent_run_id', 'child_run_id', 'budget', 'usage', 'timings',
        ]) as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;

                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $safe[$key] = $this->plainText($value, 240);

                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->safeNestedPayload($value);
            }
        }

        return $safe;
    }

    /** @param array<array-key,mixed> $payload @return array<array-key,string|int|float|bool|null> */
    private function safeNestedPayload(array $payload): array
    {
        $safe = [];
        foreach (array_slice($payload, 0, 20, true) as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $safe[$key] = $this->plainText($value, 180);
            }
        }

        return $safe;
    }

    /** @param array<string,mixed> $detail @return array<string,string|int|float|bool> */
    private function safeDetail(array $detail): array
    {
        $safe = [];
        foreach (Arr::only($detail, [
            'capability', 'capability_name', 'position', 'risk_level', 'execution_scope', 'approval_policy', 'attempts', 'artifact_type',
            'visibility',
        ]) as $key => $value) {
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;

                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $safe[$key] = $this->plainText($value, 180);
            }
        }

        return $safe;
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->plainText(AiWorkspaceErrorSanitizer::clean($value), $limit);
    }

    private function plainText(mixed $value, int $limit): string
    {
        return Str::limit(trim(strip_tags((string) $value)), $limit, '');
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function status(mixed $value): string
    {
        $status = (string) $value;

        return in_array($status, ['running', 'completed', 'attention', 'failed', 'stopped'], true) ? $status : 'running';
    }
}
