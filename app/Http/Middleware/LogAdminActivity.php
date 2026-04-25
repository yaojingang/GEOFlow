<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminActivityLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台管理员写操作日志中间件。
 *
 * 仅记录 POST/PUT/PATCH/DELETE 请求，避免把列表浏览型 GET 全量灌入日志。
 */
class LogAdminActivity
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 先放行业务逻辑，确保日志失败不阻断正常响应。
        $response = $next($request);

        $method = strtoupper((string) $request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        /** @var Admin|null $admin */
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin) {
            return $response;
        }

        $action = (string) ($request->input('action') ?: 'submit');
        $routeName = (string) ($request->route()?->getName() ?? '');
        // 组合路由名 + action，便于后续按模块和操作类型筛选审计日志。
        $fullAction = $routeName !== '' ? $routeName.':'.$action : $action;

        AdminActivityLogger::logFromRequest($request, $admin, $fullAction, $request->except(['password', 'current_password', 'new_password', 'confirm_password']));

        return $response;
    }
}
