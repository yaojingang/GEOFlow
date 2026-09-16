<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\ApiTokenService;
use App\Services\Api\ManagementInstance;
use App\Services\Api\ManagementScopePolicy;
use App\Services\Api\ThemeReleaseService;
use App\Services\SystemUpdater\RemoteUpdaterService;
use App\Support\Api\ManagementOperationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementSessionController extends BaseApiController
{
    public function show(Request $request, ManagementInstance $instance, ApiTokenService $tokens): JsonResponse
    {
        return $this->success($request, $this->sessionData($request, $instance, $tokens));
    }

    public function capabilities(Request $request, ManagementInstance $instance, ApiTokenService $tokens): JsonResponse
    {
        $data = $this->sessionData($request, $instance, $tokens);
        $data['operations'] = array_values(array_filter(ManagementOperationRegistry::all(), fn (array $operation): bool => $operation['scope'] === null || in_array($operation['scope'], $data['scopes'], true)));
        $data['updater'] = array_intersect($data['scopes'], RemoteUpdaterService::SCOPES) !== []
            ? app(RemoteUpdaterService::class)->availability() : ['supported' => false, 'reason' => 'scope_required'];
        if (! $data['updater']['supported']) {
            $data['operations'] = array_values(array_filter($data['operations'], fn (array $operation): bool => ! str_starts_with($operation['name'], 'updater.')));
        }
        if (empty($data['updater']['write_supported'])) {
            $data['operations'] = array_values(array_filter($data['operations'], fn (array $operation): bool => ! in_array($operation['name'], ['updater.plan', 'updater.operations.create'], true)));
        }
        $data['operations'] = array_values(array_filter($data['operations'], fn (array $operation): bool => $operation['name'] !== 'updater.operations.create' || array_intersect($data['scopes'], array_values(RemoteUpdaterService::ACTION_SCOPES)) !== []));
        $data['contract_hash'] = hash('sha256', json_encode($data['operations'], JSON_THROW_ON_ERROR));
        $data['limits'] = ['json_response_bytes' => 5 * 1024 * 1024];
        $data['support_stage'] = 'remote-management-preview';
        $data['theme_publication'] = ThemeReleaseService::availability();

        return $this->success($request, $data);
    }

    public function destroy(Request $request, ApiTokenService $tokens): JsonResponse
    {
        $tokens->revokeToken((int) $this->auth($request)->token['id']);

        return $this->success($request, ['revoked' => true]);
    }

    /** @return array<string, mixed> */
    private function sessionData(Request $request, ManagementInstance $instance, ApiTokenService $tokens): array
    {
        $admin = $this->executionAdmin($request);
        $auth = $this->auth($request);
        $effective = array_values(array_filter($tokens->getAvailableScopes(), fn (string $scope): bool => $tokens->tokenHasScope($auth->token, $scope) && ManagementScopePolicy::allows($admin, $scope)));

        return array_merge($instance->describe(), [
            'admin' => $admin->only(['id', 'username', 'display_name', 'role']),
            'token_id' => $auth->token['id'], 'expires_at' => $auth->token['expires_at'],
            'scopes' => $effective,
            'requestable_scopes' => array_values(array_filter(array_merge($tokens->getCliLoginScopes(), ManagementScopePolicy::SCOPES), fn (string $scope): bool => ManagementScopePolicy::allows($admin, $scope))),
        ]);
    }
}
