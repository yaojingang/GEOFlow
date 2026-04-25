<?php

namespace App\Support\GeoFlow;

use App\Models\Admin;
use Illuminate\Support\Facades\Cache;

/**
 * 管理员登录失败锁定服务。
 *
 * 规则：
 * - 同一账号累计失败 5 次后，直接将账号状态置为 locked；
 * - locked 账号需由管理员手动解锁（命令或后台改回 active）；
 * - 登录成功后清空该账号失败计数。
 */
class AdminLoginLockService
{
    private const MAX_FAILED_ATTEMPTS = 5;

    /**
     * 判断账号是否处于锁定状态。
     */
    public function isLocked(Admin $admin): bool
    {
        return (string) ($admin->status ?? '') === 'locked';
    }

    /**
     * 记录一次失败并在达到阈值时锁定账号。
     *
     * @return bool true 表示本次已触发锁定
     */
    public function recordFailedAttemptAndLock(Admin $admin): bool
    {
        $username = trim((string) $admin->username);
        if ($username === '') {
            return false;
        }

        $cacheKey = $this->failedAttemptsCacheKey($username);
        $attempts = (int) Cache::increment($cacheKey);
        if ($attempts <= 1) {
            Cache::forever($cacheKey, 1);
            $attempts = 1;
        }

        if ($attempts < self::MAX_FAILED_ATTEMPTS) {
            return false;
        }

        $admin->forceFill(['status' => 'locked'])->save();
        Cache::forget($cacheKey);

        return true;
    }

    /**
     * 清理账号失败次数（用于成功登录或手动解锁后）。
     */
    public function clearFailedAttempts(string $username): void
    {
        $username = trim($username);
        if ($username === '') {
            return;
        }

        Cache::forget($this->failedAttemptsCacheKey($username));
    }

    /**
     * 生成失败计数缓存键。
     */
    private function failedAttemptsCacheKey(string $username): string
    {
        return 'admin_login_failed_attempts:'.strtolower(trim($username));
    }
}
