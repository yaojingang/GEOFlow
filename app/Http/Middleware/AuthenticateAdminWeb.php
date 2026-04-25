<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 后台会话鉴权中间件：未登录时跳转 admin.login，避免默认 login 路由缺失导致 500。
 */
class AuthenticateAdminWeb
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 仅检查 admin guard，保持与 geo_admin 后台会话体系一致。
        if (! Auth::guard('admin')->check()) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
