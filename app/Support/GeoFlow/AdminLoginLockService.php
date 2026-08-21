<?php

namespace App\Support\GeoFlow;

use App\Models\Admin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * 管理员登录失败锁定服务。
 *
 * 自动防护按“标准化用户名 + IP”临时限速，不修改管理员业务状态。
 * admins.status=locked 继续表示人工锁定，需显式解锁。
 */
class AdminLoginLockService
{
    /**
     * 判断账号是否处于人工锁定状态。
     */
    public function isLocked(Admin $admin): bool
    {
        return (string) ($admin->status ?? '') === 'locked';
    }

    public function tooManyAttempts(string $username, string $ipAddress): bool
    {
        return RateLimiter::tooManyAttempts(
            $this->attemptKey($username, $ipAddress),
            $this->maxAttempts(),
        );
    }

    /**
     * 记录一次失败。
     *
     * @return bool true 表示本次达到临时锁定阈值
     */
    public function recordFailedAttempt(string $username, string $ipAddress): bool
    {
        RateLimiter::hit(
            $this->attemptKey($username, $ipAddress),
            $this->lockoutSeconds(),
        );

        return $this->tooManyAttempts($username, $ipAddress);
    }

    public function availableIn(string $username, string $ipAddress): int
    {
        return RateLimiter::availableIn($this->attemptKey($username, $ipAddress));
    }

    /**
     * 登录成功后只清理当前用户名和 IP 的失败预算。
     */
    public function clearFailedAttempts(string $username, string $ipAddress = ''): void
    {
        if ($ipAddress === '') {
            return;
        }

        RateLimiter::clear($this->attemptKey($username, $ipAddress));
    }

    private function attemptKey(string $username, string $ipAddress): string
    {
        $identity = Str::lower(trim($username)).'|'.trim($ipAddress);

        return 'admin-login-failure:'.hash('sha256', $identity);
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('geoflow.max_login_attempts', 5));
    }

    private function lockoutSeconds(): int
    {
        return max(1, (int) config('geoflow.login_lockout_seconds', 900));
    }
}
