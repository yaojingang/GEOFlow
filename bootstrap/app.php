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
use App\Http\Middleware\EnforceCurrentSiteSurface;
use App\Http\Middleware\EnsureAdminUiV3Enabled;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\EnsureBrowserOperationsProtocol;
use App\Http\Middleware\EnsureHostedSitesEnabled;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\GuardRecoveryTraffic;
use App\Http\Middleware\GuardRecoveryWrites;
use App\Http\Middleware\LimitArticleMarkdownExportRequestSize;
use App\Http\Middleware\LogAdminActivity;
use App\Http\Middleware\NormalizeRequestHost;
use App\Http\Middleware\RecordSiteViewLog;
use App\Http\Middleware\RenderAiWorkspaceJsonErrors;
use App\Http\Middleware\ResolveCurrentSite;
use App\Http\Middleware\ScopeThemeRevision;
use App\Http\Middleware\SiteWebLocale;
use App\Http\Middleware\TrackAdminRecentPage;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
        $workspaceFileChanges = static fn (Request $request): bool => $request->isMethod('POST')
            && count($request->segments()) === 6
            && $request->is('api/v1/management/theme-workspaces/*/changes')
            && Str::isUuid((string) $request->segment(5));
        $middleware->trimStrings(except: ['friend_links.links.*.url', $workspaceFileChanges]);
        $middleware->convertEmptyStringsToNull(except: [$workspaceFileChanges]);
        $middleware->prepend(LimitArticleMarkdownExportRequestSize::class);
        $middleware->trustHosts(static function (): array {
            $patterns = [];
            foreach (config('geoflow.hosted_sites.primary_hosts', []) as $hostname) {
                $patterns[] = '^'.preg_quote($hostname, '/').'$';
            }
            foreach (config('geoflow.hosted_sites.root_domains', []) as $rootDomain) {
                $patterns[] = '^[^.]+\\.'.preg_quote($rootDomain, '/').'$';
            }

            return $patterns;
        }, subdomains: false);
        $middleware->append([
            NormalizeRequestHost::class,
            ResolveCurrentSite::class,
            EnforceCurrentSiteSurface::class,
            ScopeThemeRevision::class,
        ]);
        $middleware->prependToGroup('web', GuardRecoveryTraffic::class);
        $middleware->prependToGroup('api', GuardRecoveryTraffic::class);
        $middleware->appendToGroup('web', AssignApiRequestId::class);

        $middleware->alias([
            // 生成/透传 X-Request-Id，并写入响应头
            'api.request_id' => AssignApiRequestId::class,
            // Authorization: Bearer，解析 Sanctum token 并注入 ApiAuthContext
            'api.auth' => AuthenticateApiToken::class,
            'api.recovery' => GuardRecoveryWrites::class,
            // 校验 Token scopes，如 api.scope:catalog:read
            'api.scope' => EnsureApiScope::class,
            'browser.protocol' => EnsureBrowserOperationsProtocol::class,
            // Blade 后台：管理员会话鉴权（失败跳转 admin.login）
            'admin.auth' => AuthenticateAdminWeb::class,
            // Blade 后台：session locale
            'admin.locale' => AdminWebLocale::class,
            // 前台：固定 public_locale（默认 zh_CN）
            'site.locale' => SiteWebLocale::class,
            // 前台：保存访问日志，供数据分析模块统计 PV、路径和爬虫类型
            'site.view_log' => RecordSiteViewLog::class,
            'hosted-sites.enabled' => EnsureHostedSitesEnabled::class,
            // Blade 后台：仅超级管理员
            'admin.super' => EnsureSuperAdmin::class,
            // Blade 后台：写操作日志
            'admin.activity' => LogAdminActivity::class,
            // Blade 后台：记录经过权限过滤的最近处理页面
            'admin.recent' => TrackAdminRecentPage::class,
            // Blade 后台：V3 独占页面在功能开关关闭时不可访问
            'admin.ui-v3' => EnsureAdminUiV3Enabled::class,
        ]);

        // 已登录的管理员访问登录页(guest:admin)时，重定向到后台仪表盘，而不是 Laravel 默认的 "/"。
        // 默认逻辑只认名为 dashboard/home 的路由，本项目是 admin.dashboard/site.home，匹配不到就回落到 "/"，
        // 导致“已登录后再打开登录页”被弹到前台内容站。这里显式指向后台首页。
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'credential',
            'preview_credential',
            'confirmation',
            'api_key',
            'package_password',
            'current_password',
            'current_admin_password',
            'updater_authorization_code',
            'authorization_code',
            'new_password',
            'confirm_password',
            'keywords_text',
            'titles_text',
            'outputs',
        ]);

        $exceptions->render(function (Throwable $e, Request $request) {
            $routeName = (string) ($request->route()?->getName() ?? '');
            if (! Str::startsWith($routeName, 'admin.ai-workspace.')) {
                return null;
            }

            return RenderAiWorkspaceJsonErrors::responseFor($e);
        });

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
                return response($e->getMessage(), $e->getHttpStatus())
                    ->header('Content-Type', 'text/plain; charset=utf-8')
                    ->header('Cache-Control', 'no-store, private');
            }

            $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());

            $headers = ['X-Request-Id' => $rid];
            $retryAfter = $e->getDetails()['retry_after'] ?? null;
            if ($e->getHttpStatus() === 429 && is_int($retryAfter) && $retryAfter >= 0 && $retryAfter <= 86400) {
                $headers['Retry-After'] = (string) $retryAfter;
            }

            return ApiResponse::error(
                $e->getErrorCode(),
                $e->getMessage(),
                $rid,
                $e->getHttpStatus(),
                $e->getDetails()
            )->withHeaders($headers);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                return null;
            }

            $hostRejected = $e instanceof SuspiciousOperationException
                || ($e instanceof BadRequestHttpException
                    && $e->getPrevious() instanceof SuspiciousOperationException);

            return $hostRejected
                ? response('', 404)->header('X-Robots-Tag', 'noindex, nofollow')
                : null;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof ApiException) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());

                return ApiResponse::error('validation_failed', $e->getMessage(), $rid, 422, ['errors' => $e->errors()])->withHeaders(['X-Request-Id' => $rid]);
            }

            $hostRejected = $e instanceof SuspiciousOperationException
                || ($e instanceof BadRequestHttpException
                    && $e->getPrevious() instanceof SuspiciousOperationException);
            if ($e instanceof NotFoundHttpException || $hostRejected) {
                $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());

                return ApiResponse::error(
                    'not_found',
                    'Not Found',
                    $rid,
                    404
                )->withHeaders(['X-Request-Id' => $rid]);
            }

            if ($e instanceof HttpExceptionInterface) {
                $rid = (string) ($request->attributes->get('request_id') ?? Str::uuid()->toString());
                $status = $e->getStatusCode();

                return ApiResponse::error('http_'.$status, Response::$statusTexts[$status] ?? 'Request failed', $rid, $status)
                    ->withHeaders(array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'])))
                    ->withHeaders(['X-Request-Id' => $rid]);
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
