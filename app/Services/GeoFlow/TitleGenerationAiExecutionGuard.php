<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Data\Ai\TitleGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\TitleGenerationRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class TitleGenerationAiExecutionGuard
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $modelAccessResolver,
        private readonly AiExecutionAccessGuard $accessGuard,
        private readonly AiExecutionContextFactory $contextFactory,
    ) {}

    /** @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,requested_ai_model_id:int,resolver_policy_version:int} */
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
            'model_access_admin_role' => $this->contextFactory->normalizedRole($admin),
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'requested_ai_model_id' => (int) $model->getKey(),
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    public function identityComplete(TitleGenerationRun $run): bool
    {
        return (int) ($run->model_access_admin_id ?? 0) > 0
            && in_array((string) ($run->model_access_admin_role ?? ''), ['admin', 'super_admin'], true)
            && (int) ($run->ai_config_access_version ?? 0) > 0
            && (int) ($run->requested_ai_model_id ?? 0) > 0
            && (int) ($run->resolver_policy_version ?? 0) === AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION;
    }

    public function identityRequired(): bool
    {
        return $this->contextFactory->identityRequired();
    }

    public function identityMalformed(TitleGenerationRun $run): bool
    {
        $hasPersistedIdentity = (int) ($run->model_access_admin_id ?? 0) > 0
            || trim((string) ($run->model_access_admin_role ?? '')) !== ''
            || (int) ($run->ai_config_access_version ?? 0) > 0
            || (int) ($run->requested_ai_model_id ?? 0) > 0
            || (int) ($run->resolver_policy_version ?? 0) > 0;

        return $hasPersistedIdentity && ! $this->identityComplete($run);
    }

    public function contextFromClaimedRun(TitleGenerationRun $run): TitleGenerationExecutionContext
    {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($run->model_access_admin_id ?? 0),
            );
        }

        return TitleGenerationExecutionContext::fromClaimedRun($run);
    }

    public function assertFrozenIdentityCurrent(TitleGenerationRun $run, AiModel|int|null $model = null): Admin
    {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($run->model_access_admin_id ?? 0),
            );
        }

        $snapshot = [
            'model_access_admin_id' => (int) $run->model_access_admin_id,
            'model_access_admin_role' => (string) $run->model_access_admin_role,
            'ai_config_access_version' => (int) $run->ai_config_access_version,
            'resolver_policy_version' => (int) $run->resolver_policy_version,
        ];
        $admin = $this->accessGuard->assertPersistedAdminSnapshot($snapshot);
        if ($model !== null) {
            $this->accessGuard->assertModelForPersistedAdminSnapshot(
                $snapshot,
                $model,
                currentAdmin: $admin,
            );
        }

        return $admin;
    }

    public function assertCurrent(
        TitleGenerationExecutionContext $context,
        AiModel|int|null $model = null,
    ): Admin {
        $run = $this->lockWhenTransactional(
            TitleGenerationRun::query()->whereKey($context->runId),
        )->first();
        if (! $run instanceof TitleGenerationRun
            || $run->status !== TitleGenerationRun::STATUS_RUNNING
            || (int) $run->batch_sequence !== $context->batchSequence
            || trim((string) $run->lease_token) === ''
            || ! hash_equals($context->leaseToken(), (string) $run->lease_token)
            || $run->lease_expires_at === null
            || $run->lease_expires_at->isPast()
            || (int) $run->model_access_admin_id !== $context->modelAccessAdminId
            || (string) $run->model_access_admin_role !== $context->modelAccessAdminRole
            || (int) $run->ai_config_access_version !== $context->aiConfigAccessVersion
            || (int) $run->requested_ai_model_id !== $context->requestedModelId
            || (int) $run->resolver_policy_version !== $context->resolverPolicyVersion) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        $snapshot = [
            'model_access_admin_id' => $context->modelAccessAdminId,
            'model_access_admin_role' => $context->modelAccessAdminRole,
            'ai_config_access_version' => $context->aiConfigAccessVersion,
            'resolver_policy_version' => $context->resolverPolicyVersion,
        ];
        $admin = $this->accessGuard->assertPersistedAdminSnapshot($snapshot);
        if ($model !== null) {
            $this->accessGuard->assertModelForPersistedAdminSnapshot(
                $snapshot,
                $model,
                currentAdmin: $admin,
            );
        }

        return $admin;
    }

    public function claimedExecutionIsCurrent(TitleGenerationExecutionContext $context): bool
    {
        $run = TitleGenerationRun::query()->whereKey($context->runId)->first();

        return $run instanceof TitleGenerationRun
            && $run->status === TitleGenerationRun::STATUS_RUNNING
            && (int) $run->batch_sequence === $context->batchSequence
            && trim((string) $run->lease_token) !== ''
            && hash_equals($context->leaseToken(), (string) $run->lease_token)
            && $run->lease_expires_at !== null
            && ! $run->lease_expires_at->isPast()
            && (int) $run->model_access_admin_id === $context->modelAccessAdminId
            && (string) $run->model_access_admin_role === $context->modelAccessAdminRole
            && (int) $run->ai_config_access_version === $context->aiConfigAccessVersion
            && (int) $run->requested_ai_model_id === $context->requestedModelId
            && (int) $run->resolver_policy_version === $context->resolverPolicyVersion;
    }

    /** @return Collection<int,AiModel> */
    public function resolveCandidates(TitleGenerationExecutionContext $context): Collection
    {
        $admin = $this->assertCurrent($context);
        $candidates = $this->modelAccessResolver->resolveCandidates($admin, 'chat');
        $requested = $candidates->first(
            static fn (AiModel $model): bool => (int) $model->getKey() === $context->requestedModelId,
        );
        if (! $requested instanceof AiModel) {
            $storedRequested = AiModel::query()->find($context->requestedModelId);
            if ($storedRequested instanceof AiModel) {
                throw AiModelAccessException::modelNotAccessible($admin, $storedRequested);
            }

            throw AiModelAccessException::modelUnavailable($admin);
        }

        return $candidates
            ->reject(static fn (AiModel $model): bool => (int) $model->getKey() === $context->requestedModelId)
            ->prepend($requested)
            ->values();
    }

    public function recordResolvedModel(TitleGenerationExecutionContext $context, AiModel $model): void
    {
        DB::transaction(function () use ($context, $model): void {
            $run = TitleGenerationRun::query()->whereKey($context->runId)->lockForUpdate()->firstOrFail();
            $admin = $this->assertCurrent($context, $model);
            $run->forceFill([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => (int) $model->owner_admin_id === (int) $admin->getKey()
                    ? 'personal'
                    : 'shared',
                'model_resolved_at' => now(),
            ])->save();
        }, 3);
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
