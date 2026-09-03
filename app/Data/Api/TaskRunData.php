<?php

namespace App\Data\Api;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\TaskRun;
use App\Support\GeoFlow\PublicExecutionErrorProjector;
use Illuminate\Support\Arr;

final class TaskRunData
{
    private const PUBLIC_META_KEYS = [
        'action',
        'ai_quality',
        'author_id',
        'available_at',
        'category_id',
        'image_count',
        'knowledge_length',
        'last_error',
        'model_selection_mode',
        'publish_interval',
        'reason',
        'retryable',
        'task_id',
        'title_id',
        'title_readiness',
    ];

    private const PUBLIC_PAYLOAD_SOURCES = [
        'api_enqueue',
        'api_manual_start',
        'follow_up_generation',
    ];

    private const PUBLIC_ACTIONS = [
        'await_ai_quality',
        'generate_draft',
        'noop',
        'publish_draft',
    ];

    private const PUBLIC_AI_QUALITY_STATUSES = [
        'cancelled',
        'completed',
        'failed',
        'pending',
        'queued',
        'running',
        'skipped',
        'stale',
    ];

    private const PUBLIC_TITLE_READINESS_STATUSES = ['blocked', 'ready', 'warning'];

    private const PUBLIC_TASK_STATUSES = ['active', 'paused'];

    private const PUBLIC_TITLE_ISSUES = [
        'loop_reuses_titles',
        'title_library_empty',
        'title_library_exhausted',
        'title_library_missing',
        'title_library_shared',
        'title_library_shortage',
    ];

    private const PUBLIC_TITLE_ISSUE_SEVERITIES = ['blocking', 'warning'];

    public function __construct(
        private readonly PublicExecutionErrorProjector $errorProjector,
    ) {}

    /** @return array<string,mixed> */
    public function fromModel(TaskRun $run, Admin $viewer): array
    {
        $this->assertViewerSnapshot($viewer);
        $meta = is_array($run->meta) ? $run->meta : [];
        $payload = $this->publicPayload($meta['payload'] ?? null);
        $publicMeta = $this->projectMeta(
            Arr::only($meta, self::PUBLIC_META_KEYS),
        );
        $errorCode = $this->errorProjector->stableCode($run->error_code, $run->error_message);
        $errorMessage = $this->errorProjector->genericMessage($errorCode);

        return [
            'id' => (int) $run->id,
            'task_id' => (int) $run->task_id,
            'job_type' => $this->publicJobType($meta['job_type'] ?? null),
            'status' => (string) $run->status,
            'attempt_count' => max(0, (int) ($meta['attempt_count'] ?? 0)),
            'max_attempts' => max(0, (int) ($meta['max_attempts'] ?? 0)),
            'worker_id' => $this->publicWorkerId($meta['worker_id'] ?? null),
            'claimed_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'payload' => $payload,
            'task_run_summary' => [
                'article_id' => $run->article_id !== null ? (int) $run->article_id : null,
                'duration_ms' => max(0, (int) ($run->duration_ms ?? 0)),
                'status' => (string) $run->status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'meta' => $publicMeta,
            ],
        ];
    }

    /** @return array{source:string}|array{} */
    private function publicPayload(mixed $payload): array
    {
        if (! is_array($payload) || ! is_string($payload['source'] ?? null)) {
            return [];
        }

        $source = trim($payload['source']);

        return in_array($source, self::PUBLIC_PAYLOAD_SOURCES, true)
            ? ['source' => $source]
            : [];
    }

    private function publicJobType(mixed $jobType): string
    {
        return $jobType === 'generate_article' ? $jobType : 'generate_article';
    }

    private function publicWorkerId(mixed $workerId): ?string
    {
        if (! is_string($workerId)) {
            return null;
        }

        $workerId = trim($workerId);

        $workerId = $this->errorProjector->sanitizedMetaText($workerId);

        return $workerId === '' ? null : mb_substr($workerId, 0, 120, 'UTF-8');
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function projectMeta(array $meta): array
    {
        $projected = [];
        $this->copyEnum($projected, $meta, 'action', self::PUBLIC_ACTIONS);
        foreach (['author_id', 'category_id', 'image_count', 'knowledge_length', 'publish_interval', 'task_id', 'title_id'] as $key) {
            $this->copyNonNegativeInteger($projected, $meta, $key);
        }
        $this->copyTimestamp($projected, $meta, 'available_at');
        $this->copyEnum($projected, $meta, 'model_selection_mode', ['fixed', 'smart_failover']);
        $this->copyBoolean($projected, $meta, 'retryable');
        $this->copyStableErrorCode($projected, $meta, 'last_error');
        $this->copyStableErrorCode($projected, $meta, 'reason');

        if (is_array($meta['ai_quality'] ?? null)) {
            $projected['ai_quality'] = $this->projectAiQuality($meta['ai_quality']);
        }
        if (is_array($meta['title_readiness'] ?? null)) {
            $projected['title_readiness'] = $this->projectTitleReadiness($meta['title_readiness']);
        }

        return $projected;
    }

    /** @param array<string,mixed> $quality @return array<string,mixed> */
    private function projectAiQuality(array $quality): array
    {
        $projected = [];
        $this->copyBoolean($projected, $quality, 'required');
        $this->copyNullablePositiveInteger($projected, $quality, 'check_id');
        $this->copyNullableEnum($projected, $quality, 'status', self::PUBLIC_AI_QUALITY_STATUSES);

        return $projected;
    }

    /** @param array<string,mixed> $readiness @return array<string,mixed> */
    private function projectTitleReadiness(array $readiness): array
    {
        $projected = [];
        $this->copyEnum($projected, $readiness, 'status', self::PUBLIC_TITLE_READINESS_STATUSES);
        foreach (['can_save', 'can_activate', 'requires_acknowledgement'] as $key) {
            $this->copyBoolean($projected, $readiness, $key);
        }

        if (is_array($readiness['library'] ?? null)) {
            $library = [];
            foreach (['total', 'used', 'available'] as $key) {
                $this->copyNonNegativeInteger($library, $readiness['library'], $key);
            }
            $projected['library'] = $library;
        }
        if (is_array($readiness['task'] ?? null)) {
            $task = [];
            $this->copyNullablePositiveInteger($task, $readiness['task'], 'id');
            $this->copyEnum($task, $readiness['task'], 'status', self::PUBLIC_TASK_STATUSES);
            foreach (['article_limit', 'created_count', 'remaining'] as $key) {
                $this->copyNonNegativeInteger($task, $readiness['task'], $key);
            }
            $this->copyBoolean($task, $readiness['task'], 'is_loop');
            $projected['task'] = $task;
        }
        foreach (['shortage', 'suggested_article_limit', 'conflict_count'] as $key) {
            $this->copyNonNegativeInteger($projected, $readiness, $key);
        }

        if (is_array($readiness['issues'] ?? null)) {
            $issues = [];
            foreach ($readiness['issues'] as $issue) {
                if (! is_array($issue)
                    || ! in_array($issue['code'] ?? null, self::PUBLIC_TITLE_ISSUES, true)
                    || ! in_array($issue['severity'] ?? null, self::PUBLIC_TITLE_ISSUE_SEVERITIES, true)) {
                    continue;
                }
                $issues[] = [
                    'code' => $issue['code'],
                    'severity' => $issue['severity'],
                ];
            }
            $projected['issues'] = $issues;
        }

        return $projected;
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source @param list<string> $allowed */
    private function copyEnum(array &$target, array $source, string $key, array $allowed): void
    {
        if (is_string($source[$key] ?? null) && in_array($source[$key], $allowed, true)) {
            $target[$key] = $source[$key];
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source @param list<string> $allowed */
    private function copyNullableEnum(array &$target, array $source, string $key, array $allowed): void
    {
        if (! array_key_exists($key, $source)) {
            return;
        }
        if ($source[$key] === null || (is_string($source[$key]) && in_array($source[$key], $allowed, true))) {
            $target[$key] = $source[$key];
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function copyBoolean(array &$target, array $source, string $key): void
    {
        if (is_bool($source[$key] ?? null)) {
            $target[$key] = $source[$key];
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function copyNonNegativeInteger(array &$target, array $source, string $key): void
    {
        if (is_int($source[$key] ?? null) && $source[$key] >= 0) {
            $target[$key] = $source[$key];
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function copyNullablePositiveInteger(array &$target, array $source, string $key): void
    {
        if (! array_key_exists($key, $source)) {
            return;
        }
        if ($source[$key] === null || (is_int($source[$key]) && $source[$key] > 0)) {
            $target[$key] = $source[$key];
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function copyTimestamp(array &$target, array $source, string $key): void
    {
        $value = $source[$key] ?? null;
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})?$/D', $value) === 1) {
            $target[$key] = $value;
        }
    }

    /** @param array<string,mixed> $target @param array<string,mixed> $source */
    private function copyStableErrorCode(array &$target, array $source, string $key): void
    {
        $code = $this->errorProjector->stableCode(null, $source[$key] ?? null);
        if ($code !== null) {
            $target[$key] = $code;
        }
    }

    private function assertViewerSnapshot(Admin $viewer): void
    {
        $role = trim(strtolower((string) $viewer->role));
        if ((int) $viewer->getKey() <= 0
            || (string) $viewer->status !== 'active'
            || ! in_array($role, ['admin', 'super_admin', 'superadmin'], true)) {
            throw new ApiException(
                AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
                'Token 所属管理员当前不可用',
                403,
            );
        }
    }
}
