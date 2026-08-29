<?php

namespace App\Services\Admin;

use App\Contracts\SystemUpdater\AgentClient;
use Throwable;

class SystemUpdaterBridgeService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly SystemUpdaterBootstrapService $bootstrapService,
        private readonly SystemUpdaterMutationPolicy $mutationPolicy,
        private readonly SystemUpdateOperationGuard $operationGuard,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        try {
            $status = $this->agentClient->status();
            $doctorStatus = in_array($status['status'] ?? null, ['pass', 'warn', 'fail'], true)
                ? (string) $status['status']
                : 'fail';
            $connection = $doctorStatus === 'pass' ? 'connected' : 'degraded';
            $checks = $this->mutationPolicy->checks($status);
            $phaseBHandoverReady = $this->mutationPolicy->phaseBHandoverReady($status);
            $mutationAuthorizationReady = $this->mutationPolicy->authorizationReady($status);

            try {
                $currentOperation = $this->agentClient->currentOperation();
                $recoveryPoints = $this->agentClient->recoveryPoints();
                $operationsAvailable = true;
            } catch (Throwable) {
                $currentOperation = null;
                $recoveryPoints = [];
                $operationsAvailable = false;
            }

            return [
                'connection' => $connection,
                'doctor_status' => $doctorStatus,
                'updater_version' => (string) ($status['updater_version'] ?? ''),
                'instance' => is_array($status['instance'] ?? null) ? $status['instance'] : [],
                'checks' => $checks,
                'operations_available' => $operationsAvailable,
                'mutation_authorization_ready' => $mutationAuthorizationReady,
                'phase_b_handover_ready' => $phaseBHandoverReady,
                'legacy_worker_absent' => $this->operationGuard->retiredWorkerAbsent($status),
                'current_operation' => is_array($currentOperation) ? $currentOperation : null,
                'recovery_points' => $recoveryPoints,
                'prepared' => $this->bootstrapService->state(),
            ];
        } catch (Throwable) {
            return [
                'connection' => 'disconnected',
                'doctor_status' => 'unavailable',
                'updater_version' => '',
                'instance' => [],
                'checks' => [],
                'operations_available' => false,
                'mutation_authorization_ready' => false,
                'phase_b_handover_ready' => false,
                'legacy_worker_absent' => false,
                'current_operation' => null,
                'recovery_points' => [],
                'prepared' => $this->bootstrapService->state(),
            ];
        }
    }
}
