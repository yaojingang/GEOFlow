<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegacySiteMode
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if((bool) config('zeropoint.enabled', false), 404);

        return $next($request);
    }
}
