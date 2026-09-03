<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\EnterpriseKnowledgeProject;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnterpriseKnowledgeAiExecutionGuard
{
    public const EXECUTION_LEASE_SECONDS = 600;

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

    /** @return array{project:EnterpriseKnowledgeProject,claimed:bool,fence:?EnterpriseKnowledgeExecutionFence} */
    public function claim(
        EnterpriseKnowledgeProject $project,
        ?string $claimLeaseToken = null,
    ): array {
        $claimLeaseToken = is_string($claimLeaseToken) && Str::isUuid($claimLeaseToken)
            ? $claimLeaseToken
            : (string) Str::uuid();

        return DB::transaction(function () use ($project, $claimLeaseToken): array {
            $lockedProject = EnterpriseKnowledgeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $status = (string) $lockedProject->status;
            $lease = trim((string) ($lockedProject->execution_lease_token ?? ''));
            $leaseExpired = $lockedProject->lease_expires_at !== null
                && $lockedProject->lease_expires_at->isPast();
            $claimable = match ($status) {
                'queued' => $lease === '',
                'failed' => (bool) $lockedProject->retryable_failure && ($lease === '' || $leaseExpired),
                'processing' => $lease === '' || $leaseExpired,
                default => false,
            };
            if (! $claimable) {
                return ['project' => $lockedProject, 'claimed' => false, 'fence' => null];
            }

            $lockedProject->forceFill([
                'status' => 'processing',
                'execution_lease_token' => $claimLeaseToken,
                'execution_attempt' => (int) $lockedProject->execution_attempt + 1,
                'lease_expires_at' => now()->addSeconds(self::EXECUTION_LEASE_SECONDS),
                'error_code' => null,
                'error_message' => null,
                'retryable_failure' => true,
            ])->save();

            return [
                'project' => $lockedProject,
                'claimed' => true,
                'fence' => EnterpriseKnowledgeExecutionFence::fromProject($lockedProject),
            ];
        }, 3);
    }

    public function assertCurrent(
        EnterpriseKnowledgeProject $project,
        AiModel|int|null $model = null,
        ?EnterpriseKnowledgeExecutionFence $fence = null,
    ): Admin {
        $fence ??= EnterpriseKnowledgeExecutionFence::fromProject($project);
        $currentProject = $this->lockWhenTransactional(
            EnterpriseKnowledgeProject::query()->whereKey($project->getKey()),
        )->first();
        $expectedLease = $fence->leaseToken;
        $currentLease = trim((string) ($currentProject?->execution_lease_token ?? ''));
        if ($expectedLease === ''
            || (string) ($currentProject?->status ?? '') !== 'processing'
            || $currentLease === ''
            || ! hash_equals($expectedLease, $currentLease)
            || (int) ($currentProject?->execution_attempt ?? 0) !== $fence->executionAttempt
            || $currentProject?->lease_expires_at === null
            || $currentProject->lease_expires_at->isPast()) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($project->model_access_admin_id ?? 0),
            );
        }

        return $this->assertIdentityCurrent($currentProject, $model);
    }

    public function claimedExecutionIsCurrent(
        EnterpriseKnowledgeProject $project,
        ?EnterpriseKnowledgeExecutionFence $fence = null,
    ): bool {
        $fence ??= EnterpriseKnowledgeExecutionFence::fromProject($project);
        $currentProject = EnterpriseKnowledgeProject::query()->whereKey($project->getKey())->first();
        $expectedLease = $fence->leaseToken;

        return $expectedLease !== ''
            && $currentProject instanceof EnterpriseKnowledgeProject
            && (string) $currentProject->status === 'processing'
            && hash_equals($expectedLease, trim((string) $currentProject->execution_lease_token))
            && $currentProject->lease_expires_at !== null
            && ! $currentProject->lease_expires_at->isPast()
            && (int) $currentProject->execution_attempt === $fence->executionAttempt
            && (int) $currentProject->model_access_admin_id === (int) $project->model_access_admin_id
            && (string) $currentProject->model_access_admin_role === (string) $project->model_access_admin_role
            && (int) $currentProject->ai_config_access_version === (int) $project->ai_config_access_version
            && (int) $currentProject->requested_ai_model_id === (int) $project->requested_ai_model_id
            && (int) $currentProject->resolver_policy_version === (int) $project->resolver_policy_version;
    }

    /** @return Collection<int, AiModel> */
    public function resolveCandidates(
        EnterpriseKnowledgeProject $project,
        ?EnterpriseKnowledgeExecutionFence $fence = null,
    ): Collection {
        $requestedModelId = (int) ($project->requested_ai_model_id ?? 0);
        $admin = $this->assertCurrent(
            $project,
            $requestedModelId > 0 ? $requestedModelId : null,
            $fence,
        );
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

    public function recordResolvedModel(
        EnterpriseKnowledgeProject $project,
        AiModel $model,
        ?EnterpriseKnowledgeExecutionFence $fence = null,
    ): void {
        $fence ??= EnterpriseKnowledgeExecutionFence::fromProject($project);
        $resolvedAttributes = DB::transaction(function () use ($project, $model, $fence): array {
            $lockedProject = EnterpriseKnowledgeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $admin = $this->assertCurrent($project, $model, $fence);
            $attributes = [
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_ai_model_snapshot' => $this->safeModelSnapshot($model),
                'resolved_model_source' => (int) $model->owner_admin_id === (int) $admin->getKey()
                    ? 'personal'
                    : 'shared',
                'model_resolved_at' => now(),
            ];
            $lockedProject->forceFill($attributes)->save();

            return $attributes;
        }, 3);

        $project->forceFill($resolvedAttributes);
    }

    public function heartbeat(
        EnterpriseKnowledgeProject $project,
        AiModel|int $model,
        ?EnterpriseKnowledgeExecutionFence $fence = null,
    ): void {
        $fence ??= EnterpriseKnowledgeExecutionFence::fromProject($project);
        DB::transaction(function () use ($project, $model, $fence): void {
            $lockedProject = EnterpriseKnowledgeProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCurrent($project, $model, $fence);
            $lockedProject->forceFill([
                'lease_expires_at' => now()->addSeconds(self::EXECUTION_LEASE_SECONDS),
            ])->save();
            $project->forceFill([
                'lease_expires_at' => $lockedProject->lease_expires_at,
            ]);
        }, 3);
    }

    private function assertIdentityCurrent(
        ?EnterpriseKnowledgeProject $currentProject,
        AiModel|int|null $model,
    ): Admin {
        $adminId = (int) ($currentProject?->model_access_admin_id ?? 0);
        $storedRole = trim((string) ($currentProject?->model_access_admin_role ?? ''));
        $storedAccessVersion = (int) ($currentProject?->ai_config_access_version ?? 0);
        $storedPolicyVersion = (int) ($currentProject?->resolver_policy_version ?? 0);
        $requestedModelId = (int) ($currentProject?->requested_ai_model_id ?? 0);
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
        if ($storedRole !== $currentRole
            || $storedAccessVersion !== max(1, (int) $admin->ai_config_access_version)) {
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
