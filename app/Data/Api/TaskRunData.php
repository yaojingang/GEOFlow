<?php

namespace App\Data\Api;

use App\Models\TaskRun;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Illuminate\Support\Arr;

final class TaskRunData
{
    private const PUBLIC_META_KEYS = [
        'action',
        'ai_quality',
        'author_id',
        'available_at',
        'category_id',
        'failure_class',
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
        private readonly AiExecutionErrorSanitizer $errorSanitizer,
    ) {}

    /** @return array<string,mixed> */
    public function fromModel(TaskRun $run): array
    {
        $meta = is_array($run->meta) ? $run->meta : [];
        $payload = $this->publicPayload($meta['payload'] ?? null);
        $publicMeta = Arr::only(
            $this->errorSanitizer->sanitizeMeta($meta),
            self::PUBLIC_META_KEYS,
        );
        $errorMessage = $this->errorSanitizer->sanitize((string) ($run->error_message ?? ''), '');

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
            'error_message' => $errorMessage,
            'payload' => $payload,
            'task_run_summary' => [
                'article_id' => $run->article_id !== null ? (int) $run->article_id : null,
                'duration_ms' => max(0, (int) ($run->duration_ms ?? 0)),
                'status' => (string) $run->status,
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

        $workerId = $this->errorSanitizer->sanitize($workerId, '');

        return $workerId === '' ? null : mb_substr($workerId, 0, 120, 'UTF-8');
    }
}
