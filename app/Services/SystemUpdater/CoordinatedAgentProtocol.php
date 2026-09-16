<?php

namespace App\Services\SystemUpdater;

use Illuminate\Support\Str;
use RuntimeException;

/** Version two keeps admission metadata separate from the immutable v1 operation shape. */
trait CoordinatedAgentProtocol
{
    public function capabilities(): array
    {
        $data = $this->coordinatedRequest('GET', 'capabilities');
        if (($data['schema_version'] ?? null) !== 2 || ($data['instance_id'] ?? null) !== $this->instanceId()
            || ($data['protocol_version'] ?? null) !== 2 || ($data['updater_protocol'] ?? null) !== 5
            || ! is_array($data['actions'] ?? null) || ! array_is_list($data['actions']) || count($data['actions']) > 4
            || array_diff($data['actions'], ['update', 'backup', 'restore', 'switch-back']) !== []
            || ! is_array($data['features'] ?? null) || ! is_array($data['recovery'] ?? null)
            || ($data['maintenance_confirmation'] ?? null) !== true
            || ($data['restore_policy'] ?? null) !== 'latest_update_checkpoint'
            || ! in_array($data['background_status'] ?? null, ['ready', 'held'], true)) {
            throw new RuntimeException('Updater returned unsupported capabilities.');
        }
        foreach (['plans', 'requests', 'idempotency', 'recovery_epoch'] as $feature) {
            if (($data['features'][$feature] ?? null) !== true) {
                throw new RuntimeException('Updater admission guarantees are unavailable.');
            }
        }
        foreach (['host_id', 'epoch'] as $key) {
            if (! $this->protocolHex($data['recovery'][$key] ?? null, 32)) {
                throw new RuntimeException('Updater recovery identity is invalid.');
            }
        }
        if (! in_array($data['recovery']['phase'] ?? null, ['ready', 'restoring', 'validating', 'http_ready'], true)) {
            throw new RuntimeException('Updater recovery phase is invalid.');
        }

        return $data;
    }

    public function createActionPlan(array $request): array
    {
        return $this->validateActionPlan($this->coordinatedRequest('POST', 'plans', $request));
    }

    public function actionPlan(string $planId): array
    {
        if (! $this->protocolHex($planId, 32)) {
            throw new RuntimeException('Updater plan identifier is invalid.');
        }
        $plan = $this->validateActionPlan($this->coordinatedRequest('GET', 'plans/'.$planId));
        if ($plan['plan_id'] !== $planId) {
            throw new RuntimeException('Updater returned a different plan.');
        }

        return $plan;
    }

    public function submitAction(array $request, #[\SensitiveParameter] ?string $authorizationCode): array
    {
        if ($authorizationCode !== null) {
            $this->validateAuthorizationCode($authorizationCode);
        }

        return $this->validateAdmissionReceipt($this->coordinatedRequest('POST', 'operations', $request, $authorizationCode));
    }

    public function requestReceipt(string $requestId): ?array
    {
        if (! is_string($requestId) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $requestId) !== 1) {
            throw new RuntimeException('Updater request identifier is invalid.');
        }
        try {
            $receipt = $this->validateAdmissionReceipt($this->coordinatedRequest('GET', 'requests/'.$requestId));
        } catch (AgentProtocolException $exception) {
            if ($exception->httpStatus === 404 && $exception->errorCode === 'request_not_found') {
                return null;
            }
            throw $exception;
        }
        if ($receipt['client_request_id'] !== $requestId) {
            throw new RuntimeException('Updater returned a different request receipt.');
        }

        return $receipt;
    }

    public function operationReceipt(string $operationId): array
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}\.[0-9]{9}Z-[a-f0-9]{16}\z/', $operationId) !== 1) {
            throw new RuntimeException('Updater operation identifier is invalid.');
        }
        $receipt = $this->validateAdmissionReceipt($this->coordinatedRequest('GET', 'operations/'.$operationId));
        if ($receipt['operation_id'] !== $operationId) {
            throw new RuntimeException('Updater returned a different operation receipt.');
        }

        return $receipt;
    }

    private function validateActionPlan(array $plan): array
    {
        if (($plan['schema_version'] ?? null) !== 2 || ($plan['instance_id'] ?? null) !== $this->instanceId()
            || ! $this->protocolHex($plan['plan_id'] ?? null, 32) || ! $this->protocolHex($plan['plan_sha256'] ?? null, 64)
            || ! $this->protocolHex($plan['baseline_sha256'] ?? null, 64) || ! $this->protocolHex($plan['expected_epoch'] ?? null, 32)
            || ! in_array($plan['action'] ?? null, ['update', 'backup', 'restore', 'switch-back'], true)
            || ! $this->validTimestamp($plan['expires_at'] ?? null) || ! is_bool($plan['maintenance_required'] ?? null)
            || ! in_array($plan['continuation'] ?? null, ['remote', 'host_only'], true)
            || ! is_array($plan['actor'] ?? null) || ! $this->protocolHex($plan['actor']['identity_sha256'] ?? null, 64)
            || ! is_int($plan['actor']['admin_id'] ?? null) || $plan['actor']['admin_id'] < 1
            || ! is_string($plan['actor']['management_instance_id'] ?? null)
            || ! Str::isUuid($plan['actor']['management_instance_id'])) {
            throw new RuntimeException('Updater returned an invalid action plan.');
        }

        return $plan;
    }

    private function validateAdmissionReceipt(array $receipt): array
    {
        if (($receipt['schema_version'] ?? null) !== 2 || ($receipt['instance_id'] ?? null) !== $this->instanceId()
            || ! is_string($receipt['client_request_id'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{7,127}\z/', $receipt['client_request_id']) !== 1
            || ! is_string($receipt['operation_id'] ?? null)
            || preg_match('/\A[0-9]{8}T[0-9]{6}\.[0-9]{9}Z-[a-f0-9]{16}\z/', $receipt['operation_id']) !== 1
            || ! $this->protocolHex($receipt['business_sha256'] ?? null, 64) || ! $this->protocolHex($receipt['plan_id'] ?? null, 32)
            || ! $this->protocolHex($receipt['accepted_epoch'] ?? null, 32)
            || ! in_array($receipt['action'] ?? null, ['update', 'backup', 'restore', 'switch-back'], true)
            || ! in_array($receipt['admission_status'] ?? null, ['accepted', 'pending'], true)
            || ! in_array($receipt['background_status'] ?? null, ['ready', 'held'], true)
            || ! array_key_exists('operation', $receipt)) {
            throw new RuntimeException('Updater returned an invalid admission receipt.');
        }
        if ($receipt['operation'] !== null) {
            if (! is_array($receipt['operation'])) {
                throw new RuntimeException('Updater operation receipt is invalid.');
            }
            $operation = $this->validateOperation($receipt['operation'], $receipt['action'] === 'restore' ? 'rollback' : $receipt['action']);
            if ($operation['id'] !== $receipt['operation_id']) {
                throw new RuntimeException('Updater admission and operation identities differ.');
            }
        }

        return $receipt;
    }

    private function coordinatedRequest(string $method, string $endpoint, ?array $payload = null, #[\SensitiveParameter] ?string $authorizationCode = null): array
    {
        [$status, $decoded, $retryAfter] = $this->instanceRequest($method, $endpoint, $payload, $authorizationCode, 'v2');
        if (! in_array($status, [200, 201, 202], true)) {
            $code = is_string($decoded['error'] ?? null) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $decoded['error']) === 1
                ? $decoded['error'] : 'updater_rejected';
            throw new AgentProtocolException($status, $code, $retryAfter);
        }

        return $decoded;
    }

    private function protocolHex(mixed $value, int $length): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{'.$length.'}\z/', $value) === 1;
    }
}
