<?php

namespace App\Services\BrowserOperations;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class DeviceAuthorizationService
{
    public const EXPIRES_IN = 600;

    public const POLL_INTERVAL = 5;

    public function __construct(private readonly ApiTokenService $tokenService) {}

    /** @return array<string,mixed> */
    public function create(string $clientName): array
    {
        $deviceCode = Str::random(64);
        $userCode = $this->uniqueUserCode();
        $deviceHash = hash('sha256', $deviceCode);
        $expiresAt = now()->addSeconds(self::EXPIRES_IN);
        $record = [
            'device_hash' => $deviceHash,
            'user_code' => $userCode,
            'client_name' => mb_substr(trim($clientName) ?: 'GEOFlow Chrome', 0, 80),
            'status' => 'pending',
            'admin_id' => null,
            'interval' => self::POLL_INTERVAL,
            'last_poll_at' => null,
            'expires_at' => $expiresAt->timestamp,
        ];

        Cache::put($this->deviceKey($deviceHash), $record, $expiresAt);
        Cache::put($this->userKey($userCode), $deviceHash, $expiresAt);

        return [
            'device_code' => $deviceCode,
            'user_code' => $userCode,
            'expires_in' => self::EXPIRES_IN,
            'interval' => self::POLL_INTERVAL,
        ];
    }

    /** @return array<string,mixed>|null */
    public function findByUserCode(string $userCode): ?array
    {
        $normalized = $this->normalizeUserCode($userCode);
        $deviceHash = Cache::get($this->userKey($normalized));
        if (! is_string($deviceHash)) {
            return null;
        }

        $record = Cache::get($this->deviceKey($deviceHash));

        return is_array($record) && ($record['expires_at'] ?? 0) >= now()->timestamp ? $record : null;
    }

    public function decide(string $userCode, Admin $admin, bool $approved): void
    {
        $normalized = $this->normalizeUserCode($userCode);
        $deviceHash = Cache::get($this->userKey($normalized));
        if (! is_string($deviceHash)) {
            throw new ApiException('expired_token', '配对码已失效，请在扩展中重新申请', 400);
        }

        Cache::lock('browser-device-decision:'.$deviceHash, 10)->block(3, function () use ($deviceHash, $admin, $approved): void {
            $record = Cache::get($this->deviceKey($deviceHash));
            if (! is_array($record) || ($record['expires_at'] ?? 0) < now()->timestamp) {
                throw new ApiException('expired_token', '配对码已失效，请在扩展中重新申请', 400);
            }
            if (($record['status'] ?? null) !== 'pending') {
                throw new ApiException('authorization_resolved', '该配对请求已经处理', 409);
            }

            $record['status'] = $approved ? 'approved' : 'denied';
            $record['admin_id'] = $approved ? (int) $admin->getKey() : null;
            Cache::put(
                $this->deviceKey($deviceHash),
                $record,
                now()->setTimestamp((int) $record['expires_at']),
            );
        });
    }

    /** @return array<string,mixed> */
    public function exchange(string $deviceCode, string $clientVersion): array
    {
        $deviceHash = hash('sha256', trim($deviceCode));

        return Cache::lock('browser-device-exchange:'.$deviceHash, 10)->block(3, function () use ($deviceHash, $clientVersion): array {
            $key = $this->deviceKey($deviceHash);
            $record = Cache::get($key);
            if (! is_array($record) || ($record['expires_at'] ?? 0) < now()->timestamp) {
                throw new ApiException('expired_token', '设备授权已过期', 400);
            }

            $interval = max(self::POLL_INTERVAL, (int) ($record['interval'] ?? self::POLL_INTERVAL));
            $lastPollAt = (int) ($record['last_poll_at'] ?? 0);
            if ($lastPollAt > 0 && now()->timestamp - $lastPollAt < $interval) {
                $record['interval'] = min(30, $interval + 5);
                Cache::put($key, $record, now()->setTimestamp((int) $record['expires_at']));
                throw new ApiException('slow_down', '轮询过于频繁', 400, ['interval' => $record['interval']]);
            }

            if (($record['status'] ?? null) === 'pending') {
                $record['last_poll_at'] = now()->timestamp;
                Cache::put($key, $record, now()->setTimestamp((int) $record['expires_at']));
                throw new ApiException('authorization_pending', '等待管理员确认授权', 400, ['interval' => $interval]);
            }
            if (($record['status'] ?? null) === 'denied') {
                $this->forget($record);
                throw new ApiException('access_denied', '管理员拒绝了浏览器连接', 400);
            }

            $adminId = (int) ($record['admin_id'] ?? 0);
            $created = $this->tokenService->createToken(
                'GEOFlow Chrome '.mb_substr($clientVersion, 0, 32).' · '.($record['client_name'] ?? 'Browser'),
                $this->tokenService->getBrowserClientScopes(),
                $adminId,
            );
            $admin = Admin::query()->find($adminId);
            if ($admin instanceof Admin) {
                AdminActivityLogger::log($admin, 'browser_client.paired', [
                    'target_type' => 'personal_access_token',
                    'target_id' => (int) ($created['record']['id'] ?? 0),
                    'details' => [
                        'client_name' => (string) ($record['client_name'] ?? 'Browser'),
                        'extension_version' => mb_substr($clientVersion, 0, 32),
                        'scopes' => $this->tokenService->getBrowserClientScopes(),
                    ],
                ]);
            }
            $this->forget($record);

            return [
                'token' => (string) $created['token'],
                'scopes' => $this->tokenService->getBrowserClientScopes(),
                'expires_at' => $created['record']['expires_at'] ?? null,
                'protocol_version' => 1,
            ];
        });
    }

    /** @param array<string,mixed> $record */
    private function forget(array $record): void
    {
        Cache::forget($this->deviceKey((string) ($record['device_hash'] ?? '')));
        Cache::forget($this->userKey((string) ($record['user_code'] ?? '')));
    }

    private function uniqueUserCode(): string
    {
        do {
            $raw = strtoupper(Str::random(8));
            $code = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        } while (Cache::has($this->userKey($code)));

        return $code;
    }

    private function normalizeUserCode(string $userCode): string
    {
        $raw = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $userCode));

        return strlen($raw) === 8 ? substr($raw, 0, 4).'-'.substr($raw, 4, 4) : $raw;
    }

    private function deviceKey(string $hash): string
    {
        return 'browser-operations:device:'.$hash;
    }

    private function userKey(string $code): string
    {
        return 'browser-operations:user:'.hash('sha256', $code);
    }
}
