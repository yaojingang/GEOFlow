<?php

namespace App\Services\SystemUpdater;

use App\Contracts\SystemUpdater\AgentClient;
use JsonException;
use RuntimeException;

class UnixSocketAgentClient implements AgentClient
{
    private const MAX_RESPONSE_BYTES = 1024 * 1024;

    /** @return array<string, mixed> */
    public function status(): array
    {
        [$status, $decoded] = $this->instanceRequest('GET', 'status');
        $this->requireStatus($status, [200], $decoded, 'status');
        if ((int) ($decoded['schema_version'] ?? 0) !== 1
            || ! in_array($decoded['status'] ?? null, ['pass', 'warn', 'fail'], true)
            || ! $this->boundedString($decoded['updater_version'] ?? null, 100)
            || ! $this->validStatusInstance($decoded['instance'] ?? null)
            || ! $this->validChecks($decoded['checks'] ?? null)) {
            throw new RuntimeException('Updater returned an unsupported status response.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    public function startUpdate(#[\SensitiveParameter] string $authorizationCode): array
    {
        return $this->startOperation('updates', 'update', $authorizationCode);
    }

    /** @return array<string, mixed> */
    public function startBackup(#[\SensitiveParameter] string $authorizationCode): array
    {
        return $this->startOperation('backups', 'backup', $authorizationCode);
    }

    /** @return array<string, mixed> */
    public function startRollback(string $recoveryPointId, #[\SensitiveParameter] string $authorizationCode): array
    {
        if (preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/', $recoveryPointId) !== 1) {
            throw new RuntimeException('Updater recovery point identifier is invalid.');
        }
        $this->validateAuthorizationCode($authorizationCode);
        [$status, $decoded] = $this->instanceRequest('POST', 'rollbacks', [
            'recovery_point_id' => $recoveryPointId,
        ], $authorizationCode);
        $this->requireStatus($status, [202], $decoded, 'rollback');

        return $this->validateOperation($decoded, 'rollback');
    }

    /** @return array<string, mixed> */
    public function startVerify(): array
    {
        return $this->startOperation('verify', 'verify');
    }

    /** @return array<string, mixed>|null */
    public function currentOperation(): ?array
    {
        [$status, $decoded] = $this->instanceRequest('GET', 'operations/current');
        if ($status === 404 && ($decoded['error'] ?? null) === 'operation_not_found') {
            return null;
        }
        $this->requireStatus($status, [200], $decoded, 'current operation');

        return $this->validateOperation($decoded);
    }

    /** @return list<array<string, mixed>> */
    public function recoveryPoints(): array
    {
        [$status, $decoded] = $this->instanceRequest('GET', 'backups');
        $this->requireStatus($status, [200], $decoded, 'recovery points');
        if ((int) ($decoded['schema_version'] ?? 0) !== 1 || ! is_array($decoded['recovery_points'] ?? null)) {
            throw new RuntimeException('Updater returned an unsupported recovery point response.');
        }
        $points = array_values($decoded['recovery_points']);
        if (count($points) > 100) {
            throw new RuntimeException('Updater returned too many recovery points.');
        }
        $instanceId = $this->instanceId();
        foreach ($points as $point) {
            if (! is_array($point)
                || (int) ($point['schema_version'] ?? 0) !== 1
                || preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/', (string) ($point['id'] ?? '')) !== 1
                || ($point['instance_id'] ?? null) !== $instanceId
                || ! $this->boundedString($point['reason'] ?? null, 255, true)
                || ! $this->validTimestamp($point['created_at'] ?? null)
                || ! $this->boundedString($point['version'] ?? null, 100)
                || ! is_int($point['release_sequence'] ?? null)
                || $point['release_sequence'] < 1) {
                throw new RuntimeException('Updater returned an invalid recovery point.');
            }
        }

        /** @var list<array<string, mixed>> $points */
        return $points;
    }

    /** @return array<string, mixed> */
    private function startOperation(string $endpoint, string $kind, #[\SensitiveParameter] ?string $authorizationCode = null): array
    {
        if ($authorizationCode !== null) {
            $this->validateAuthorizationCode($authorizationCode);
        }
        [$status, $decoded] = $this->instanceRequest('POST', $endpoint, null, $authorizationCode);
        $this->requireStatus($status, [202], $decoded, $kind);

        return $this->validateOperation($decoded, $kind);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function validateOperation(array $operation, ?string $expectedKind = null): array
    {
        $kind = $operation['kind'] ?? null;
        $status = $operation['status'] ?? null;
        $stages = $operation['stages'] ?? null;
        $allowedStages = ['resolve', 'preflight', 'pull', 'quiesce', 'backup', 'migrate', 'activate', 'resume', 'verify', 'rollback', 'rolled_back', 'succeeded', 'reconciled'];
        if ((int) ($operation['schema_version'] ?? 0) !== 1
            || preg_match('/\A[0-9]{8}T[0-9]{6}\.[0-9]{9}Z-[a-f0-9]{16}\z/', (string) ($operation['id'] ?? '')) !== 1
            || ($operation['instance_id'] ?? null) !== $this->instanceId()
            || ! in_array($kind, ['update', 'backup', 'rollback', 'verify'], true)
            || ($expectedKind !== null && $kind !== $expectedKind)
            || ! in_array($status, ['queued', 'running', 'succeeded', 'failed', 'rolled_back', 'recovery_required'], true)
            || ! is_array($stages)
            || count($stages) > 100
            || ! $this->validTimestamp($operation['started_at'] ?? null)
            || (array_key_exists('completed_at', $operation) && $operation['completed_at'] !== null && ! $this->validTimestamp($operation['completed_at']))
            || (array_key_exists('target_version', $operation) && ! $this->boundedString($operation['target_version'], 100))
            || (array_key_exists('recovery_point_id', $operation) && preg_match('/\A[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\z/', is_string($operation['recovery_point_id']) ? $operation['recovery_point_id'] : '') !== 1)
            || (array_key_exists('error', $operation) && ! $this->boundedString($operation['error'], 4096, true))) {
            throw new RuntimeException('Updater returned an invalid operation response.');
        }
        if (isset($operation['current_stage']) && ! in_array($operation['current_stage'], $allowedStages, true)) {
            throw new RuntimeException('Updater returned an invalid operation stage.');
        }
        foreach ($stages as $stage) {
            if (! is_array($stage)
                || ! in_array($stage['name'] ?? null, $allowedStages, true)
                || ! in_array($stage['status'] ?? null, ['running', 'succeeded', 'failed'], true)
                || ! $this->validTimestamp($stage['updated_at'] ?? null)
                || (array_key_exists('message', $stage) && ! $this->boundedString($stage['message'], 4096, true))) {
                throw new RuntimeException('Updater returned an invalid operation stage.');
            }
        }

        return $operation;
    }

    private function validStatusInstance(mixed $instance): bool
    {
        if ($instance === null) {
            return true;
        }
        if (! is_array($instance)
            || ($instance['id'] ?? null) !== $this->instanceId()
            || ! $this->boundedString($instance['version'] ?? null, 100)
            || ! is_int($instance['release_sequence'] ?? null)
            || $instance['release_sequence'] < 1) {
            return false;
        }

        return true;
    }

    private function validChecks(mixed $checks): bool
    {
        if (! is_array($checks) || count($checks) > 100) {
            return false;
        }
        foreach ($checks as $check) {
            if (! is_array($check)
                || ! $this->boundedString($check['id'] ?? null, 100)
                || ! in_array($check['status'] ?? null, ['pass', 'warn', 'fail'], true)
                || ! $this->boundedString($check['message'] ?? null, 4096, true)) {
                return false;
            }
        }

        return true;
    }

    private function boundedString(mixed $value, int $maximum, bool $allowEmpty = false): bool
    {
        return is_string($value)
            && ($allowEmpty || $value !== '')
            && mb_strlen($value, '8bit') <= $maximum;
    }

    private function validTimestamp(mixed $value): bool
    {
        return is_string($value)
            && mb_strlen($value, '8bit') <= 40
            && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?Z\z/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function instanceRequest(string $method, string $endpoint, ?array $payload = null, #[\SensitiveParameter] ?string $authorizationCode = null): array
    {
        $socketPath = (string) config('geoflow.updater_socket');
        $tokenPath = (string) config('geoflow.updater_control_token_file');
        if (! str_starts_with($socketPath, '/') || ! str_starts_with($tokenPath, '/')) {
            throw new RuntimeException('Updater local connection paths are invalid.');
        }
        $token = $this->readControlToken($tokenPath);
        $body = '';
        if ($payload !== null) {
            try {
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException('Updater request could not be encoded.', 0, $exception);
            }
        }
        [$status, $response] = $this->request(
            $socketPath,
            $method,
            '/v1/instances/'.$this->instanceId().'/'.$endpoint,
            $token,
            $body,
            $authorizationCode,
        );
        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Updater returned invalid JSON.', 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Updater returned an invalid response.');
        }

        return [$status, $decoded];
    }

    private function instanceId(): string
    {
        $instanceId = (string) config('geoflow.updater_instance_id', 'primary');
        if (preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $instanceId) !== 1) {
            throw new RuntimeException('Updater instance identifier is invalid.');
        }

        return $instanceId;
    }

    private function readControlToken(string $path): string
    {
        $stat = @lstat($path);
        if (! is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0027) !== 0) {
            throw new RuntimeException('Updater control credential is unavailable.');
        }
        $token = trim((string) @file_get_contents($path));
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) !== 1) {
            throw new RuntimeException('Updater control credential is invalid.');
        }

        return $token;
    }

    /** @return array{0: int, 1: string} */
    private function request(string $socketPath, string $method, string $path, #[\SensitiveParameter] string $token, string $body, #[\SensitiveParameter] ?string $authorizationCode): array
    {
        $socketInfo = @lstat($socketPath);
        if (! is_array($socketInfo) || (($socketInfo['mode'] ?? 0) & 0170000) !== 0140000) {
            throw new RuntimeException('Updater agent is not reachable.');
        }
        $connectTimeout = max(0.1, (float) config('geoflow.updater_connect_timeout_seconds', 0.5));
        $readTimeout = max(1, (int) config('geoflow.updater_read_timeout_seconds', 10));
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            'unix://'.$socketPath,
            $errorCode,
            $errorMessage,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
        );
        if (! is_resource($socket)) {
            throw new RuntimeException('Updater agent is not reachable.');
        }

        try {
            stream_set_timeout($socket, $readTimeout);
            $authorizationHeader = $authorizationCode === null
                ? ''
                : "X-GEOFlow-Updater-Authorization: {$authorizationCode}\r\n";
            $request = "{$method} {$path} HTTP/1.0\r\n"
                ."Host: geoflow-updater\r\n"
                ."Authorization: Bearer {$token}\r\n"
                .$authorizationHeader
                ."Accept: application/json\r\n"
                ."Content-Type: application/json\r\n"
                .'Content-Length: '.mb_strlen($body, '8bit')."\r\n"
                ."Connection: close\r\n\r\n"
                .$body;
            $remaining = $request;
            while ($remaining !== '') {
                $written = fwrite($socket, $remaining);
                if (! is_int($written) || $written < 1) {
                    throw new RuntimeException('Updater agent request could not be written.');
                }
                $remaining = substr($remaining, $written);
            }

            $rawResponse = '';
            while (! feof($socket)) {
                $chunk = fread($socket, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Updater agent response could not be read.');
                }
                $rawResponse .= $chunk;
                if (mb_strlen($rawResponse, '8bit') > self::MAX_RESPONSE_BYTES) {
                    throw new RuntimeException('Updater agent response exceeded the size limit.');
                }
                $metadata = stream_get_meta_data($socket);
                if (($metadata['timed_out'] ?? false) === true) {
                    throw new RuntimeException('Updater agent response timed out.');
                }
            }
        } finally {
            fclose($socket);
        }

        [$headers, $responseBody] = array_pad(explode("\r\n\r\n", $rawResponse, 2), 2, '');
        $statusLine = strtok($headers, "\r\n");
        if (! is_string($statusLine) || preg_match('/\AHTTP\/1\.[01] ([0-9]{3})(?: |\z)/', $statusLine, $matches) !== 1) {
            throw new RuntimeException('Updater agent returned an invalid HTTP response.');
        }
        if ($responseBody === '') {
            throw new RuntimeException('Updater agent returned an empty response.');
        }

        return [(int) $matches[1], $responseBody];
    }

    private function validateAuthorizationCode(#[\SensitiveParameter] string $authorizationCode): void
    {
        if (preg_match('/\A[0-9]{6}\z/', $authorizationCode) !== 1) {
            throw new RuntimeException('Updater mutation authorization code is invalid.');
        }
    }

    /**
     * @param  list<int>  $expected
     * @param  array<string, mixed>  $payload
     */
    private function requireStatus(int $status, array $expected, array $payload, string $operation): void
    {
        if (in_array($status, $expected, true)) {
            return;
        }
        $code = is_string($payload['error'] ?? null)
            && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $payload['error']) === 1
                ? $payload['error']
                : 'unknown_error';
        throw new RuntimeException("Updater agent rejected the {$operation} request ({$code}).");
    }
}
