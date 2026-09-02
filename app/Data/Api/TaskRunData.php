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

    /** @param array<string|int,mixed> $meta @return array<string|int,mixed> */
    private function projectMeta(array $meta): array
    {
        $projected = [];
        foreach ($meta as $key => $value) {
            $normalizedKey = is_string($key) ? $this->normalizeMetaKey($key) : '';
            if ($normalizedKey !== '' && $this->isSensitiveMetaKey($normalizedKey)) {
                continue;
            }

            if (is_array($value)) {
                $projected[$key] = $this->projectMeta($value);
            } elseif (is_string($value)) {
                if (in_array($normalizedKey, ['error', 'errormessage', 'lasterror', 'reason'], true)) {
                    $projected[$key] = $this->errorProjector->stableCode(null, $value);
                } else {
                    $projected[$key] = mb_substr(
                        $this->errorProjector->sanitizedMetaText($value),
                        0,
                        240,
                        'UTF-8',
                    );
                }
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $projected[$key] = $value;
            }
        }

        return $projected;
    }

    private function isSensitiveMetaKey(string $normalizedKey): bool
    {
        return $normalizedKey === 'key'
            || $normalizedKey === 'model'
            || $normalizedKey === 'provider'
            || $normalizedKey === 'response'
            || $normalizedKey === 'output'
            || $normalizedKey === 'message'
            || str_contains($normalizedKey, 'modelattempt')
            || str_ends_with($normalizedKey, 'modelid')
            || str_ends_with($normalizedKey, 'modelname')
            || str_starts_with($normalizedKey, 'usedmodel')
            || str_starts_with($normalizedKey, 'requestedmodel')
            || str_starts_with($normalizedKey, 'resolvedmodel')
            || str_contains($normalizedKey, 'modelaccessadmin')
            || str_contains($normalizedKey, 'executionadmin')
            || str_contains($normalizedKey, 'owneradmin')
            || str_contains($normalizedKey, 'configowner')
            || str_contains($normalizedKey, 'accessversion')
            || str_contains($normalizedKey, 'policyversion')
            || str_contains($normalizedKey, 'executionlease')
            || str_contains($normalizedKey, 'apikey')
            || str_contains($normalizedKey, 'apiurl')
            || str_contains($normalizedKey, 'baseurl')
            || $normalizedKey === 'url'
            || str_ends_with($normalizedKey, 'url')
            || str_contains($normalizedKey, 'endpoint')
            || str_contains($normalizedKey, 'prompt')
            || str_contains($normalizedKey, 'content')
            || str_contains($normalizedKey, 'providerresponse')
            || str_contains($normalizedKey, 'rawresponse')
            || str_contains($normalizedKey, 'rawoutput')
            || str_contains($normalizedKey, 'authorization')
            || str_contains($normalizedKey, 'password')
            || str_contains($normalizedKey, 'secret')
            || str_contains($normalizedKey, 'token')
            || str_contains($normalizedKey, 'bearer')
            || str_contains($normalizedKey, 'credential');
    }

    private function normalizeMetaKey(string $key): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? '';
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
