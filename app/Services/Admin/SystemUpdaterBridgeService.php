<?php

namespace App\Services\Admin;

use App\Contracts\SystemUpdater\AgentClient;
use Throwable;

class SystemUpdaterBridgeService
{
    public function __construct(
        private readonly AgentClient $agentClient,
        private readonly SystemUpdaterBootstrapService $bootstrapService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        try {
            $status = $this->agentClient->status();
            $doctorStatus = (string) ($status['status'] ?? 'fail');
            $connection = in_array($doctorStatus, ['pass', 'warn'], true) ? 'connected' : 'degraded';

            return [
                'connection' => $connection,
                'doctor_status' => $doctorStatus,
                'updater_version' => (string) ($status['updater_version'] ?? ''),
                'instance' => is_array($status['instance'] ?? null) ? $status['instance'] : [],
                'checks' => is_array($status['checks'] ?? null) ? $status['checks'] : [],
                'prepared' => $this->bootstrapService->state(),
            ];
        } catch (Throwable) {
            return [
                'connection' => 'disconnected',
                'doctor_status' => 'unavailable',
                'updater_version' => '',
                'instance' => [],
                'checks' => [],
                'prepared' => $this->bootstrapService->state(),
            ];
        }
    }
}
