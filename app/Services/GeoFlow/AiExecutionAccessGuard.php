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

        TaskRun::query()
            ->whereKey($context->taskRunId)
            ->where('model_access_admin_id', $context->modelAccessAdminId)
            ->where('ai_config_access_version', $context->aiConfigAccessVersion)
            ->where('resolver_policy_version', $context->resolverPolicyVersion)
            ->where('status', 'running')
            ->whereNull('resolved_ai_model_id')
            ->update([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => $source,
                'model_resolved_at' => now(),
            ]);
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
