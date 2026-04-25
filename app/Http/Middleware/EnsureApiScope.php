<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Services\Api\ApiTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiScope
{
    public function __construct(
        private ApiTokenService $tokenService
    ) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $context = $request->attributes->get('api_auth');
        if (! $context instanceof ApiAuthContext) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        if (! $this->tokenService->tokenHasScope($context->token, $scope)) {
            throw new ApiException('forbidden', '当前 Token 没有访问此接口的权限', 403, [
                'required_scope' => $scope,
            ]);
        }

        return $next($request);
    }
}
