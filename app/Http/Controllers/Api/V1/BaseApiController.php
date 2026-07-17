<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * API v1 控制器基类。
 *
 * 统一封装：请求 ID（{@see requestId}）、已认证上下文（{@see auth}）、
 * 成功 JSON 信封（{@see success}）。写操作幂等由控制器在 mutation 前调用 IdempotencyService。
 */
abstract class BaseApiController extends Controller
{
    /**
     * 从中间件写入的属性读取请求 ID；若无则生成 UUID。
     */
    protected function requestId(Request $request): string
    {
        return (string) $request->attributes->get('request_id', Str::uuid()->toString());
    }

    /**
     * 读取 Bearer 鉴权后注入的 {@see ApiAuthContext}（需先经过 api.auth 中间件）。
     */
    protected function auth(Request $request): ApiAuthContext
    {
        $context = $request->attributes->get('api_auth');
        if (! $context instanceof ApiAuthContext) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        return $context;
    }

    /**
     * 返回统一成功响应。
     *
     * @param  array<string, mixed>  $data  置于 JSON 的 data 字段
     */
    protected function success(Request $request, array $data, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, $this->requestId($request), $status);
    }
}
