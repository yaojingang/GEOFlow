<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 限制仅 `admins.role = super_admin` 可访问（对应 bak is_super_admin）。
 */
class EnsureSuperAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        if (! $admin || ! method_exists($admin, 'isSuperAdmin') || ! $admin->isSuperAdmin()) {
            abort(403, 'Forbidden');
        }

        return $next($request);
    }
}
