<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ApiAdminAuthService
{
    public function __construct(
        private ApiTokenService $tokenService,
        private AdminLoginLockService $loginLockService
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function login(string $username, string $password, string $ipAddress = '', string $userAgent = ''): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            $fieldErrors = [];
            if ($username === '') {
                $fieldErrors['username'] = '用户名不能为空';
            }
            if ($password === '') {
                $fieldErrors['password'] = '密码不能为空';
            }
            throw new ApiException('validation_failed', '用户名和密码不能为空', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }

        $throttleKey = 'api_admin_login:'.sha1(Str::lower($username).'|'.$ipAddress);
        $maxAttempts = max(1, (int) config('geoflow.api_login_rate_limit_attempts', 10));
        $decaySeconds = max(1, (int) config('geoflow.api_login_rate_limit_decay_seconds', 60));

        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            throw new ApiException('too_many_attempts', '登录尝试过于频繁，请稍后再试', 429, [
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ]);
        }

        RateLimiter::hit($throttleKey, $decaySeconds);

        $admin = Admin::query()->where('username', $username)->first();
        if ($admin && $this->loginLockService->isLocked($admin)) {
            throw new ApiException('account_locked', '账号已被锁定，请联系超级管理员处理', 423);
        }

        $status = (string) ($admin?->status ?? 'active');
        $passwordMatches = $admin ? password_verify($password, (string) $admin->password) : false;
        if (! $admin || $status !== 'active' || ! $passwordMatches) {
            if ($admin && $status === 'active' && ! $passwordMatches) {
                $locked = $this->loginLockService->recordFailedAttemptAndLock($admin);
                if ($locked) {
                    throw new ApiException('account_locked', '密码错误次数过多，账号已被锁定', 423);
                }
            }

            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        $tokenResult = DB::transaction(function () use ($admin, $username, $throttleKey) {
            $admin->forceFill(['last_login' => now()])->save();
            $this->loginLockService->clearFailedAttempts($username);
            RateLimiter::clear($throttleKey);

            return $this->tokenService->createToken(
                'CLI Login '.$username.' '.date('Y-m-d H:i:s'),
                $this->tokenService->getAvailableScopes(),
                (int) $admin->id
            );
        });

        return [
            'token' => $tokenResult['token'],
            'scopes' => $tokenResult['record']['scopes'] ?? [],
            'expires_at' => $tokenResult['record']['expires_at'] ?? null,
            'admin' => [
                'id' => (int) $admin->id,
                'username' => $admin->username,
                'display_name' => $admin->display_name ?? '',
                'role' => $admin->role ?? 'admin',
                'status' => $admin->status ?? 'active',
            ],
        ];
    }
}
