<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\UrlImportJob;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class UrlImportAiExecutionGuard
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $modelAccessResolver,
    ) {}

    /**
     * @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,requested_ai_model_id:int,resolver_policy_version:int}
     */
    public function snapshotForCreation(Admin $actor, AiModel $requestedModel): array
    {
        $admin = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($actor);
        }

        $model = AiModel::query()->whereKey($requestedModel->getKey())->lockForUpdate()->first();
        if (! $model instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin, $requestedModel);
        }
        $this->modelAccessResolver->assertLockedUsable($admin, $model);

        return [
            'model_access_admin_id' => (int) $admin->getKey(),
            'model_access_admin_role' => $admin->isSuperAdmin() ? 'super_admin' : 'admin',
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'requested_ai_model_id' => (int) $model->getKey(),
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    public function assertCurrent(UrlImportJob $job, AiModel|int|null $model = null): Admin
    {
        $currentJob = $this->lockWhenTransactional(UrlImportJob::query()->whereKey($job->getKey()))->first();
        $expectedLease = trim((string) ($job->execution_lease_token ?? ''));
        $currentLease = trim((string) ($currentJob?->execution_lease_token ?? ''));
        if ($expectedLease !== '' && ($currentLease === '' || ! hash_equals($expectedLease, $currentLease))) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($job->model_access_admin_id ?? 0),
            );
        }
        $adminId = (int) ($currentJob?->model_access_admin_id ?? 0);
        $storedRole = trim((string) ($currentJob?->model_access_admin_role ?? ''));
        $storedAccessVersion = (int) ($currentJob?->ai_config_access_version ?? 0);
        $storedPolicyVersion = (int) ($currentJob?->resolver_policy_version ?? 0);
        $requestedModelId = (int) ($currentJob?->requested_ai_model_id ?? 0);
        if ($adminId <= 0 || $storedRole === '' || $storedAccessVersion <= 0
            || $requestedModelId <= 0
            || $storedPolicyVersion !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw AiModelAccessException::configAccessRevokedForAdminId(max(0, $adminId));
        }

        $admin = $this->lockWhenTransactional(Admin::query()->whereKey($adminId))->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactiveForId($adminId);
        }

        $currentRole = $admin->isSuperAdmin() ? 'super_admin' : 'admin';
        if ($storedRole !== $currentRole || $storedAccessVersion !== max(1, (int) $admin->ai_config_access_version)) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        if ($model !== null) {
            $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
            $currentModel = $this->lockWhenTransactional(AiModel::query()->whereKey($modelId))->first();
            if (! $currentModel instanceof AiModel) {
                throw AiModelAccessException::modelUnavailable($admin);
            }
            $this->modelAccessResolver->assertLockedUsable($admin, $currentModel);
        }

        return $admin;
    }

    /** @return Collection<int, AiModel> */
    public function resolveCandidates(UrlImportJob $job): Collection
    {
        $requestedModelId = (int) ($job->requested_ai_model_id ?? 0);
        $admin = $this->assertCurrent($job, $requestedModelId > 0 ? $requestedModelId : null);
        $candidates = $this->modelAccessResolver->resolveCandidates($admin, 'chat');
        if ($requestedModelId <= 0) {
            return $candidates;
        }

        $requested = $candidates->first(
            static fn (AiModel $model): bool => (int) $model->getKey() === $requestedModelId,
        );
        if (! $requested instanceof AiModel) {
            $storedRequested = AiModel::query()->find($requestedModelId);
            if ($storedRequested instanceof AiModel) {
                throw AiModelAccessException::modelNotAccessible($admin, $storedRequested);
            }

            throw AiModelAccessException::modelUnavailable($admin);
        }

        return $candidates
            ->reject(static fn (AiModel $model): bool => (int) $model->getKey() === $requestedModelId)
            ->prepend($requested)
            ->values();
    }

    public function recordResolvedModel(UrlImportJob $job, AiModel $model): void
    {
        DB::transaction(function () use ($job, $model): void {
            $lockedJob = UrlImportJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            $admin = $this->assertCurrent($job, $model);
            $source = (int) $model->owner_admin_id === (int) $admin->getKey()
                ? 'personal'
                : 'shared';

            $lockedJob->forceFill([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => $source,
                'model_resolved_at' => now(),
            ])->save();
        });

        $job->refresh();
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
