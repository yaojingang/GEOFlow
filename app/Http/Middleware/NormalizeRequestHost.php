<?php

namespace App\Http\Middleware;

use App\Support\Site\LinkPoolThemeReadbackSignature;
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
        if (LinkPoolThemeReadbackSignature::valid($request)) {
            $host = LinkPoolThemeReadbackSignature::targetHost($request);
            $request->headers->set('Host', $host);
            $request->headers->set('X-Forwarded-Host', $host);
            $request->attributes->set('link_pool.theme_readback', true);
        }
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
