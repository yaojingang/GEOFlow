<?php

namespace App\Support\Site;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

final class SiteThemePreviewContext
{
    private ?string $theme = null;

    private string $frameBase = '';

    public function themeId(): ?string
    {
        return $this->theme;
    }

    public function isActive(): bool
    {
        return $this->theme !== null;
    }

    public function run(string $themeId, string $frameBase, Closure $render, ?Request $siteRequest = null): mixed
    {
        $previousRequest = app('request');
        $previousUrlRequest = URL::getRequest();
        $previousRouter = Route::getFacadeRoot();
        $hadRouteBinding = app()->bound(RoutingRoute::class);
        $previousRouteBinding = $hadRouteBinding ? app(RoutingRoute::class) : null;
        $previousTheme = $this->theme;
        $previousBase = $this->frameBase;
        $previousFormatter = URL::pathFormatter();
        $finder = View::getFinder();
        $previousPaths = $finder->getPaths();
        $this->theme = $themeId;
        $this->frameBase = rtrim($frameBase, '/');

        try {
            if ($siteRequest !== null) {
                app()->instance('request', $siteRequest);
                Facade::clearResolvedInstance('request');
                URL::setRequest($siteRequest);
                // Align route helpers on a scoped clone without dispatching site middleware.
                $previewRouter = clone $previousRouter;
                (function () use ($siteRequest): void {
                    $this->current = $siteRequest->route();
                    $this->currentRequest = $siteRequest;
                })->call($previewRouter);
                Route::swap($previewRouter);
                app()->instance(RoutingRoute::class, $siteRequest->route());
            }
            URL::formatPathUsing(function (string $path, $route) use ($previousFormatter): string {
                $path = $previousFormatter($path, $route);
                if (in_array($route?->getName(), ['site.home', 'site.category', 'site.article', 'site.about', 'site.archive', 'site.archive.month'], true)) {
                    return (string) parse_url($this->frameBase, PHP_URL_PATH).'/'.ltrim($path, '/');
                }

                return $path;
            });

            return $render();
        } finally {
            Route::swap($previousRouter);
            if ($hadRouteBinding) {
                app()->instance(RoutingRoute::class, $previousRouteBinding);
            } else {
                app()->forgetInstance(RoutingRoute::class);
            }
            app()->instance('request', $previousRequest);
            Facade::clearResolvedInstance('request');
            URL::setRequest($previousUrlRequest);
            URL::formatPathUsing($previousFormatter);
            $finder->setPaths($previousPaths);
            $finder->flush();
            $this->theme = $previousTheme;
            $this->frameBase = $previousBase;
        }
    }

    public function urlForPath(string $path): ?string
    {
        if (! $this->isActive() || preg_match('~\A/(?:\?(?:.*)|(?:about|archive(?:/[0-9]{4}/[0-9]{2})?|(?:category|article)/[^/?#]+)(?:[?#].*)?)?\z~D', $path) !== 1) {
            return null;
        }

        return $this->frameBase.'/'.ltrim($path, '/');
    }
}
