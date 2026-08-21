<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeRequestHost
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower(rtrim($request->getHost(), '.'));

        if ($host === ''
            || strlen($host) > 253
            || preg_match('/^[a-z0-9.-]+$/', $host) !== 1
            || str_contains($host, '..')) {
            abort(404);
        }

        $request->attributes->set('normalized_host', $host);

        return $next($request);
    }
}
