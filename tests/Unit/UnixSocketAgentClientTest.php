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
}
