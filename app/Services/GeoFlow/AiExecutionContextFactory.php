<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\Task;
use App\Models\TaskRun;
use InvalidArgumentException;

final class AiExecutionContextFactory
{
    /**
     * @return array{model_access_admin_id:int,model_access_admin_role:string,model_access_policy_version:int}|null
     */
    public function identityForTaskCreation(Admin|int|null $executionAdmin): ?array
    {
        if (! (bool) config('geoflow.admin_ai_access.ownership_write_enabled', true)) {
            if ($this->identityRequired()) {
                throw AiModelAccessException::configAccessRevokedForAdminId(
                    $executionAdmin instanceof Admin ? (int) $executionAdmin->getKey() : (int) ($executionAdmin ?? 0),
                );
            }

            return null;
        }

        $adminId = $executionAdmin instanceof Admin
            ? (int) $executionAdmin->getKey()
            : (int) ($executionAdmin ?? 0);
        if ($adminId <= 0) {
            if ($this->identityRequired()) {
                throw AiModelAccessException::configAccessRevokedForAdminId(0);
            }

            return null;
        }

        $admin = Admin::query()
            ->whereKey($adminId)
            ->lockForUpdate()
            ->first();
        if (! $admin instanceof Admin) {
            throw AiModelAccessException::executionAdminInactiveForId($adminId);
        }
        if ((string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($admin);
        }

        return [
            'model_access_admin_id' => $adminId,
            'model_access_admin_role' => $this->normalizedRole($admin),
            'model_access_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    /**
     * The caller must hold a write lock on the task row.
     *
     * @return array{model_access_admin_id:?int,model_access_admin_role:?string,ai_config_access_version:?int,requested_ai_model_id:?int,resolver_policy_version:?int}
     */
    public function taskRunIdentity(Task $lockedTask): array
    {
        $adminId = (int) ($lockedTask->model_access_admin_id ?? 0);
        $storedRole = trim((string) ($lockedTask->model_access_admin_role ?? ''));
        $policyVersion = (int) ($lockedTask->model_access_policy_version ?? 0);

        if ($adminId <= 0 || $storedRole === '' || $policyVersion <= 0) {
            if ($this->identityRequired()) {
                throw AiModelAccessException::configAccessRevokedForAdminId(max(0, $adminId));
            }

            return $this->emptyRunIdentity($lockedTask);
        }

        $admin = Admin::query()
            ->whereKey($adminId)
            ->lockForUpdate()
            ->first();
        if (! $admin instanceof Admin) {
            if ($this->identityRequired()) {
                throw AiModelAccessException::executionAdminInactiveForId($adminId);
            }

            return $this->emptyRunIdentity($lockedTask);
        }

        if ($this->identityRequired() && $storedRole !== $this->normalizedRole($admin)) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        return [
            'model_access_admin_id' => $adminId,
            'model_access_admin_role' => $storedRole,
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'requested_ai_model_id' => $lockedTask->ai_model_id === null ? null : (int) $lockedTask->ai_model_id,
            'resolver_policy_version' => $policyVersion,
        ];
    }

    public function fromTaskRun(TaskRun $run): AiExecutionContext
    {
        try {
            return AiExecutionContext::fromPersistedTaskRun($run);
        } catch (InvalidArgumentException) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($run->model_access_admin_id ?? 0),
            );
        }
    }

    public function normalizedRole(Admin $admin): string
    {
        return $admin->isSuperAdmin() ? 'super_admin' : 'admin';
    }

    public function identityRequired(): bool
    {
        return (bool) config('geoflow.admin_ai_access.access_enforce_enabled', false)
            || (bool) config('geoflow.admin_ai_access.revocation_enforce_enabled', false);
    }

    /** @return array{model_access_admin_id:null,model_access_admin_role:null,ai_config_access_version:null,requested_ai_model_id:?int,resolver_policy_version:null} */
    private function emptyRunIdentity(Task $task): array
    {
        return [
            'model_access_admin_id' => null,
            'model_access_admin_role' => null,
            'ai_config_access_version' => null,
            'requested_ai_model_id' => $task->ai_model_id === null ? null : (int) $task->ai_model_id,
            'resolver_policy_version' => null,
        ];
    }
}
