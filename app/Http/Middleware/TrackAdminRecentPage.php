<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\AdminUiRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrackAdminRecentPage
{
    public function __construct(private readonly AdminUiRegistry $registry) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $admin = $request->user('admin');
        $routeName = (string) ($request->route()?->getName() ?? '');

        if ($admin instanceof Admin
            && $this->registry->routeClassification($routeName) === 'shell'
            && $this->registry->shouldRememberRoute($routeName)
            && $response->isSuccessful()
            && str_contains((string) $response->headers->get('content-type'), 'text/html')) {
            $this->registry->remember($request, $admin);
        }

        return $response;
    }
}
