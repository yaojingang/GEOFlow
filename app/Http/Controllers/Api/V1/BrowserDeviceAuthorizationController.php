<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Services\BrowserOperations\DeviceAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrowserDeviceAuthorizationController extends BaseApiController
{
    public function store(Request $request, DeviceAuthorizationService $authorizations): JsonResponse
    {
        $this->ensureTrustedInstance($request);
        $clientName = trim((string) $request->input('client_name', 'GEOFlow Chrome'));
        if ($clientName === '' || mb_strlen($clientName) > 80) {
            throw new ApiException('validation_failed', '客户端名称格式无效', 422);
        }

        $authorization = $authorizations->create($clientName);
        $verificationUri = route('admin.manual-publications.browser-connect.show');
        $authorization['verification_uri'] = $verificationUri;
        $authorization['verification_uri_complete'] = $verificationUri.'?'.http_build_query([
            'user_code' => $authorization['user_code'],
        ]);

        return $this->success($request, $authorization);
    }

    public function token(Request $request, DeviceAuthorizationService $authorizations): JsonResponse
    {
        $this->ensureTrustedInstance($request);
        $deviceCode = trim((string) $request->input('device_code'));
        if ($deviceCode === '' || strlen($deviceCode) > 128) {
            throw new ApiException('validation_failed', '设备码格式无效', 422);
        }

        return $this->success($request, $authorizations->exchange(
            $deviceCode,
            (string) $request->attributes->get('browser_client_version'),
        ));
    }

    private function ensureTrustedInstance(Request $request): void
    {
        $host = strtolower($request->getHost());
        if (! $request->isSecure() && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new ApiException('insecure_instance', '远程 GEOFlow 实例必须使用 HTTPS', 400);
        }
    }
}
