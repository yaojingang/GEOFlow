<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrowserSessionController extends BaseApiController
{
    public function show(Request $request): JsonResponse
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->findOrFail($auth->auditAdminId);

        return $this->success($request, [
            'protocol_version' => 1,
            'admin' => [
                'id' => (int) $admin->getKey(),
                'display_name' => $admin->name,
                'role' => (string) $admin->role,
            ],
            'scopes' => array_values((array) ($auth->token['scopes'] ?? [])),
        ]);
    }

    public function destroy(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $auth = $this->auth($request);
        $admin = Admin::query()->findOrFail($auth->auditAdminId);
        $tokens->revokeToken((int) $auth->token['id']);
        AdminActivityLogger::log($admin, 'browser_client.revoked_self', [
            'request_method' => 'DELETE',
            'page' => $request->path(),
            'target_type' => 'personal_access_token',
            'target_id' => (int) $auth->token['id'],
        ]);

        return $this->success($request, ['revoked' => true]);
    }
}
