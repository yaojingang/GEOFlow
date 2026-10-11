<?php

namespace App\Http\Middleware;

use App\Jobs\RecordLinkPoolHtmlServed;
use App\Support\Site\SiteThemePreviewContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class RecordLinkPoolResponse
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->attributes->get('link_pool.theme_readback') === true
            || ! in_array($request->route()?->getName(), ['site.article', 'site.article.resolve'], true)
            || $response->getStatusCode() !== 200
            || app(SiteThemePreviewContext::class)->isActive()
            || ! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        $ids = $request->attributes->get('link_pool.placement_ids', []);
        if (! is_array($ids) || $ids === []) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store');
        if ($request->method() === 'GET') {
            try {
                RecordLinkPoolHtmlServed::dispatch(array_map('intval', $ids), (string) Str::uuid())->onQueue('default');
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $response;
    }
}
