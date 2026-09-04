<?php

namespace Tests\Unit;

use App\Services\SystemUpdater\UnixSocketAgentClient;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UnixSocketAgentClientTest extends TestCase
{
    public function test_rejected_rollback_keeps_diagnostic_context_without_logging_the_authorization_code(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        $authorizationCode = '604827';

        try {
            $this->assertStringContainsString($authorizationCode, $this->formatException(new RuntimeException($authorizationCode)));
            try {
                $this->withAgentResponse(
                    ['error' => 'rollback_target_not_allowed'],
                    fn (UnixSocketAgentClient $client): array => $client->startRollback('20260827T120000Z-1234abcd', $authorizationCode),
                    409,
                );
                $this->fail('The rejected rollback must throw an exception.');
            } catch (RuntimeException $exception) {
                $log = $this->formatException($exception);
                $this->assertStringContainsString('rollback_target_not_allowed', $log);
                $this->assertStringContainsString('UnixSocketAgentClient->startRollback(', $log);
                $this->assertStringNotContainsString($authorizationCode, $log);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    private function formatException(\Throwable $exception): string
    {
        return (new LineFormatter(null, 'Y-m-d H:i:s', true, true, true))->format(new LogRecord(
            new \DateTimeImmutable,
            'updater-test',
            Level::Error,
            $exception->getMessage(),
            ['exception' => $exception],
        ));
    }

    #[DataProvider('mutationFailureCases')]
    public function test_mutation_failures_redact_credentials_even_when_exception_arguments_are_enabled(string $method, string $failure): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        $previousLength = ini_get('zend.exception_string_param_max_len');
        ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '128');
        $authorizationCode = $failure === 'validation' ? '604827bad' : '604827';
        $controlToken = str_repeat('c', 43);
        $expectedReason = match ($failure) {
            'validation' => 'authorization code is invalid',
            'transport' => 'invalid HTTP response',
            default => 'mutation_rejected',
        };

        try {
            $positive = $this->formatException(new RuntimeException($authorizationCode.' '.$controlToken));
            $this->assertStringContainsString($authorizationCode, $positive);
            $this->assertStringContainsString($controlToken, $positive);
            $invoke = fn (UnixSocketAgentClient $client): array => $method === 'startRollback'
                ? $client->startRollback('20260827T120000Z-1234abcd', $authorizationCode)
                : $client->{$method}($authorizationCode);
            try {
                if ($failure === 'validation') {
                    $invoke(new UnixSocketAgentClient);
                } else {
                    $this->withAgentResponse(['error' => 'mutation_rejected'], $invoke, $failure === 'transport' ? 0 : 409);
                }
                $this->fail('The failed mutation must throw an exception.');
            } catch (RuntimeException $exception) {
                $log = $this->formatException($exception);
                $this->assertStringContainsString($expectedReason, $log);
                $this->assertStringContainsString('UnixSocketAgentClient->'.$method.'(', $log);
                $this->assertStringNotContainsString($authorizationCode, $log);
                $this->assertStringNotContainsString($controlToken, $log);
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
            ini_set('zend.exception_string_param_max_len', (string) $previousLength);
        }
    }

    public static function mutationFailureCases(): array
    {
        $cases = [];
        foreach (['startUpdate', 'startBackup', 'startRollback'] as $method) {
            foreach (['rejected', 'transport', 'validation'] as $failure) {
                $cases[$method.' '.$failure] = [$method, $failure];
            }
        }

        return $cases;
    }

    public function test_it_reads_authenticated_status_from_the_local_unix_socket(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-updater-client-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $socketPath = $directory.'/agent.sock';
        $tokenPath = $directory.'/control.token';
        $token = str_repeat('a', 43);
        file_put_contents($tokenPath, $token."\n");
        chmod($tokenPath, 0640);
        $server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($server, $errorMessage);
        $pid = pcntl_fork();

        if ($pid === 0) {
            $connection = stream_socket_accept($server, 5);
            if (is_resource($connection)) {
                $request = '';
                while (! str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($connection, 4096);
                    if (! is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                $authorized = str_contains($request, 'Authorization: Bearer '.$token."\r\n");
                $body = json_encode([
                    'schema_version' => 1,
                    'status' => $authorized ? 'pass' : 'fail',
                    'updater_version' => '0.1.0',
                    'checks' => [],
                ], JSON_THROW_ON_ERROR);
                fwrite($connection, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        try {
            config([
                'geoflow.updater_socket' => $socketPath,
                'geoflow.updater_control_token_file' => $tokenPath,
                'geoflow.updater_instance_id' => 'primary',
            ]);

            $status = (new UnixSocketAgentClient)->status();

            $this->assertSame('pass', $status['status']);
            $this->assertSame('0.1.0', $status['updater_version']);
        } finally {
            fclose($server);
            pcntl_waitpid($pid, $waitStatus);
            @unlink($socketPath);
            @unlink($tokenPath);
            @rmdir($directory);
        }
    }

    public function test_it_rejects_a_control_token_with_header_injection_characters(): void
    {
        $tokenPath = tempnam(sys_get_temp_dir(), 'geoflow-updater-token-');
        file_put_contents($tokenPath, str_repeat('a', 43)."\r\nX-Injection: yes");
        chmod($tokenPath, 0640);
        config([
            'geoflow.updater_socket' => '/run/geoflow-updater/missing.sock',
            'geoflow.updater_control_token_file' => $tokenPath,
            'geoflow.updater_instance_id' => 'primary',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credential is invalid');

        try {
            (new UnixSocketAgentClient)->status();
        } finally {
            @unlink($tokenPath);
        }
    }

    public function test_it_uses_only_the_typed_operation_endpoints(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-updater-client-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $socketPath = $directory.'/agent.sock';
        $tokenPath = $directory.'/control.token';
        $token = str_repeat('b', 43);
        file_put_contents($tokenPath, $token."\n");
        chmod($tokenPath, 0640);
        $server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($server, $errorMessage);
        $pid = pcntl_fork();

        if ($pid === 0) {
            for ($index = 0; $index < 4; $index++) {
                $connection = stream_socket_accept($server, 5);
                if (! is_resource($connection)) {
                    exit(1);
                }
                $request = '';
                while (! str_contains($request, "\r\n\r\n")) {
                    $chunk = fread($connection, 4096);
                    if (! is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $request .= $chunk;
                }
                [$headers, $requestBody] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
                preg_match('/\r\nContent-Length: (\d+)\r\n/i', "\r\n".$headers."\r\n", $lengthMatch);
                $expectedLength = (int) ($lengthMatch[1] ?? 0);
                while (strlen($requestBody) < $expectedLength) {
                    $chunk = fread($connection, $expectedLength - strlen($requestBody));
                    if (! is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $requestBody .= $chunk;
                }
                $firstLine = strtok($headers, "\r\n");
                $updateAuthorized = str_contains($headers, "X-GEOFlow-Updater-Authorization: 123456\r\n");
                $rollbackAuthorized = str_contains($headers, "X-GEOFlow-Updater-Authorization: 234567\r\n");
                $operation = [
                    'schema_version' => 1,
                    'id' => '20260827T123456.000000000Z-0011223344556677',
                    'instance_id' => 'primary',
                    'kind' => 'update',
                    'status' => 'queued',
                    'stages' => [],
                    'started_at' => '2026-08-27T12:34:56Z',
                ];
                $status = '200 OK';
                if ($firstLine === 'POST /v1/instances/primary/updates HTTP/1.0' && $updateAuthorized) {
                    $status = '202 Accepted';
                } elseif ($firstLine === 'POST /v1/instances/primary/rollbacks HTTP/1.0'
                    && $requestBody === '{"recovery_point_id":"20260827T120000Z-1234abcd"}'
                    && $rollbackAuthorized) {
                    $status = '202 Accepted';
                    $operation['kind'] = 'rollback';
                } elseif ($firstLine === 'GET /v1/instances/primary/operations/current HTTP/1.0') {
                    $operation['status'] = 'running';
                } elseif ($firstLine === 'GET /v1/instances/primary/backups HTTP/1.0') {
                    $operation = [
                        'schema_version' => 1,
                        'recovery_points' => [[
                            'schema_version' => 1,
                            'id' => '20260827T120000Z-1234abcd',
                            'instance_id' => 'primary',
                            'reason' => 'manual-backup',
                            'created_at' => '2026-08-27T12:00:00Z',
                            'version' => '2.4.0',
                            'release_sequence' => 17,
                        ]],
                    ];
                } else {
                    $status = '400 Bad Request';
                    $operation = ['error' => 'invalid_test_request'];
                }
                $body = json_encode($operation, JSON_THROW_ON_ERROR);
                fwrite($connection, "HTTP/1.0 {$status}\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        try {
            config([
                'geoflow.updater_socket' => $socketPath,
                'geoflow.updater_control_token_file' => $tokenPath,
                'geoflow.updater_instance_id' => 'primary',
            ]);
            $client = new UnixSocketAgentClient;

            $this->assertSame('update', $client->startUpdate('123456')['kind']);
            $this->assertSame('rollback', $client->startRollback('20260827T120000Z-1234abcd', '234567')['kind']);
            $this->assertSame('running', $client->currentOperation()['status']);
            $this->assertSame('20260827T120000Z-1234abcd', $client->recoveryPoints()[0]['id']);
        } finally {
            fclose($server);
            pcntl_waitpid($pid, $waitStatus);
            @unlink($socketPath);
            @unlink($tokenPath);
            @rmdir($directory);
        }
    }

    public function test_it_rejects_an_invalid_recovery_point_before_connecting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recovery point identifier is invalid');

        (new UnixSocketAgentClient)->startRollback('../unsafe', '123456');
    }

    public function test_it_rejects_an_invalid_mutation_authorization_code_before_connecting(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authorization code is invalid');

        (new UnixSocketAgentClient)->startUpdate("123456\r\nX-Injection: yes");
    }

    public function test_it_rejects_non_scalar_status_fields_at_the_socket_boundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported status response');

        $this->withAgentResponse([
            'schema_version' => 1,
            'status' => 'pass',
            'updater_version' => ['unexpected'],
            'checks' => [],
        ], fn (UnixSocketAgentClient $client): array => $client->status());
    }

    public function test_it_rejects_non_scalar_recovery_point_fields_at_the_socket_boundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid recovery point');

        $this->withAgentResponse([
            'schema_version' => 1,
            'recovery_points' => [[
                'schema_version' => 1,
                'id' => '20260827T120000Z-1234abcd',
                'instance_id' => 'primary',
                'reason' => ['unexpected'],
                'created_at' => '2026-08-27T12:00:00Z',
                'version' => '2.4.0',
                'release_sequence' => 17,
            ]],
        ], fn (UnixSocketAgentClient $client): array => $client->recoveryPoints());
    }

    public function test_it_rejects_non_scalar_operation_fields_at_the_socket_boundary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid operation response');

        $this->withAgentResponse([
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => 'update',
            'status' => 'failed',
            'stages' => [],
            'error' => ['unexpected'],
            'started_at' => '2026-08-27T12:34:56Z',
            'completed_at' => '2026-08-27T12:35:56Z',
        ], fn (UnixSocketAgentClient $client): ?array => $client->currentOperation());
    }

    public function test_it_accepts_the_durable_recovery_required_operation_status(): void
    {
        $operation = $this->withAgentResponse([
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => 'rollback',
            'status' => 'recovery_required',
            'current_stage' => 'rollback',
            'stages' => [[
                'name' => 'rollback',
                'status' => 'failed',
                'message' => 'restore will be retried',
                'updated_at' => '2026-08-27T12:35:00Z',
            ]],
            'recovery_point_id' => '20260827T120000Z-1234abcd',
            'started_at' => '2026-08-27T12:34:56Z',
            'completed_at' => '2026-08-27T12:35:56Z',
        ], fn (UnixSocketAgentClient $client): ?array => $client->currentOperation());

        $this->assertSame('recovery_required', $operation['status']);
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(UnixSocketAgentClient): T  $callback
     * @return T
     */
    private function withAgentResponse(array $payload, callable $callback, int $httpStatus = 200): mixed
    {
        $directory = sys_get_temp_dir().'/geoflow-updater-client-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $socketPath = $directory.'/agent.sock';
        $tokenPath = $directory.'/control.token';
        $token = str_repeat('c', 43);
        file_put_contents($tokenPath, $token."\n");
        chmod($tokenPath, 0640);
        $server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($server, $errorMessage);
        $pid = pcntl_fork();

        if ($pid === 0) {
            $connection = stream_socket_accept($server, 5);
            if (is_resource($connection)) {
                while (! feof($connection)) {
                    $chunk = fread($connection, 4096);
                    if (! is_string($chunk) || $chunk === '' || str_contains($chunk, "\r\n\r\n")) {
                        break;
                    }
                }
                $body = json_encode($payload, JSON_THROW_ON_ERROR);
                fwrite($connection, "HTTP/1.0 {$httpStatus} Response\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                fclose($connection);
            }
            fclose($server);
            exit(0);
        }

        try {
            config([
                'geoflow.updater_socket' => $socketPath,
                'geoflow.updater_control_token_file' => $tokenPath,
                'geoflow.updater_instance_id' => 'primary',
            ]);

            return $callback(new UnixSocketAgentClient);
        } finally {
            fclose($server);
            pcntl_waitpid($pid, $waitStatus);
            @unlink($socketPath);
            @unlink($tokenPath);
            @rmdir($directory);
        }
    }
}
