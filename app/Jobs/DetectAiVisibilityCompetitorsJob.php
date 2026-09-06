<?php

namespace App\Jobs;

use App\Models\AiVisibilityRun;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DetectAiVisibilityCompetitorsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 1200;

    public array $backoff = [30, 120];

    public readonly string $executionUuid;

    public function __construct(public readonly int $runId)
    {
        $this->executionUuid = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(AiVisibilityCompetitorDetectionService $detection): void
    {
        try {
            $detection->detectRun($this->runId, $this->executionUuid);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getMessage(), [
                'ai_model_unavailable', 'ai_model_not_accessible', 'ai_model_quota_exhausted',
                'ai_config_access_revoked', 'ai_result_discarded', 'ai_provider_auth_failed',
                'ai_provider_request_failed', 'ai_competitor_response_invalid',
                'ai_competitor_detection_outcome_unknown', 'ai_competitor_execution_failed',
            ], true)) {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        AiVisibilityRun::query()->where('parent_run_id', $this->runId)
            ->where('uuid', $this->executionUuid)
            ->where('provider_type', AiVisibilityRun::PROVIDER_COMPETITOR_DETECTION)
            ->where('status', AiVisibilityRun::STATUS_RUNNING)
            ->update(['status' => AiVisibilityRun::STATUS_FAILED, 'error_message' => 'ai_competitor_execution_interrupted', 'completed_at' => now()]);
        Log::warning('ai_visibility_competitor_job_failed', ['run_id' => $this->runId, 'exception_type' => $exception ? $exception::class : null]);
    }
}
