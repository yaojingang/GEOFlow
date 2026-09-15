<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\ApiAdminAuthService;
use App\Services\Api\ManagementInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 认证：管理员账号登录并签发 API Token。
 *
 * 无需 Bearer；成功返回 token、过期时间与 admin 摘要，与遗留 ApiAdminAuthService 行为对齐。
 */
class AuthController extends BaseApiController
{
    /**
     * 使用用户名密码登录，签发默认或明确请求的 scope，并更新管理员 last_login。
     *
     * 请求体：username、password（JSON）。错误时抛出/映射为 401 或 422。
     */
    public function login(Request $request, ApiAdminAuthService $adminAuth, ManagementInstance $instance): JsonResponse
    {
        $body = $request->all();
        if (array_key_exists('requested_scopes', $body)) {
            $request->validate(['requested_scopes' => ['required', 'array', 'min:1', 'max:100'], 'requested_scopes.*' => ['required', 'string', 'max:100', 'distinct']]);
        }

        $data = $adminAuth->login(
            trim((string) ($body['username'] ?? '')),
            (string) ($body['password'] ?? ''),
            (string) ($request->ip() ?? ''),
            trim((string) ($request->userAgent() ?? '')),
            $body['requested_scopes'] ?? null,
        );

        return $this->success($request, array_merge($data, $instance->describe()));
    }
}
