<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\TaskRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AiExecutionAccessGuard
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $modelAccessResolver,
        private readonly AiExecutionContextFactory $contextFactory,
    ) {}

    public function assertCurrent(AiExecutionContext $context, bool $validateRequestedModel = false): Admin
    {
        $this->assertExecutionLeaseCurrent($context);

        $admin = $this->lockWhenTransactional(
            Admin::query()->whereKey($context->modelAccessAdminId),
        )->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactiveForId($context->modelAccessAdminId);
        }

        if ($this->contextFactory->normalizedRole($admin) !== $context->modelAccessAdminRole
            || max(1, (int) $admin->ai_config_access_version) !== $context->aiConfigAccessVersion
            || $context->resolverPolicyVersion !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        if ($validateRequestedModel && $context->requestedModelId !== null) {
            $this->assertModelCurrent($context, $context->requestedModelId, $admin);
        }

        return $admin;
    }

    public function assertModelCurrent(
        AiExecutionContext $context,
        AiModel|int $model,
        ?Admin $currentAdmin = null,
    ): AiModel {
        $admin = $currentAdmin ?? $this->assertCurrent($context);
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        $currentModel = $this->lockWhenTransactional(
            AiModel::query()->whereKey($modelId),
        )->first();
        if (! $currentModel instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
        }

        if (! $admin->isSuperAdmin()
            && (int) $currentModel->owner_admin_id !== (int) $admin->getKey()
            && (int) ($admin->shared_ai_config_owner_id ?? 0) === (int) $currentModel->owner_admin_id) {
            $providerId = (int) $currentModel->owner_admin_id;
            $provider = $this->lockWhenTransactional(
                Admin::query()->whereKey($providerId),
            )->first();
            if (! $provider instanceof Admin
                || (string) $provider->status !== 'active'
                || ! $provider->isSuperAdmin()) {
                throw AiModelAccessException::configOwnerInactive($admin, $providerId);
            }
        }

        $this->modelAccessResolver->assertLockedUsable($admin, $currentModel);

        return $currentModel;
    }

    /** @return list<AiModel> */
    public function resolveModelCandidates(AiExecutionContext $context, string $modelType): array
    {
        $admin = $this->assertCurrent($context);

        return $this->modelAccessResolver
            ->resolveCandidates($admin, $modelType)
            ->all();
    }

    /**
     * Resolve an ownership-scoped candidate list during shadow rollout.
     *
     * Shadow mode can defer access-version enforcement, while the actual provider
     * calls must still remain inside the execution administrator's model pools.
     *
     * @return list<AiModel>
     */
    public function resolveModelCandidatesForShadow(AiExecutionContext $context, string $modelType): array
    {
        $admin = Admin::query()
            ->whereKey($context->modelAccessAdminId)
            ->active()
            ->first();
        if (! $admin instanceof Admin) {
            throw AiModelAccessException::executionAdminInactiveForId($context->modelAccessAdminId);
        }

        return $this->modelAccessResolver
            ->resolveCandidates($admin, $modelType)
            ->all();
    }

    public function assertModelCurrentForShadow(AiExecutionContext $context, AiModel|int $model): AiModel
    {
        $admin = Admin::query()
            ->whereKey($context->modelAccessAdminId)
            ->active()
            ->first();
        if (! $admin instanceof Admin) {
            throw AiModelAccessException::executionAdminInactiveForId($context->modelAccessAdminId);
        }

        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        $currentModel = AiModel::query()->whereKey($modelId)->first();
        if (! $currentModel instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
        }

        $this->modelAccessResolver->assertLockedUsable($admin, $currentModel);

        return $currentModel;
    }

    public function recordResolvedModel(AiExecutionContext $context, AiModel $model): void
    {
        if ($context->taskRunId === null) {
            return;
        }

        $admin = $this->assertCurrent($context);
        $currentModel = $this->assertModelCurrent($context, $model, $admin);
        $source = (int) $currentModel->owner_admin_id === (int) $admin->getKey()
            ? 'personal'
            : 'shared';

        $this->writeResolvedModel($context, $currentModel, $source);
    }

    public function recordResolvedModelSnapshot(AiExecutionContext $context, AiModel $model): void
    {
        if ($context->taskRunId === null) {
            return;
        }

        $admin = Admin::query()->whereKey($context->modelAccessAdminId)->first();
        $currentModel = AiModel::query()->whereKey($model->getKey())->first();
        if (! $admin instanceof Admin || ! $currentModel instanceof AiModel) {
            return;
        }

        $source = match (true) {
            (string) $currentModel->access_scope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY => 'system',
            (int) $currentModel->owner_admin_id === (int) $admin->getKey() => 'personal',
            ! $admin->isSuperAdmin()
                && (int) ($admin->shared_ai_config_owner_id ?? 0) === (int) $currentModel->owner_admin_id => 'shared',
            default => null,
        };
        if ($source === null) {
            return;
        }

        $this->writeResolvedModel($context, $currentModel, $source);
    }

    private function writeResolvedModel(
        AiExecutionContext $context,
        AiModel $model,
        string $source,
    ): void {

        $affected = TaskRun::query()
            ->whereKey($context->taskRunId)
            ->where('model_access_admin_id', $context->modelAccessAdminId)
            ->where('ai_config_access_version', $context->aiConfigAccessVersion)
            ->where('resolver_policy_version', $context->resolverPolicyVersion)
            ->where('execution_lease_token', $context->executionLeaseToken())
            ->where('status', 'running')
            ->whereNull('resolved_ai_model_id')
            ->update([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => $source,
                'model_resolved_at' => now(),
            ]);
        if ($affected !== 1) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }
    }

    private function assertExecutionLeaseCurrent(AiExecutionContext $context): void
    {
        if ($context->taskRunId === null) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        $run = $this->lockWhenTransactional(TaskRun::query()->whereKey($context->taskRunId))->first();
        if (! $run instanceof TaskRun
            || (string) $run->status !== 'running'
            || (int) $run->model_access_admin_id !== $context->modelAccessAdminId
            || (string) $run->model_access_admin_role !== $context->modelAccessAdminRole
            || (int) $run->ai_config_access_version !== $context->aiConfigAccessVersion
            || (int) $run->resolver_policy_version !== $context->resolverPolicyVersion
            || ! hash_equals($context->executionLeaseToken(), (string) $run->execution_lease_token)) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
