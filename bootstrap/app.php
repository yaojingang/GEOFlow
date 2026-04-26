<?php

/**
 * Laravel 11 应用入口：路由、中间件别名、API 异常渲染为统一 JSON 信封。
 *
 * API 路由：`routes/api.php`（前缀 /api）；`ApiException` 在 api/* 请求下转为 {@see ApiResponse::error}。
 */

use App\Exceptions\ApiException;
use App\Http\Middleware\AdminWebLocale;
use App\Http\Middleware\AssignApiRequestId;
use App\Http\Middleware\AuthenticateAdminWeb;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\SiteWebLocale;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            // 生成/透传 X-Request-Id，并写入响应头
            'api.request_id' => AssignApiRequestId::class,
            // Authorization: Bearer，解析 Sanctum token 并注入 ApiAuthContext
            'api.auth' => AuthenticateApiToken::class,
            // 校验 Token scopes，如 api.scope:catalog:read
            'api.scope' => EnsureApiScope::class,
            // Blade 后台：管理员会话鉴权（失败跳转 admin.login）
            'admin.auth' => AuthenticateAdminWeb::class,
            // Blade 后台：session locale
            'admin.locale' => AdminWebLocale::class,
            // 前台：固定 public_locale（默认 zh_CN）
            'site.locale' => SiteWebLocale::class,
            // Blade 后台：仅超级管理员
            'admin.super' => EnsureSuperAdmin::class,
            // Blade 后台：写操作日志
            'admin.activity' => LogAdminActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /**
         * 后台 firstOrFail 友好错误页：
         * Laravel 渲染流程里 ModelNotFoundException 可能会先包装为 NotFoundHttpException，
         * 因此统一拦截 404，并仅对“模型不存在”场景输出后台风格的 404 视图。
         */
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            $adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
            if (! $request->is($adminPrefix.'/*')) {
                return null;
            }

            if (! $e->getPrevious() instanceof ModelNotFoundException) {
                return null;
            }

            return response()->view('admin.errors.not-found', [
                'pageTitle' => __('admin.common.not_found_title'),
                'activeMenu' => '',
                'adminSiteName' => config('app.name'),
            ], 404);
        });

        $exceptions->render(function (ApiException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());

            return ApiResponse::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $rid,
                $e->getHttpStatus(),
                $e->getDetails()
            )->withHeaders(['X-Request-Id' => $rid]);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof ApiException) {
                return null;
            }

            Log::error($e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());

            return ApiResponse::error(
                'internal_error',
                '服务器内部错误',
                $rid,
                500
            )->withHeaders(['X-Request-Id' => $rid]);
        });
    })->create();
