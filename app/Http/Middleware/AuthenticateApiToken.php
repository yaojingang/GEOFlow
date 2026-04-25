<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Services\Api\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function __construct(
        private ApiTokenService $tokenService
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $authorization = $request->header('Authorization');
        if (! is_string($authorization) || $authorization === '') {
            throw new ApiException('unauthorized', '缺少 Authorization 头', 401);
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            throw new ApiException('unauthorized', 'Authorization 格式无效', 401);
        }

        $tokenValue = trim($matches[1]);
        if ($tokenValue === '') {
            throw new ApiException('unauthorized', 'Token 不能为空', 401);
        }

        $token = $this->tokenService->getActiveTokenByPlaintext($tokenValue);
        if (! $token) {
            throw new ApiException('unauthorized', 'Token 无效或已过期', 401);
        }

        $this->tokenService->touchToken((int) $token['id']);
        $auditAdminId = $this->tokenService->resolveAuditAdminId(
            isset($token['created_by_admin_id']) ? (int) $token['created_by_admin_id'] : null
        );

        $request->attributes->set('api_auth', new ApiAuthContext($token, $auditAdminId));

        return $next($request);
    }
}
