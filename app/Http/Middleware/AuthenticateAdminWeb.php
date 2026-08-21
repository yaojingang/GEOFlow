<?php

namespace App\Http\Middleware;

use App\Models\Admin;
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
        $guard = Auth::guard('admin');
        if (! $guard->check()) {
            return redirect()->route('admin.login');
        }

        $adminId = (int) ($guard->id() ?? 0);
        $admin = $adminId > 0 ? Admin::query()->find($adminId) : null;
        if (! $admin instanceof Admin || $admin->status !== 'active') {
            return $this->logout($request);
        }

        $sessionVersion = $request->session()->get(Admin::AUTH_VERSION_SESSION_KEY);
        if ($sessionVersion === null && $guard->viaRemember()) {
            $sessionVersion = (int) $admin->auth_version;
            $request->session()->put(Admin::AUTH_VERSION_SESSION_KEY, $sessionVersion);
        }
        if ($sessionVersion === null || (int) $sessionVersion !== $admin->auth_version) {
            return $this->logout($request);
        }

        $guard->setUser($admin);

        return $next($request);
    }

    private function logout(Request $request): Response
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
