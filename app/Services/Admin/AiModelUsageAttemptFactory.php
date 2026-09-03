<?php

namespace App\Services\Admin;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class AiModelUsageAttemptFactory
{
    public function __construct(private AiModelUsageRecorder $recorder) {}

    public function requestId(): string
    {
        return (string) Str::uuid();
    }

    public function beginForAdmin(
        AiModel $model,
        int $executionAdminId,
        int $accessVersion,
        string $executionScope,
        string $modelSource,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
    ): AiModelUsageAttempt {
        $snapshot = null;
        try {
            $admin = new Admin;
            $admin->setAttribute($admin->getKeyName(), $executionAdminId);
            $snapshot = AiModelUsageAccessSnapshot::capture(
                model: $model,
                executionAdmin: $admin,
                executionScope: $executionScope,
                modelSource: $modelSource,
                aiConfigAccessVersion: $accessVersion,
                requestId: $requestId,
                requestPayloadDigest: hash('sha256', $requestPayload),
            );
        } catch (Throwable $exception) {
            $this->warnSafely($exception);
        }

        return new AiModelUsageAttempt($snapshot, $this->recorder, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    public function beginForSystem(
        AiModel $model,
        SystemAiIdentity $identity,
        string $requestId,
        string $requestPayload,
        string $callKey,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
    ): AiModelUsageAttempt {
        $identity->assertCanBuildKnowledgeIndex();
        $snapshot = AiModelUsageAccessSnapshot::capture(
            model: $model,
            executionAdmin: null,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            modelSource: AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            aiConfigAccessVersion: 0,
            requestId: $requestId,
            requestPayloadDigest: hash('sha256', $requestPayload),
        );

        return new AiModelUsageAttempt($snapshot, $this->recorder, [
            'call_key' => $callKey,
            'operation' => $operation,
            'business_source' => $businessSource,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    public function sourceFor(AiModel $model, int $executionAdminId): string
    {
        return (int) $model->owner_admin_id === $executionAdminId
            ? AiModelUsageEvent::MODEL_SOURCE_PERSONAL
            : AiModelUsageEvent::MODEL_SOURCE_SHARED;
    }

    private function warnSafely(Throwable $exception): void
    {
        try {
            Log::warning('AI usage telemetry attribution failed safely.', [
                'stage' => 'capture',
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable) {
            // Telemetry and its alert path must remain best effort.
        }
    }
}
