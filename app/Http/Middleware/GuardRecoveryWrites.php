<?php

namespace App\Http\Middleware;

use App\Services\SystemUpdater\RecoveryState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GuardRecoveryWrites
{
    public function __construct(private readonly RecoveryState $state) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->routeIs('api.v1.management.updater.submit')) {
            $this->state->assertWriteEpoch($request->header('X-GEOFlow-Recovery-Epoch'));
        }

        return $next($request);
    }
}
