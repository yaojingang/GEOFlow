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
        /** @var Admin|null $admin */
        $admin = auth('admin')->user();

        // 先放行业务逻辑，确保日志失败不阻断正常响应。
        $response = $next($request);

        $method = strtoupper((string) $request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        if (! $admin instanceof Admin) {
            return $response;
        }

        $requestedAction = $request->attributes->get('admin_activity_action');
        $action = is_string($requestedAction)
            && preg_match('/\A[a-z0-9._:-]{1,40}\z/i', $requestedAction) === 1
                ? $requestedAction
                : 'submit';
        $routeName = (string) ($request->route()?->getName() ?? '');
        // 组合路由名 + action，便于后续按模块和操作类型筛选审计日志。
        $fullAction = mb_substr($routeName !== '' ? $routeName.':'.$action : $action, 0, 120);

        $details = $request->except([
            'password', 'password_confirmation', 'package_password',
            'current_password', 'new_password', 'confirm_password',
        ]);
        $explicitDetails = $request->attributes->get('admin_activity_details');
        if (is_array($explicitDetails)) {
            $details = array_replace($details, $explicitDetails);
        }
        if (! array_key_exists('success', $details)) {
            $errors = session()->get('errors');
            $details['success'] = $response->getStatusCode() < 400
                && (! is_object($errors) || ! method_exists($errors, 'any') || ! $errors->any());
        }
        AdminActivityLogger::logFromRequest($request, $admin, $fullAction, $details);

        return $response;
    }
}
