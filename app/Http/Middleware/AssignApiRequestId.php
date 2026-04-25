<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignApiRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('X-Request-Id');
        $id = is_string($header) && trim($header) !== ''
            ? mb_substr(trim($header), 0, 128)
            : (string) Str::uuid();

        $request->attributes->set('request_id', $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
