<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Services\GeoFlow\AnonymousUsageTelemetry;
use App\Support\GeoFlow\AdminLoginLockService;
use Illuminate\Support\Facades\DB;

class ApiAdminAuthService
{
    public function __construct(
        private ApiTokenService $tokenService,
        private AdminLoginLockService $loginLockService,
        private AnonymousUsageTelemetry $anonymousUsageTelemetry,
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

        if ($this->loginLockService->tooManyAttempts($username, $ipAddress)) {
            throw new ApiException('too_many_attempts', '登录尝试过于频繁，请稍后再试', 429, [
                'retry_after' => $this->loginLockService->availableIn($username, $ipAddress),
            ]);
        }

        $loginResult = DB::transaction(function () use ($username, $password, $ipAddress): array {
            $admin = Admin::query()
                ->where('username', $username)
                ->lockForUpdate()
                ->first();
            if ($admin && $this->loginLockService->isLocked($admin)) {
                return ['error' => 'account_locked'];
            }

            $status = (string) ($admin?->status ?? 'active');
            $passwordMatches = $admin ? password_verify($password, (string) $admin->password) : false;
            if (! $admin || $status !== 'active' || ! $passwordMatches) {
                return ['error' => 'invalid_credentials'];
            }

            $admin->forceFill(['last_login' => now()])->save();
            $this->loginLockService->clearFailedAttempts($username, $ipAddress);

            return [
                'admin' => $admin,
                'token' => $this->tokenService->createToken(
                    'CLI Login '.$username.' '.date('Y-m-d H:i:s'),
                    $this->tokenService->getAvailableScopes(),
                    (int) $admin->id
                ),
            ];
        });
        if (($loginResult['error'] ?? null) === 'account_locked') {
            throw new ApiException('account_locked', '账号已被锁定，请联系超级管理员处理', 423);
        }
        if (($loginResult['error'] ?? null) === 'invalid_credentials') {
            if ($this->loginLockService->recordFailedAttempt($username, $ipAddress)) {
                throw new ApiException('too_many_attempts', '登录尝试过于频繁，请稍后再试', 429, [
                    'retry_after' => $this->loginLockService->availableIn($username, $ipAddress),
                ]);
            }

            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        /** @var Admin $admin */
        $admin = $loginResult['admin'];
        /** @var array<string, mixed> $tokenResult */
        $tokenResult = $loginResult['token'];
        defer(fn () => $this->anonymousUsageTelemetry->reportAdminLogin($admin, 'api'));

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
