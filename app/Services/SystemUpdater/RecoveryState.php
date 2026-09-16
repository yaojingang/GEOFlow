<?php

namespace App\Services\SystemUpdater;

use App\Exceptions\ApiException;
use Closure;

final class RecoveryState
{
    public const CONTAINER_DIRECTORY = '/run/geoflow-recovery-control';

    public const SESSION_KEY = 'admin_recovery_epoch';

    public function __construct(
        private readonly string $directory,
        private readonly string $instanceId,
        private readonly bool $required,
        private readonly ?Closure $readyGate = null,
    ) {}

    /** Read the mounted directory on every boundary; atomic replacement must remain visible. */
    public function snapshot(): ?array
    {
        if (! $this->required) {
            return null;
        }
        $path = $this->directory.'/state.json';
        clearstatcache(true, $path);
        if (is_link($this->directory) || is_link($path) || ! is_file($path)) {
            throw $this->unavailable();
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw $this->unavailable();
        }
        try {
            $opened = fstat($stream);
            $current = @lstat($path);
            if ($opened === false || $current === false || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw $this->unavailable();
            }
            $bytes = stream_get_contents($stream, 8193);
        } finally {
            fclose($stream);
        }
        if (! is_string($bytes) || strlen($bytes) > 8192) {
            throw $this->unavailable();
        }
        try {
            $state = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $this->unavailable();
        }
        if (! is_array($state) || ($state['schema_version'] ?? null) !== 1
            || ($state['instance_id'] ?? null) !== $this->instanceId
            || ! $this->validIdentity($state['host_id'] ?? null) || ! $this->validIdentity($state['epoch'] ?? null)
            || ! in_array($state['phase'] ?? null, ['ready', 'restoring', 'validating', 'http_ready'], true)
            || ! array_key_exists('transaction_id', $state)
            || ! (($state['phase'] === 'ready' && $state['transaction_id'] === null)
                || (is_string($state['transaction_id']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $state['transaction_id']) === 1))
            || ($state['minimum_updater_protocol'] ?? null) !== 5) {
            throw $this->unavailable();
        }

        return $state;
    }

    public function assertHttpReady(): ?array
    {
        $state = $this->snapshot();
        if ($state !== null && ! in_array($state['phase'], ['ready', 'http_ready'], true)) {
            throw new ApiException('recovery_in_progress', '实例恢复仍在校验，暂未开放认证与写入', 503);
        }

        if ($state !== null && $state['phase'] === 'ready') {
            ($this->readyGate)?->__invoke($state);
        }

        return $state;
    }

    public function assertWriteEpoch(?string $expected): void
    {
        $state = $this->assertHttpReady();
        if ($state !== null && ($expected === null || ! hash_equals($state['epoch'], $expected))) {
            throw new ApiException('recovery_epoch_conflict', '实例恢复代次已变化或请求未提供代次；请重新登录并核对原操作结果', 409);
        }
    }

    public function assertBackgroundReady(): void
    {
        $state = $this->snapshot();
        if ($state !== null && $state['phase'] !== 'ready') {
            throw new ApiException('recovery_background_held', '恢复后的后台任务仍需对账，暂未恢复执行', 503);
        }
        if ($state !== null) {
            ($this->readyGate)?->__invoke($state);
        }
    }

    private function validIdentity(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/D', $value) === 1;
    }

    private function unavailable(): ApiException
    {
        return new ApiException('recovery_state_unavailable', '宿主机恢复状态不可用，请通过宿主机核验；未开放写入', 503);
    }
}
