<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Data\Ai\AiExecutionContext;
use App\Data\Ai\KnowledgeFactGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeFactGenerationRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\AiExecutionAccessGuard;
use App\Services\GeoFlow\AiExecutionContextFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class KnowledgeFactGenerationAiExecutionGuard
{
    public function __construct(
        private AdminAiModelAccessResolver $modelAccessResolver,
        private AiExecutionAccessGuard $accessGuard,
        private AiExecutionContextFactory $contextFactory,
    ) {}

    /** @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,requested_ai_model_id:int,resolver_policy_version:int} */
    public function snapshotForCreation(Admin $actor, AiModel|int $requestedModel): array
    {
        $admin = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($actor);
        }

        $modelId = $requestedModel instanceof AiModel
            ? (int) $requestedModel->getKey()
            : $requestedModel;
        $model = AiModel::query()->whereKey($modelId)->lockForUpdate()->first();
        if (! $model instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
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

    public function identityComplete(KnowledgeFactGenerationRun $run): bool
    {
        return (int) ($run->model_access_admin_id ?? 0) > 0
            && in_array((string) ($run->model_access_admin_role ?? ''), ['admin', 'super_admin'], true)
            && (int) ($run->ai_config_access_version ?? 0) > 0
            && (int) ($run->requested_ai_model_id ?? 0) > 0
            && (int) ($run->resolver_policy_version ?? 0) === AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION;
    }

    public function identityMalformed(KnowledgeFactGenerationRun $run): bool
    {
        $hasIdentity = (int) ($run->model_access_admin_id ?? 0) > 0
            || trim((string) ($run->model_access_admin_role ?? '')) !== ''
            || (int) ($run->ai_config_access_version ?? 0) > 0
            || (int) ($run->requested_ai_model_id ?? 0) > 0
            || (int) ($run->resolver_policy_version ?? 0) > 0;

        return $hasIdentity && ! $this->identityComplete($run);
    }

    public function claimBatch(
        int $runId,
        int $batchSequence,
        string $inputHash,
        int $executionAttempt,
        string $claimToken,
        ?string $newLeaseToken = null,
    ): ?KnowledgeFactGenerationExecutionContext {
        return DB::transaction(function () use ($runId, $batchSequence, $inputHash, $executionAttempt, $claimToken, $newLeaseToken): ?KnowledgeFactGenerationExecutionContext {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof KnowledgeFactGenerationRun
                || ! $run->isActive()
                || $run->cancel_requested_at !== null
                || (int) $run->execution_attempt !== $executionAttempt) {
                return null;
            }
            if (! $this->identityComplete($run)) {
                throw AiModelAccessException::configAccessRevokedForAdminId(
                    (int) ($run->model_access_admin_id ?? 0),
                );
            }

            $claims = (array) $run->batch_claims_json;
            $claim = (array) ($claims[(string) $batchSequence] ?? []);
            if (($claim['input_hash'] ?? null) !== $inputHash
                || (int) ($claim['execution_attempt'] ?? 0) !== $executionAttempt
                || in_array((string) ($claim['status'] ?? ''), ['completed', 'failed'], true)) {
                return null;
            }
            $registeredToken = (string) ($claim['dispatch_token'] ?? '');
            if ($registeredToken === '' || $claimToken === '' || ! hash_equals($registeredToken, $claimToken)) {
                return null;
            }
            if (($claim['status'] ?? null) === 'running') {
                $expiresAt = isset($claim['lease_expires_at'])
                    ? now()->parse((string) $claim['lease_expires_at'])
                    : null;
                if ($expiresAt?->isFuture() === true) {
                    return null;
                }
            } elseif (($claim['status'] ?? null) !== 'queued') {
                return null;
            }

            $attemptCount = (int) ($claim['attempt_count'] ?? 0) + 1;
            if ($attemptCount > (int) config('geoflow.knowledge_fact_generation_max_batch_attempts', 3)) {
                return null;
            }
            $leaseToken = $newLeaseToken ?? (string) Str::uuid7();
            if ($leaseToken === '' || hash_equals((string) ($claim['lease_token'] ?? ''), $leaseToken)) {
                return null;
            }
            $claim['status'] = 'running';
            $claim['lease_token'] = $leaseToken;
            $claim['lease_expires_at'] = now()
                ->addSeconds((int) config('geoflow.knowledge_fact_generation_batch_lease_seconds', 210))
                ->toIso8601String();
            $claim['attempt_count'] = $attemptCount;
            $claims[(string) $batchSequence] = $claim;
            $run->forceFill(['batch_claims_json' => $claims])->save();

            return KnowledgeFactGenerationExecutionContext::fromClaimedRun(
                $run,
                $batchSequence,
                $inputHash,
                $leaseToken,
            );
        }, 3);
    }

    public function releaseBatchForRetry(
        KnowledgeFactGenerationExecutionContext $context,
        bool $refundAttempt = false,
    ): void {
        DB::transaction(function () use ($context, $refundAttempt): void {
            $run = $this->assertRunLeaseCurrent($context);
            $claims = (array) $run->batch_claims_json;
            $claim = (array) ($claims[(string) $context->batchSequence] ?? []);
            $claim['status'] = 'queued';
            if ($refundAttempt) {
                $claim['attempt_count'] = max(0, (int) $claim['attempt_count'] - 1);
            }
            $claim['lease_token'] = null;
            $claim['lease_expires_at'] = null;
            unset(
                $claim['resolved_ai_model_id'],
                $claim['resolved_model_source'],
                $claim['model_resolved_at'],
            );
            $claims[(string) $context->batchSequence] = $claim;
            $run->forceFill(['batch_claims_json' => $claims])->save();
        }, 3);
    }

    public function assertCurrent(
        KnowledgeFactGenerationExecutionContext $context,
        AiModel|int|null $model = null,
    ): Admin {
        $run = $this->assertRunLeaseCurrent($context);
        $snapshot = $this->snapshot($run);
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

    public function assertFrozenIdentityCurrent(
        KnowledgeFactGenerationRun $run,
        AiModel|int|null $model = null,
    ): Admin {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($run->model_access_admin_id ?? 0),
            );
        }
        $snapshot = $this->snapshot($run);
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

    /** @return Collection<int,AiModel> */
    public function resolveCandidates(KnowledgeFactGenerationExecutionContext $context): Collection
    {
        $admin = $this->assertCurrent($context, $context->requestedModelId);
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

    public function registerCandidate(
        KnowledgeFactGenerationExecutionContext $context,
        AiModel|int $model,
    ): AiModel {
        return DB::transaction(function () use ($context, $model): AiModel {
            $run = $this->assertRunLeaseCurrent($context);
            $snapshot = $this->snapshot($run);
            $admin = $this->accessGuard->assertPersistedAdminSnapshot($snapshot);
            $currentModel = $this->accessGuard->assertModelForPersistedAdminSnapshot(
                $snapshot,
                $model,
                currentAdmin: $admin,
            );
            $source = (int) $currentModel->owner_admin_id === (int) $admin->getKey()
                ? 'personal'
                : 'shared';
            $claims = (array) $run->batch_claims_json;
            $claim = (array) $claims[(string) $context->batchSequence];
            $claim['resolved_ai_model_id'] = (int) $currentModel->getKey();
            $claim['resolved_model_source'] = $source;
            $claim['model_resolved_at'] = now()->toIso8601String();
            $claim['lease_expires_at'] = now()
                ->addSeconds((int) config('geoflow.knowledge_fact_generation_batch_lease_seconds', 210))
                ->toIso8601String();
            $claims[(string) $context->batchSequence] = $claim;
            $run->forceFill(['batch_claims_json' => $claims])->save();

            return $currentModel;
        }, 3);
    }

    private function assertRunLeaseCurrent(
        KnowledgeFactGenerationExecutionContext $context,
    ): KnowledgeFactGenerationRun {
        $run = $this->lockWhenTransactional(
            KnowledgeFactGenerationRun::query()->whereKey($context->runId),
        )->first();
        $claim = (array) data_get($run?->batch_claims_json, (string) $context->batchSequence, []);
        $expiresAt = isset($claim['lease_expires_at'])
            ? now()->parse((string) $claim['lease_expires_at'])
            : null;
        if (! $run instanceof KnowledgeFactGenerationRun
            || ! $run->isActive()
            || $run->cancel_requested_at !== null
            || (int) $run->execution_attempt !== $context->executionAttempt
            || ! $this->identityComplete($run)
            || (int) $run->model_access_admin_id !== $context->modelAccessAdminId
            || (string) $run->model_access_admin_role !== $context->modelAccessAdminRole
            || (int) $run->ai_config_access_version !== $context->aiConfigAccessVersion
            || (int) $run->requested_ai_model_id !== $context->requestedModelId
            || (int) $run->resolver_policy_version !== $context->resolverPolicyVersion
            || ($claim['status'] ?? null) !== 'running'
            || ($claim['input_hash'] ?? null) !== $context->inputHash
            || (int) ($claim['attempt_count'] ?? 0) !== $context->batchAttempt
            || (string) ($claim['lease_token'] ?? '') === ''
            || ! hash_equals($context->leaseToken(), (string) $claim['lease_token'])
            || $expiresAt === null
            || $expiresAt->isPast()) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        return $run;
    }

    /** @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,resolver_policy_version:int} */
    private function snapshot(KnowledgeFactGenerationRun $run): array
    {
        return [
            'model_access_admin_id' => (int) $run->model_access_admin_id,
            'model_access_admin_role' => (string) $run->model_access_admin_role,
            'ai_config_access_version' => (int) $run->ai_config_access_version,
            'resolver_policy_version' => (int) $run->resolver_policy_version,
        ];
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
