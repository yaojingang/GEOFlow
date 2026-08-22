<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminUiV3Enabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('geoflow.admin_ui_v3_enabled', false), 404);

        return $next($request);
    }
}
