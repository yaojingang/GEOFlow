<?php

namespace App\Services\AiWorkspace;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Support\AdminWeb;
use Illuminate\Support\Facades\Gate;

final readonly class AiWorkspaceConnectionStatus
{
    public function __construct(
        private AiWorkspaceModelReadiness $readiness,
        private AiWorkspaceExecutionAccessGuard $executionGuard,
    ) {}

    /** @return array{ready:bool,message:string,test_url:?string} */
    public function forAdmin(Admin $admin): array
    {
        if (! config('ai-workspace.runtime_enabled', false)) {
            return ['ready' => false, 'message' => __('admin.ai_workspace.connection_runtime_disabled'), 'test_url' => null];
        }
        $status = $this->readiness->status($admin);
        if ($status['ready']) {
            return ['ready' => true, 'message' => '', 'test_url' => null];
        }
        if ($status['reason'] !== __('admin.ai_workspace.readiness_no_verified_model')) {
            return ['ready' => false, 'message' => (string) $status['reason'], 'test_url' => null];
        }

        try {
            $model = $this->executionGuard->resolveCandidates($this->executionGuard->directContext($admin))->first();
        } catch (AiModelAccessException) {
            $model = null;
        }
        if ($model === null) {
            return ['ready' => false, 'message' => __('admin.ai_workspace.connection_no_model'), 'test_url' => null];
        }
        if (! Gate::forUser($admin)->allows('test', $model)) {
            return ['ready' => false, 'message' => __('admin.ai_workspace.connection_shared_model'), 'test_url' => null];
        }

        return [
            'ready' => false,
            'message' => __('admin.ai_workspace.connection_needs_check', ['model' => $model->name]),
            'test_url' => AdminWeb::routePath('admin.ai-models.test', ['modelId' => $model->id]),
        ];
    }
}
