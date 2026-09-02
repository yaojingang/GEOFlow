<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\AiModelRuntimeEligibilityException;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\AiWorkspace\AiWorkspaceModelUnavailableException;
use Closure;

final readonly class DirectAdminAiModelInvocationGateway
{
    public function __construct(
        private DirectAdminAiExecutionGuard $executionGuard,
        private DirectAdminAiModelRuntimeGate $runtimeGate,
        private AiUsageQuotaService $usageQuota,
        private AiModelInvocationLock $invocationLocks,
        private DirectAdminAiInvocationBoundaryHook $boundaryHook,
        private AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    public function acquire(
        DirectAdminAiExecutionContext $context,
        int $leaseSeconds,
        ?Closure $candidateReadiness = null,
    ): DirectAdminAiModelInvocation {
        $explicit = $context->requestedModelId !== null;

        foreach ($this->executionGuard->candidateSelections($context) as $selection) {
            $candidate = $selection['model'];
            $lock = null;
            $this->boundaryHook->beforeCandidateLock($context, $candidate);

            try {
                $lock = $this->invocationLocks->acquireForInvocation((int) $candidate->id, $leaseSeconds);
                $model = $this->executionGuard->assertModelCurrent($context, $candidate);
                $this->runtimeGate->assertExecutable($model, $context->capability);
                $candidateReadiness?->__invoke($model);
                $reservation = $this->usageQuota->reserveModel($model);
                if ($reservation === null) {
                    throw AiModelRuntimeEligibilityException::quota();
                }

                return new DirectAdminAiModelInvocation(
                    $model,
                    $selection['source'],
                    $reservation,
                    $lock,
                    $this->usageQuota,
                    $this->invocationLocks,
                    $this->usageAttempts,
                    $context,
                );
            } catch (AiModelRuntimeEligibilityException|AiWorkspaceModelUnavailableException $exception) {
                $this->invocationLocks->release($lock);
                if ($explicit) {
                    throw $exception;
                }
            } catch (AiModelAccessException $exception) {
                $this->invocationLocks->release($lock);
                if ($explicit || $exception->getErrorCode() !== AiModelAccessException::AI_MODEL_UNAVAILABLE) {
                    throw $exception;
                }
            } catch (\Throwable $exception) {
                $this->invocationLocks->release($lock);

                throw $exception;
            }
        }

        throw AiModelAccessException::modelUnavailable($this->executionGuard->assertCurrent($context));
    }
}
