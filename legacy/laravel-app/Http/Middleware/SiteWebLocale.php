<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * 前台路由专用：固定翻译语言（默认 zh_CN），与后台 {@see AdminWebLocale} 会话语言解耦，避免 APP_LOCALE=en 时导航变成英文。
 */
final class SiteWebLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = trim((string) config('geoflow.public_locale', 'zh_CN'));
        if ($locale !== '') {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
