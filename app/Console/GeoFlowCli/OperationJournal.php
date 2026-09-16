<?php

namespace App\Console\GeoFlowCli;

final class OperationJournal
{
    /**
     * Persist identity and a non-secret digest before sending the request.
     *
     * @return array{client_request_id: string, repeated: bool, operation_id: ?string, recovery_epoch: ?string}
     */
    public static function prepare(ConfigurationRepository $configuration, array $session, string $name, array $input, ?string $requestId): array
    {
        $requestId ??= bin2hex(random_bytes(16));
        self::validateId($requestId);
        $record = [
            'instance_id' => $session['instance_id'], 'admin_id' => (string) $session['admin']['id'],
            'client_request_id' => $requestId, 'operation' => $name,
            'input_hash' => hash('sha256', json_encode(self::canonicalInput($input), JSON_THROW_ON_ERROR)),
        ];
        $path = self::path($configuration, $session, $requestId, true);
        $existing = $configuration->withLock($path, function (string $path) use ($record, $session): ?array {
            $existing = self::read($path);
            if ($existing !== null) {
                foreach ($record as $key => $value) {
                    if (($existing[$key] ?? null) !== $value) {
                        throw new CliException('该客户端请求 ID 已用于不同输入，请先查询原操作结果');
                    }
                }

                return $existing;
            }
            self::write($path, $record + ['operation_id' => null, 'state' => 'prepared', 'recovery_epoch' => ApiClient::recoveryEpoch($session)]);

            return null;
        });

        return ['client_request_id' => $requestId, 'repeated' => $existing !== null, 'operation_id' => $existing['operation_id'] ?? null, 'recovery_epoch' => $existing !== null ? ($existing['recovery_epoch'] ?? null) : ApiClient::recoveryEpoch($session)];
    }

    public static function isPrepared(ConfigurationRepository $configuration, array $session, string $requestId): bool
    {
        self::validateId($requestId);

        return self::read(self::path($configuration, $session, $requestId, false)) !== null;
    }

    /** Only update an existing local journal; response bodies and credentials are never retained. */
    public static function recordResponse(ConfigurationRepository $configuration, array $session, array $receipt, ?string $expectedRequestId = null): void
    {
        $requestId = $receipt['client_request_id'] ?? null;
        if (! is_string($requestId) || ($expectedRequestId !== null && $expectedRequestId !== $requestId)
            || ! is_string($receipt['operation_id'] ?? null) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $receipt['operation_id']) !== 1
            || ! is_string($receipt['operation'] ?? null) || ! is_string($receipt['state'] ?? null)
            || preg_match('/^[a-z_]{1,40}$/D', $receipt['state']) !== 1) {
            throw new CliException('服务端操作收据无效；请保留客户端请求 ID 并查询原操作，避免重复执行');
        }
        self::validateId($requestId);
        $path = self::path($configuration, $session, $requestId, false);
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        $configuration->withLock($path, function (string $path) use ($receipt, $session, $requestId): void {
            $record = self::read($path);
            if ($record === null) {
                return;
            }
            if (($record['instance_id'] ?? null) !== $session['instance_id']
                || (string) ($record['admin_id'] ?? '') !== (string) $session['admin']['id']
                || ($record['client_request_id'] ?? null) !== $requestId
                || ($record['operation'] ?? null) !== $receipt['operation']
                || (isset($record['operation_id']) && $record['operation_id'] !== $receipt['operation_id'])) {
                throw new CliException('服务端收据与本地操作身份不一致，未覆盖原操作记录');
            }
            self::write($path, array_replace($record, ['operation_id' => $receipt['operation_id'], 'state' => $receipt['state']]));
        });
    }

    private static function validateId(string $id): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $id) !== 1) {
            throw new CliException('客户端请求 ID 格式无效');
        }
    }

    private static function path(ConfigurationRepository $configuration, array $session, string $requestId, bool $create): string
    {
        $directory = dirname($configuration->defaultPath()).'/operations';
        if (is_link($directory) || (file_exists($directory) && ! is_dir($directory))) {
            throw new CliException('操作收据目录无效');
        }
        if ($create && ! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new CliException('无法创建操作收据目录');
        }
        if ($create) {
            self::syncDirectory(dirname($directory));
        }
        if ($create && PHP_OS_FAMILY !== 'Windows' && ! @chmod($directory, 0700)) {
            throw new CliException('无法保护操作收据目录');
        }

        return $directory.'/'.hash('sha256', $session['instance_id'].':'.$session['admin']['id'].':'.$requestId).'.json';
    }

    private static function read(string $path): ?array
    {
        if (is_link($path) || (file_exists($path) && ! is_file($path))) {
            throw new CliException('操作收据必须是普通文件');
        }
        if (! file_exists($path)) {
            return null;
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new CliException('无法读取操作收据');
        }
        try {
            $opened = fstat($stream);
            $current = @lstat($path);
            if ($opened === false || $current === false || ($opened['mode'] & 0170000) !== 0100000
                || ($current['mode'] & 0170000) !== 0100000 || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new CliException('操作收据文件在读取时发生变化');
            }
            $bytes = stream_get_contents($stream, 8193);
            if ($bytes === false || strlen($bytes) > 8192) {
                throw new CliException('操作收据大小无效');
            }
            try {
                $record = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new CliException('操作收据损坏，请保留客户端请求 ID 并查询原操作');
            }
            if (! is_array($record) || ! str_starts_with(ltrim($bytes), '{')) {
                throw new CliException('操作收据格式无效');
            }

            return $record;
        } finally {
            fclose($stream);
        }
    }

    private static function write(string $path, array $record): void
    {
        $temporary = dirname($path).'/.receipt-'.bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'x+b');
        if ($stream === false) {
            throw new CliException('无法写入操作收据');
        }
        try {
            $bytes = json_encode($record, JSON_THROW_ON_ERROR)."\n";
            if ((PHP_OS_FAMILY !== 'Windows' && ! chmod($temporary, 0600))
                || fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream) || ! fsync($stream)) {
                throw new CliException('操作收据未持久化，请保留客户端请求 ID 并查询原操作');
            }
            fclose($stream);
            $stream = null;
            if (! rename($temporary, $path)) {
                throw new CliException('无法原子更新操作收据');
            }
            self::syncDirectory(dirname($path));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function syncDirectory(string $directory): void
    {
        $stream = @fopen($directory, 'r');
        if ($stream === false) {
            throw new CliException('无法持久化操作收据目录，未发送请求');
        }
        try {
            if (! @fsync($stream)) {
                throw new CliException('操作收据目录未持久化，未发送请求');
            }
        } finally {
            fclose($stream);
        }
    }

    private static function canonicalInput(array $input): array
    {
        if (! array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as $key => $value) {
            if (preg_match('/(?:token|password|secret|authorization[_-]?code|api[_-]?key)/i', (string) $key) === 1) {
                $input[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $input[$key] = self::canonicalInput($value);
            }
        }

        return $input;
    }
}
