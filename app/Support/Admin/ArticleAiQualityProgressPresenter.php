<?php

namespace App\Support\Admin;

use App\Models\ArticleAiQualityCheck;
use App\Services\GeoFlow\ArticleAiQualityWorkerLiveness;

class ArticleAiQualityProgressPresenter
{
    public function __construct(private readonly ArticleAiQualityWorkerLiveness $workerLiveness) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?ArticleAiQualityCheck $check): array
    {
        if (! $check) {
            return $this->payload(null, 'not_started', 'not_started', 0, 0, 0, false, false);
        }

        $status = (string) $check->status;
        $total = max(0, (int) $check->segment_count);
        $completed = min($total, max(0, (int) $check->completed_segment_count));

        if ($status === 'completed') {
            return $this->payload($check, $status, 'completed', 100, $completed, $total, false, true);
        }

        if (in_array($status, ['failed', 'stale', 'cancelled'], true)) {
            return $this->payload(
                $check,
                $status,
                $status,
                $this->activeProgress($check, $completed, $total),
                $completed,
                $total,
                false,
                true,
            );
        }

        if (! in_array($status, ['queued', 'running'], true)) {
            return $this->payload($check, $status, $status, 0, $completed, $total, false, false);
        }

        [$phase, $progress] = $this->activePhase($check, $completed, $total);

        return $this->payload($check, $status, $phase, $progress, $completed, $total, true, false);
    }

    /** @return array{string, int} */
    private function activePhase(ArticleAiQualityCheck $check, int $completed, int $total): array
    {
        if ((string) $check->inspection_scope === 'fallback_sampled'
            || (string) data_get($check->execution_meta, 'current_phase') === 'sampling_queued') {
            return ['sampling', (string) $check->status === 'running' ? 90 : 82];
        }

        if ($check->primary_deadline_at?->lte(now())
            && (bool) data_get($check->execution_meta, 'policy_snapshot.timeout_sampling_enabled', false)) {
            return ['sampling', 82];
        }

        if ($total > 0 && $completed >= $total) {
            return ['summarizing', 94];
        }

        if ($completed > 0 || $this->hasEvidence($check)) {
            $ratio = $total > 0 ? $completed / $total : 0;

            return ['inspecting', min(88, 24 + (int) floor($ratio * 64))];
        }

        if ((string) $check->status === 'running') {
            return ['evidence', 18];
        }

        return ['queued', 8];
    }

    private function activeProgress(ArticleAiQualityCheck $check, int $completed, int $total): int
    {
        [, $progress] = $this->activePhase($check, $completed, $total);

        return $progress;
    }

    private function hasEvidence(ArticleAiQualityCheck $check): bool
    {
        return is_array($check->evidence_snapshot) && $check->evidence_snapshot !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        ?ArticleAiQualityCheck $check,
        string $status,
        string $phase,
        int $progress,
        int $completed,
        int $total,
        bool $active,
        bool $reload,
    ): array {
        $translationPhase = in_array($phase, ['queued', 'evidence', 'inspecting', 'sampling', 'summarizing', 'completed'], true)
            ? $phase
            : 'finished';
        $executionMeta = is_array($check?->execution_meta) ? $check->execution_meta : [];
        $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
            ? $executionMeta['workflow_apply']
            : [];
        $workflowApplyStatus = (string) ($workflowApply['status'] ?? '');
        $timings = is_array($executionMeta['timings_ms'] ?? null)
            ? array_map(static fn (mixed $value): int => max(0, (int) $value), $executionMeta['timings_ms'])
            : [];
        $startedAt = $check?->created_at;
        $elapsedEnd = $check?->finished_at ?: now();
        $elapsedMs = $startedAt ? max(0, (int) round($startedAt->diffInMilliseconds($elapsedEnd))) : 0;
        $deadlineAt = $check?->deadline_at
            ?: $startedAt?->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
        $primaryDeadlineAt = $check?->primary_deadline_at
            ?: $startedAt?->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
        $sampledDeadlineAt = $check?->sampled_deadline_at;
        $activeDeadlineAt = (string) ($check?->inspection_scope ?: 'full') === 'fallback_sampled'
            ? ($sampledDeadlineAt ?: $deadlineAt)
            : $deadlineAt;
        $safeErrorCode = $this->safeErrorCode($check?->error_code);
        $serviceStatus = $this->workerLiveness->serviceStatus();
        $effectiveStatus = $status;
        $reconciling = false;
        if ($active && $check && $activeDeadlineAt?->copy()->addSeconds(5)->lte(now())) {
            $effectiveStatus = 'failed';
            $active = false;
            $reload = false;
            $reconciling = true;
            $safeErrorCode = $this->workerLiveness->expirationCode($check);
            $translationPhase = 'finished';
        }
        $message = __('admin.articles.ai_quality.progress_'.$translationPhase, [
            'completed' => $completed,
            'total' => $total,
        ]);
        $detail = __('admin.articles.ai_quality.progress_'.$translationPhase.'_detail');
        if ($effectiveStatus === 'failed') {
            $message = __('admin.articles.ai_quality.progress_failed');
        } elseif ($active && $serviceStatus === 'unavailable') {
            $message = __('admin.articles.ai_quality.progress_service_unavailable');
            $detail = __('admin.articles.ai_quality.progress_service_unavailable_detail');
        } elseif ($active && $serviceStatus === 'degraded') {
            $detail = __('admin.articles.ai_quality.progress_service_degraded_detail');
        }
        $queueWaitMs = $check?->started_at
            ? max(0, (int) round($check->created_at->diffInMilliseconds($check->started_at)))
            : ($active && $startedAt ? $elapsedMs : null);
        $retryableByCode = in_array($safeErrorCode, [
            'provider_timeout',
            'provider_rate_limited',
            'provider_gateway_error',
            'provider_circuit_open',
            'structured_output_unsupported',
            'invalid_model_output',
            'evidence_retrieval_failed',
            'queue_dispatch_failed',
            'model_timeout',
            'inspection_deadline_exceeded',
            'inspection_primary_deadline_exceeded',
            'worker_interrupted',
            'queue_worker_unavailable',
            'queue_wait_timeout',
        ], true);
        $retryable = array_key_exists('retryable_failure', $executionMeta)
            ? (bool) $executionMeta['retryable_failure']
            : $retryableByCode;
        $failure = in_array($effectiveStatus, ['failed', 'stale', 'cancelled'], true)
            ? $this->failureDetails($safeErrorCode, $retryable, $executionMeta, $elapsedMs)
            : null;
        if ($effectiveStatus === 'failed' && is_array($failure)) {
            $detail = (string) $failure['reason'];
        }
        $scope = (string) ($check?->inspection_scope ?: 'full');
        $degraded = $scope === 'fallback_sampled';
        $coverage = $this->publicCoverage(is_array($check?->coverage_meta) ? $check->coverage_meta : []);
        $fallback = is_array($executionMeta['fallback'] ?? null) ? $executionMeta['fallback'] : [];
        if ($check?->fallback_trigger_code) {
            $fallback['trigger_code'] = (string) $check->fallback_trigger_code;
        }
        $deadlineWarning = $active && $activeDeadlineAt && now()->diffInSeconds($activeDeadlineAt, false) <= 30;

        return [
            'check_id' => $check?->id,
            'status' => $status,
            'effective_status' => $effectiveStatus,
            'phase' => $phase,
            'progress_percent' => max(0, min(100, $progress)),
            'completed_segments' => $completed,
            'total_segments' => $total,
            'active' => $active,
            'reconciling' => $reconciling,
            'reload' => $reload,
            'message' => $message,
            'detail' => $detail,
            'segments_label' => $total > 0
                ? __('admin.articles.ai_quality.progress_segments', ['completed' => $completed, 'total' => $total])
                : __('admin.articles.ai_quality.progress_preparing'),
            'elapsed_ms' => $elapsedMs,
            'elapsed_label' => __('admin.articles.ai_quality.progress_elapsed', [
                'seconds' => (int) floor($elapsedMs / 1000),
                'minutes' => max(1, (int) ceil($this->deadlineSeconds($executionMeta) / 60)),
            ]),
            'deadline_at' => $deadlineAt?->toIso8601String(),
            'primary_deadline_at' => $primaryDeadlineAt?->toIso8601String(),
            'sampled_deadline_at' => $sampledDeadlineAt?->toIso8601String(),
            'active_deadline_at' => $activeDeadlineAt?->toIso8601String(),
            'inspection_scope' => $scope,
            'requested_retrieval_mode' => $check?->requested_retrieval_mode,
            'effective_retrieval_mode' => $check?->effective_retrieval_mode,
            'retrieval_strategy_version' => $check?->retrieval_strategy_version,
            'degraded' => $degraded,
            'result_label' => $effectiveStatus === 'failed'
                ? __('admin.articles.ai_quality.failed')
                : $this->resultLabel($check, $degraded),
            'score_label' => $degraded
                ? __('admin.articles.ai_quality.sampled_score_label')
                : __('admin.articles.ai_quality.full_score_label'),
            'coverage' => $coverage,
            'fallback' => $fallback,
            'queue_wait_ms' => $queueWaitMs,
            'service_status' => $serviceStatus,
            'deadline_warning' => $deadlineWarning
                ? __('admin.articles.ai_quality.progress_ending')
                : null,
            'timings' => $timings,
            'safe_error_code' => $safeErrorCode,
            'retryable' => $retryable,
            'failure' => $failure,
            'workflow_apply' => [
                'status' => $workflowApplyStatus,
                'attempts' => max(0, (int) ($workflowApply['attempts'] ?? 0)),
                'error_code' => in_array($workflowApplyStatus, ['failed', 'exhausted'], true)
                    ? (string) ($workflowApply['error_code'] ?? 'workflow_apply_failed')
                    : null,
                'operator_retryable' => $workflowApplyStatus === 'exhausted',
            ],
            'next_action' => match (true) {
                $active => 'wait',
                $workflowApplyStatus === 'exhausted' => 'retry_workflow',
                $effectiveStatus === 'completed' => 'view_result',
                in_array($effectiveStatus, ['failed', 'stale', 'cancelled'], true) => $this->failureAction(
                    $safeErrorCode,
                    $retryable,
                ),
                default => 'none',
            },
            'updated_at' => $check?->updated_at?->toIso8601String(),
            'next_poll_ms' => $reconciling ? 5000 : 2000,
        ];
    }

    private function safeErrorCode(?string $errorCode): ?string
    {
        if ($errorCode === null || $errorCode === '') {
            return null;
        }

        $allowed = [
            'provider_timeout',
            'provider_rate_limited',
            'provider_gateway_error',
            'provider_quota_exhausted',
            'provider_authentication_failed',
            'provider_circuit_open',
            'structured_output_unsupported',
            'invalid_model_output',
            'evidence_retrieval_failed',
            'inspection_deadline_exceeded',
            'inspection_primary_deadline_exceeded',
            'input_too_large',
            'model_output_truncated',
            'output_budget_exhausted',
            'remaining_budget_insufficient',
            'queue_dispatch_failed',
            'model_timeout',
            'model_quota_exceeded',
            'model_unavailable',
            'input_changed',
            'worker_interrupted',
            'queue_worker_unavailable',
            'queue_wait_timeout',
            'inspection_failed',
            'sampling_policy_disabled',
        ];

        return in_array($errorCode, $allowed, true) ? $errorCode : 'inspection_failed';
    }

    /**
     * @param  array<string, mixed>  $executionMeta
     * @return array{code:string,title:string,reason:string,next_step:string,retryable:bool,model_attempt_seconds:int,provider_http_status:?int,provider_code:?string}
     */
    private function failureDetails(
        ?string $safeErrorCode,
        bool $retryable,
        array $executionMeta,
        int $elapsedMs,
    ): array {
        $code = $safeErrorCode ?: 'inspection_failed';
        $attempts = is_array($executionMeta['model_attempts'] ?? null)
            ? $executionMeta['model_attempts']
            : [];
        $lastAttempt = $attempts === [] ? [] : end($attempts);
        $attemptMs = is_array($lastAttempt) ? max(0, (int) ($lastAttempt['duration_ms'] ?? 0)) : 0;
        $attemptSeconds = (int) round(($attemptMs > 0 ? $attemptMs : $elapsedMs) / 1000);
        $storedFailure = is_array($executionMeta['failure'] ?? null) ? $executionMeta['failure'] : [];
        $providerHttpStatus = (int) ($storedFailure['http_status'] ?? 0);
        $providerCode = trim((string) ($storedFailure['provider_code'] ?? ''));

        $titleKey = match ($code) {
            'provider_timeout', 'model_timeout', 'inspection_deadline_exceeded', 'inspection_primary_deadline_exceeded' => 'failure_title_timeout',
            'input_too_large' => 'failure_title_input_too_large',
            'provider_gateway_error', 'queue_dispatch_failed' => 'failure_title_connection',
            'provider_rate_limited', 'provider_quota_exhausted', 'model_quota_exceeded', 'provider_circuit_open' => 'failure_title_capacity',
            'provider_authentication_failed', 'model_unavailable' => 'failure_title_configuration',
            'structured_output_unsupported', 'invalid_model_output' => 'failure_title_output',
            'model_output_truncated', 'output_budget_exhausted', 'remaining_budget_insufficient' => 'failure_title_output_budget',
            'evidence_retrieval_failed' => 'failure_title_evidence',
            'queue_worker_unavailable', 'queue_wait_timeout', 'worker_interrupted' => 'failure_title_worker',
            'input_changed' => 'failure_title_stale',
            'sampling_policy_disabled' => 'failure_title_sampling_disabled',
            default => 'failure_title_generic',
        };
        $reasonKey = match ($code) {
            'provider_timeout', 'model_timeout' => 'failure_reason_provider_timeout',
            'inspection_deadline_exceeded', 'inspection_primary_deadline_exceeded' => 'failure_reason_deadline',
            'input_too_large' => 'failure_reason_input_too_large',
            'provider_gateway_error' => 'failure_reason_provider_gateway',
            'provider_rate_limited' => 'failure_reason_rate_limited',
            'provider_quota_exhausted', 'model_quota_exceeded' => 'failure_reason_quota',
            'provider_circuit_open' => 'failure_reason_circuit_open',
            'provider_authentication_failed' => 'failure_reason_authentication',
            'structured_output_unsupported' => 'failure_reason_structured_output',
            'invalid_model_output' => 'failure_reason_invalid_output',
            'model_output_truncated' => 'failure_reason_output_truncated',
            'output_budget_exhausted' => 'failure_reason_output_budget',
            'remaining_budget_insufficient' => 'failure_reason_remaining_budget',
            'evidence_retrieval_failed' => 'failure_reason_evidence',
            'queue_dispatch_failed' => 'failure_reason_queue_dispatch',
            'queue_worker_unavailable' => 'failure_reason_worker_unavailable',
            'queue_wait_timeout' => 'failure_reason_queue_wait',
            'worker_interrupted' => 'failure_reason_worker_interrupted',
            'model_unavailable' => 'failure_reason_model_unavailable',
            'input_changed' => 'failure_reason_input_changed',
            'sampling_policy_disabled' => 'failure_reason_sampling_disabled',
            default => 'failure_reason_generic',
        };
        $nextStepKey = match ($code) {
            'provider_authentication_failed', 'model_unavailable', 'structured_output_unsupported', 'invalid_model_output' => 'failure_next_step_configuration',
            'provider_quota_exhausted', 'model_quota_exceeded' => 'failure_next_step_quota',
            'queue_worker_unavailable', 'worker_interrupted' => 'failure_next_step_worker',
            'evidence_retrieval_failed' => 'failure_next_step_evidence',
            'input_too_large' => 'failure_next_step_input_too_large',
            'input_changed' => 'failure_next_step_input_changed',
            'sampling_policy_disabled' => 'failure_next_step_sampling_disabled',
            default => $retryable ? 'failure_next_step_retry' : 'failure_next_step_admin',
        };

        return [
            'code' => $code,
            'title' => __('admin.articles.ai_quality.'.$titleKey),
            'reason' => __('admin.articles.ai_quality.'.$reasonKey, [
                'seconds' => max(0, $attemptSeconds),
                'deadline' => $this->deadlineSeconds($executionMeta),
            ]),
            'next_step' => __('admin.articles.ai_quality.'.$nextStepKey),
            'retryable' => $retryable,
            'model_attempt_seconds' => max(0, $attemptSeconds),
            'provider_http_status' => $providerHttpStatus >= 100 && $providerHttpStatus <= 599
                ? $providerHttpStatus
                : null,
            'provider_code' => preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/D', $providerCode) === 1
                ? $providerCode
                : null,
        ];
    }

    private function failureAction(?string $safeErrorCode, bool $retryable): string
    {
        $action = match ($safeErrorCode) {
            'provider_authentication_failed',
            'provider_quota_exhausted',
            'model_quota_exceeded',
            'model_unavailable',
            'structured_output_unsupported',
            'invalid_model_output' => 'configure_model',
            'evidence_retrieval_failed' => 'review_knowledge',
            'input_too_large', 'input_changed' => 'edit_article',
            'sampling_policy_disabled' => 'review_task_policy',
            default => null,
        };

        return $action ?? ($retryable ? 'retry' : 'contact_admin');
    }

    /** @param array<string,mixed> $executionMeta */
    private function deadlineSeconds(array $executionMeta): int
    {
        $seconds = (int) config('geoflow.ai_quality_deadline_seconds', 180);
        if (is_array($executionMeta['fallback'] ?? null)) {
            $seconds += (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45);
            $seconds += (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10);
        }

        return max(1, $seconds);
    }

    /** @param array<string,mixed> $coverage @return array<string,mixed> */
    private function publicCoverage(array $coverage): array
    {
        unset($coverage['sampled_content']);
        if (is_array($coverage['sampled_ranges'] ?? null)) {
            $coverage['sampled_ranges'] = array_values(array_map(static function (array $range): array {
                unset($range['content']);

                return $range;
            }, array_values(array_filter($coverage['sampled_ranges'], 'is_array'))));
        }

        return $coverage;
    }

    private function resultLabel(?ArticleAiQualityCheck $check, bool $degraded): string
    {
        if (! $check || (string) $check->status !== 'completed') {
            return $degraded
                ? __('admin.articles.ai_quality.sampled_in_progress_label')
                : __('admin.articles.ai_quality.full_in_progress_label');
        }

        if ((string) $check->decision === 'passed') {
            return $degraded
                ? __('admin.articles.ai_quality.sampled_passed_label')
                : __('admin.articles.ai_quality.full_passed_label');
        }

        return __('admin.articles.ai_quality.'.$check->decision);
    }
}
