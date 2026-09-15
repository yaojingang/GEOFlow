<?php

namespace App\Http\Middleware;

use App\Support\Site\ThemeRevisionContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class ScopeThemeRevision
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(ThemeRevisionContext::class);
        $context->reset();
        $finder = View::getFinder();
        $paths = $finder->getPaths();
        try {
            $response = $next($request);
            $snapshot = $context->snapshot();
            if (($snapshot['revision_id'] ?? null) !== null
                && str_starts_with((string) $request->route()?->getName(), 'site.')
                && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                $source = '/themes/'.$snapshot['theme_id'].'/';
                $target = '/theme-assets/'.$snapshot['revision_id'].'/';
                $response->setContent(str_replace($source, $target, (string) $response->getContent()));
            }

            return $response;
        } finally {
            View::setFinder($finder);
            $finder->setPaths($paths);
            $finder->flush();
            $context->reset();
        }
    }
}
