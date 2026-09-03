<?php

namespace App\Support;

use App\Models\TitleGenerationRun;

final class TitleGenerationStatus
{
    /** @return array<string, int|string|bool|null> */
    public static function payload(TitleGenerationRun $run): array
    {
        return [
            'id' => (int) $run->getKey(),
            'status' => (string) $run->status,
            'requested_count' => (int) $run->requested_count,
            'requested_from_model_count' => (int) $run->requested_from_model_count,
            'generated_count' => (int) $run->generated_count,
            'saved_count' => (int) $run->saved_count,
            'duplicate_count' => (int) $run->duplicate_count,
            'invalid_count' => (int) $run->invalid_count,
            'batch_count' => (int) $run->batch_count,
            'progress_percent' => $run->progressPercent(),
            'active' => $run->isActive(),
            'retryable' => $run->isRetryable(),
            'next_poll_ms' => in_array((string) $run->failure_code, ['quota_wait', 'quota_resume_failed'], true)
                ? 300_000
                : 2_500,
            'last_error' => self::failureMessage((string) $run->failure_code),
            'notice' => in_array((string) $run->failure_code, ['quota_wait', 'quota_resume_failed'], true)
                ? __('admin.title_ai_generate.notice.'.(string) $run->failure_code, [
                    'time' => $run->available_at?->format('Y-m-d H:i') ?? '-',
                ])
                : null,
        ];
    }

    public static function failureMessage(string $failureCode): ?string
    {
        return match ($failureCode) {
            'no_progress' => __('admin.title_ai_generate.error.no_progress'),
            'request_budget_exhausted' => __('admin.title_ai_generate.error.request_budget_exhausted'),
            'batch_attempts_exhausted' => __('admin.title_ai_generate.error.run_failed'),
            'dispatch_failed' => __('admin.title_ai_generate.error.queue_failed'),
            'batch_failed' => __('admin.title_ai_generate.error.run_failed'),
            'permanent_ai_failure' => __('admin.title_ai_generate.error.run_failed'),
            default => null,
        };
    }
}
