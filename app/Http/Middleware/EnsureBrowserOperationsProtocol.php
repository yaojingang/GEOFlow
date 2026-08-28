<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureBrowserOperationsProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-GEOFlow-Browser-Protocol') !== '1') {
            throw new ApiException('upgrade_required', '浏览器协议版本不兼容', 426, [
                'supported_protocol' => 1,
            ]);
        }

        $clientVersion = trim((string) $request->header('X-GEOFlow-Client-Version'));
        if (preg_match('/\A[A-Za-z0-9._+\-]{1,64}\z/D', $clientVersion) !== 1) {
            throw new ApiException('invalid_client_version', '缺少或无法识别扩展版本', 422);
        }

        $request->attributes->set('browser_client_version', $clientVersion);

        return $next($request);
    }
}
