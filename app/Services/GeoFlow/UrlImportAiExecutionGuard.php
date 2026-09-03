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
     * @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,requested_ai_model_id:int,requested_ai_model_snapshot:string,resolver_policy_version:int}
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
            'requested_ai_model_snapshot' => $this->safeModelSnapshot($model),
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    public function assertCurrent(UrlImportJob $job, AiModel|int|null $model = null): Admin
    {
        $currentJob = $this->lockWhenTransactional(UrlImportJob::query()->whereKey($job->getKey()))->first();
        $expectedLease = trim((string) ($job->execution_lease_token ?? ''));
        $currentLease = trim((string) ($currentJob?->execution_lease_token ?? ''));
        if ($expectedLease === ''
            || (string) ($currentJob?->status ?? '') !== 'running'
            || $currentLease === ''
            || ! hash_equals($expectedLease, $currentLease)
            || $currentJob?->lease_expires_at === null
            || $currentJob->lease_expires_at->isPast()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($job->model_access_admin_id ?? 0),
            );
        }

        return $this->assertIdentityCurrent($currentJob, $model, true);
    }

    public function assertCommitCurrent(
        UrlImportJob $job,
        string $expectedResultHash,
        AiModel|int|null $model = null,
    ): Admin {
        $currentJob = $this->lockWhenTransactional(UrlImportJob::query()->whereKey($job->getKey()))->first();
        $currentResultHash = hash('sha256', (string) ($currentJob?->result_json ?? ''));
        if ((string) ($currentJob?->status ?? '') !== 'completed'
            || trim((string) ($currentJob?->execution_lease_token ?? '')) !== ''
            || $currentJob?->lease_expires_at !== null
            || ! hash_equals($expectedResultHash, $currentResultHash)) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($job->model_access_admin_id ?? 0),
            );
        }

        return $this->assertIdentityCurrent($currentJob, $model, false);
    }

    private function assertIdentityCurrent(
        ?UrlImportJob $currentJob,
        AiModel|int|null $model,
        bool $requiresRequestedModel,
    ): Admin {
        $adminId = (int) ($currentJob?->model_access_admin_id ?? 0);
        $storedRole = trim((string) ($currentJob?->model_access_admin_role ?? ''));
        $storedAccessVersion = (int) ($currentJob?->ai_config_access_version ?? 0);
        $storedPolicyVersion = (int) ($currentJob?->resolver_policy_version ?? 0);
        $requestedModelId = (int) ($currentJob?->requested_ai_model_id ?? 0);
        $requestedModelSnapshot = trim((string) ($currentJob?->requested_ai_model_snapshot ?? ''));
        if ($adminId <= 0 || $storedRole === '' || $storedAccessVersion <= 0
            || ($requiresRequestedModel && $requestedModelId <= 0)
            || (! $requiresRequestedModel && $requestedModelId <= 0 && $requestedModelSnapshot === '')
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
        } elseif (! $requiresRequestedModel) {
            $this->assertSnapshotOwnerCurrent($admin, $currentJob);
        }

        return $admin;
    }

    private function assertSnapshotOwnerCurrent(Admin $admin, UrlImportJob $job): void
    {
        $snapshotJson = trim((string) ($job->resolved_ai_model_snapshot ?: $job->requested_ai_model_snapshot));
        $snapshot = json_decode($snapshotJson, true);
        $ownerId = is_array($snapshot) ? (int) ($snapshot['owner_admin_id'] ?? 0) : 0;
        if ($ownerId <= 0) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }
        if ($ownerId === (int) $admin->getKey()) {
            return;
        }
        if ($admin->isSuperAdmin() || (int) ($admin->shared_ai_config_owner_id ?? 0) !== $ownerId) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        $owner = $this->lockWhenTransactional(Admin::query()->whereKey($ownerId))->first();
        if (! $owner instanceof Admin || (string) $owner->status !== 'active') {
            throw AiModelAccessException::configOwnerInactive($admin, $ownerId);
        }
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
                'resolved_ai_model_snapshot' => $this->safeModelSnapshot($model),
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

    private function safeModelSnapshot(AiModel $model): string
    {
        return json_encode([
            'id' => (int) $model->getKey(),
            'owner_admin_id' => (int) $model->owner_admin_id,
            'name' => (string) $model->name,
            'version' => (string) $model->version,
            'model_type' => (string) $model->model_type,
            'access_scope' => (string) $model->access_scope,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
