<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\Admin;
use Illuminate\Support\Facades\DB;

class ApiAdminAuthService
{
    public function __construct(
        private ApiTokenService $tokenService
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

        $admin = Admin::query()->where('username', $username)->first();
        if (! $admin || ($admin->status ?? 'active') !== 'active' || ! password_verify($password, (string) $admin->password)) {
            throw new ApiException('invalid_credentials', '用户名或密码错误，或账号已被停用', 401);
        }

        $tokenResult = DB::transaction(function () use ($admin, $username) {
            $admin->forceFill(['last_login' => now()])->save();

            return $this->tokenService->createToken(
                'CLI Login '.$username.' '.date('Y-m-d H:i:s'),
                $this->tokenService->getAvailableScopes(),
                (int) $admin->id
            );
        });

        return [
            'token' => $tokenResult['token'],
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
