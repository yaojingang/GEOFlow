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
        private AiWorkspaceRuntimeStatus $runtimeStatus,
    ) {}

    /** @return array{ready:bool,message:string,test_url:?string,settings_url:?string,settings_label:?string} */
    public function forAdmin(Admin $admin): array
    {
        if (! $this->runtimeStatus->enabled()) {
            $canManageRuntime = $admin->canManageProtectedWorkflows();

            return [
                'ready' => false,
                'message' => $canManageRuntime
                    ? __('admin.ai_workspace.connection_runtime_disabled_admin')
                    : __('admin.ai_workspace.connection_runtime_disabled'),
                'test_url' => null,
                'settings_url' => $canManageRuntime
                    ? AdminWeb::routePath('admin.site-settings.index').'#site-settings-ai-workspace'
                    : null,
                'settings_label' => $canManageRuntime
                    ? __('admin.ai_workspace.connection_runtime_settings')
                    : null,
            ];
        }
        $status = $this->readiness->status($admin);
        if ($status['ready']) {
            return ['ready' => true, 'message' => '', 'test_url' => null, 'settings_url' => null, 'settings_label' => null];
        }
        if ($status['reason'] !== __('admin.ai_workspace.readiness_no_verified_model')) {
            return $this->modelSettingsRecovery((string) $status['reason']);
        }

        try {
            $model = $this->executionGuard->resolveCandidates($this->executionGuard->directContext($admin))->first();
        } catch (AiModelAccessException) {
            $model = null;
        }
        if ($model === null) {
            return $this->modelSettingsRecovery(__('admin.ai_workspace.connection_no_model'));
        }
        if (! Gate::forUser($admin)->allows('test', $model)) {
            return $this->modelSettingsRecovery(__('admin.ai_workspace.connection_shared_model'));
        }

        return [
            'ready' => false,
            'message' => __('admin.ai_workspace.connection_needs_check', ['model' => $model->name]),
            'test_url' => AdminWeb::routePath('admin.ai-models.test', ['modelId' => $model->id]),
            'settings_url' => AdminWeb::routePath('admin.ai-models.index'),
            'settings_label' => __('admin.ai_workspace.connection_settings'),
        ];
    }

    /** @return array{ready:false,message:string,test_url:null,settings_url:string,settings_label:string} */
    private function modelSettingsRecovery(string $message): array
    {
        return [
            'ready' => false,
            'message' => $message,
            'test_url' => null,
            'settings_url' => AdminWeb::routePath('admin.ai-models.index'),
            'settings_label' => __('admin.ai_workspace.connection_settings'),
        ];
    }
}
