<?php

namespace Tests\Unit;

use App\Services\SystemUpdater\UnixSocketAgentClient;
use RuntimeException;
use Tests\TestCase;

class UnixSocketAgentClientTest extends TestCase
{
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
                if ($firstLine === 'POST /v1/instances/primary/updates HTTP/1.0') {
                    $status = '202 Accepted';
                } elseif ($firstLine === 'POST /v1/instances/primary/rollbacks HTTP/1.0'
                    && $requestBody === '{"recovery_point_id":"20260827T120000Z-1234abcd"}') {
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

            $this->assertSame('update', $client->startUpdate()['kind']);
            $this->assertSame('rollback', $client->startRollback('20260827T120000Z-1234abcd')['kind']);
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

        (new UnixSocketAgentClient)->startRollback('../unsafe');
    }
}
