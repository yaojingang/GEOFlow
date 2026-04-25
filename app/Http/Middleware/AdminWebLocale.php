<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 根据 session `locale` 设置应用语言（与 bak 后台语言切换一致，键为 zh_CN / en）。
 */
class AdminWebLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = (string) $request->session()->get('locale', '');
        if (! in_array($locale, ['zh_CN', 'en'], true)) {
            $locale = 'zh_CN';
            $request->session()->put('locale', $locale);
        }
        app()->setLocale($locale);

        return $next($request);
    }
}
