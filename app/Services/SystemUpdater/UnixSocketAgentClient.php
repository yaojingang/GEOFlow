<?php

namespace App\Services\SystemUpdater;

use App\Contracts\SystemUpdater\AgentClient;
use RuntimeException;

class UnixSocketAgentClient implements AgentClient
{
    private const MAX_RESPONSE_BYTES = 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $socketPath = (string) config('geoflow.updater_socket');
        $tokenPath = (string) config('geoflow.updater_control_token_file');
        $instanceId = (string) config('geoflow.updater_instance_id', 'primary');

        if (! str_starts_with($socketPath, '/') || ! str_starts_with($tokenPath, '/')) {
            throw new RuntimeException('Updater local connection paths are invalid.');
        }
        if (! preg_match('/\A[a-z][a-z0-9-]{0,62}\z/', $instanceId)) {
            throw new RuntimeException('Updater instance identifier is invalid.');
        }

        $token = $this->readControlToken($tokenPath);
        $response = $this->request(
            $socketPath,
            '/v1/instances/'.$instanceId.'/status',
            $token,
        );

        $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || (int) ($decoded['schema_version'] ?? 0) !== 1) {
            throw new RuntimeException('Updater returned an unsupported status response.');
        }

        return $decoded;
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

    private function request(string $socketPath, string $path, string $token): string
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
            $request = "GET {$path} HTTP/1.0\r\n"
                ."Host: geoflow-updater\r\n"
                ."Authorization: Bearer {$token}\r\n"
                ."Accept: application/json\r\n"
                ."Connection: close\r\n\r\n";
            if (fwrite($socket, $request) !== mb_strlen($request, '8bit')) {
                throw new RuntimeException('Updater agent request could not be written.');
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

        [$headers, $body] = array_pad(explode("\r\n\r\n", $rawResponse, 2), 2, '');
        $statusLine = strtok($headers, "\r\n");
        if (! is_string($statusLine) || ! preg_match('/\AHTTP\/1\.[01] 200(?: |\z)/', $statusLine)) {
            throw new RuntimeException('Updater agent rejected the status request.');
        }
        if ($body === '') {
            throw new RuntimeException('Updater agent returned an empty response.');
        }

        return $body;
    }
}
