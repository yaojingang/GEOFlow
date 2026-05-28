<?php

namespace App\Http\Middleware;

use App\Support\AdminWeb;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 根据 session `locale` 设置应用语言。
 */
class AdminWebLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('locale', '');
        if (! AdminWeb::isSupportedLocale($locale)) {
            $locale = 'zh_CN';
            $request->session()->put('locale', $locale);
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
