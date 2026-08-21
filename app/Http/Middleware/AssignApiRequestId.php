<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignApiRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Request-Id');
        $id = is_string($header)
            && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', trim($header)) === 1
            ? mb_substr(trim($header), 0, 128)
            : (string) Str::uuid();

        $request->attributes->set('request_id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);
        if ($response->getStatusCode() === 403 && $this->isAdminRequest($request)) {
            Log::warning('geoflow.admin_forbidden', [
                'request_id' => $id,
                'admin_id' => (int) ($request->user('admin')?->getAuthIdentifier() ?? 0),
                'method' => $request->method(),
                'route' => (string) ($request->route()?->getName() ?? ''),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    private function isAdminRequest(Request $request): bool
    {
        $adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        return $adminPrefix !== '' && (
            $request->is($adminPrefix)
            || $request->is($adminPrefix.'/*')
        );
    }
}
