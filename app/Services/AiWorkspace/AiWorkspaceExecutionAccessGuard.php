<?php

namespace App\Services\AiWorkspace;

use App\Data\Ai\AiExecutionContext;
use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Data\Ai\AiWorkspaceModelExecutionReceipt;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\AiWorkspaceRun;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\AiExecutionAccessGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AiWorkspaceExecutionAccessGuard
{
    public function __construct(
        private AdminAiModelAccessResolver $modelAccessResolver,
        private AiExecutionAccessGuard $accessGuard,
    ) {}

    /** @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,requested_ai_model_id:?int,resolver_policy_version:int} */
    public function snapshotForCreation(Admin $actor): array
    {
        $admin = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($actor);
        }

        try {
            $candidates = $this->modelAccessResolver->resolveCandidates($admin, 'chat');
        } catch (AiModelAccessException) {
            $candidates = new Collection;
        }
        $requested = $this->preferredRequestedCandidate($admin, $candidates);

        return [
            'model_access_admin_id' => (int) $admin->getKey(),
            'model_access_admin_role' => AiWorkspaceExecutionContext::normalizedRole($admin),
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'requested_ai_model_id' => $requested instanceof AiModel ? (int) $requested->getKey() : null,
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ];
    }

    public function identityComplete(AiWorkspaceRun $run): bool
    {
        return (int) ($run->model_access_admin_id ?? 0) > 0
            && in_array((string) ($run->model_access_admin_role ?? ''), ['admin', 'super_admin'], true)
            && (int) ($run->ai_config_access_version ?? 0) > 0
            && (int) ($run->resolver_policy_version ?? 0) === AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION;
    }

    public function directContext(Admin $admin, ?int $requestedModelId = null, ?string $requestId = null): AiWorkspaceExecutionContext
    {
        $current = $this->assertSnapshot([
            'model_access_admin_id' => (int) $admin->getKey(),
            'model_access_admin_role' => AiWorkspaceExecutionContext::normalizedRole($admin),
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
        ]);

        if ($requestedModelId === null) {
            try {
                $candidates = $this->modelAccessResolver->resolveCandidates($current, 'chat');
            } catch (AiModelAccessException) {
                $candidates = new Collection;
            }
            $requestedModelId = $this->preferredRequestedCandidate($current, $candidates)?->getKey();
        }

        return AiWorkspaceExecutionContext::forDirectAdmin($current, $requestedModelId, $requestId);
    }

    public function contextFromResolutionRun(AiWorkspaceRun $run, string $leaseToken): AiWorkspaceExecutionContext
    {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId((int) ($run->model_access_admin_id ?? 0));
        }

        return AiWorkspaceExecutionContext::fromResolutionRun($run, $leaseToken);
    }

    public function contextFromExecutionRun(AiWorkspaceRun $run, string $leaseToken): AiWorkspaceExecutionContext
    {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId((int) ($run->model_access_admin_id ?? 0));
        }

        return AiWorkspaceExecutionContext::fromExecutionRun($run, $leaseToken);
    }

    public function assertFrozenRunAdmin(AiWorkspaceRun $run): Admin
    {
        if (! $this->identityComplete($run)) {
            throw AiModelAccessException::configAccessRevokedForAdminId((int) ($run->model_access_admin_id ?? 0));
        }

        return $this->assertSnapshot([
            'model_access_admin_id' => (int) $run->model_access_admin_id,
            'model_access_admin_role' => (string) $run->model_access_admin_role,
            'ai_config_access_version' => (int) $run->ai_config_access_version,
            'resolver_policy_version' => (int) $run->resolver_policy_version,
        ]);
    }

    public function assertCurrent(AiWorkspaceExecutionContext $context, AiModel|int|null $model = null): Admin
    {
        if ($context->runId !== null) {
            $run = $this->lockWhenTransactional(AiWorkspaceRun::query()->whereKey($context->runId))->first();
            if (! $run instanceof AiWorkspaceRun) {
                throw new AiWorkspaceRuntimeGuardException('AI 工作台运行已经结束或不存在。');
            }
            $leaseField = $context->leaseKind === 'resolution' ? 'resolution_lease_owner' : 'execution_lease_token';
            $expiresField = $context->leaseKind === 'resolution' ? 'resolution_lease_expires_at' : 'execution_lease_expires_at';
            $lease = trim((string) $run->{$leaseField});
            $allowedStates = $context->leaseKind === 'resolution'
                ? ['received', 'planning', 'validating_plan', 'answering']
                : ['running', 'cancel_requested'];
            if (! $this->identityComplete($run)
                || (int) $run->model_access_admin_id !== $context->modelAccessAdminId
                || (string) $run->model_access_admin_role !== $context->modelAccessAdminRole
                || (int) $run->ai_config_access_version !== $context->aiConfigAccessVersion
                || (int) $run->resolver_policy_version !== $context->resolverPolicyVersion) {
                throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
            }
            if (! in_array((string) $run->state, $allowedStates, true)
                || ! hash_equals((string) $context->leaseToken(), $lease)
                || $run->{$expiresField} === null
                || $run->{$expiresField}->isPast()) {
                throw new AiWorkspaceRuntimeGuardException('AI 工作台执行状态或租约已经变化。');
            }
        }

        $admin = $this->assertSnapshot($context->toSafeArray());
        if ($model !== null) {
            $currentModel = $this->accessGuard->assertModelForPersistedAdminSnapshot(
                $context->toSafeArray(),
                $model,
                currentAdmin: $admin,
            );
            if ($model instanceof AiModel
                && ! hash_equals($this->modelConfigurationDigest($model), $this->modelConfigurationDigest($currentModel))) {
                throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
            }
        }

        return $admin;
    }

    /** @return Collection<int,AiModel> */
    public function resolveCandidates(AiWorkspaceExecutionContext $context): Collection
    {
        $admin = $this->assertCurrent($context);
        $candidates = $this->modelAccessResolver->resolveCandidates($admin, 'chat');
        if ($context->requestedModelId === null) {
            return $candidates;
        }

        $requested = $candidates->first(
            static fn (AiModel $model): bool => (int) $model->getKey() === $context->requestedModelId,
        );
        if (! $requested instanceof AiModel) {
            $stored = AiModel::query()->find($context->requestedModelId);
            throw $stored instanceof AiModel
                ? AiModelAccessException::modelNotAccessible($admin, $stored)
                : AiModelAccessException::modelUnavailable($admin);
        }

        return $candidates
            ->reject(static fn (AiModel $model): bool => (int) $model->getKey() === $context->requestedModelId)
            ->prepend($requested)
            ->values();
    }

    public function receiptFor(
        AiWorkspaceExecutionContext $context,
        AiModel $model,
    ): AiWorkspaceModelExecutionReceipt {
        $admin = $this->assertCurrent($context, $model);

        return new AiWorkspaceModelExecutionReceipt(
            modelId: (int) $model->getKey(),
            modelSource: (int) $model->owner_admin_id === (int) $admin->getKey() ? 'personal' : 'shared',
            configurationDigest: $this->modelConfigurationDigest($model),
            requestId: $context->requestId,
        );
    }

    /** @return array{AiModel,AiWorkspaceModelExecutionReceipt} */
    public function claimModelForCall(
        AiWorkspaceExecutionContext $context,
        AiModel|int $model,
    ): array {
        return DB::transaction(function () use ($context, $model): array {
            $admin = $this->assertCurrent($context);
            $currentModel = $this->accessGuard->assertModelForPersistedAdminSnapshot(
                $context->toSafeArray(),
                $model instanceof AiModel ? (int) $model->getKey() : $model,
                currentAdmin: $admin,
            );
            $source = (int) $currentModel->owner_admin_id === (int) $admin->getKey()
                ? 'personal'
                : 'shared';

            if ($context->runId !== null) {
                $run = AiWorkspaceRun::query()->whereKey($context->runId)->lockForUpdate()->firstOrFail();
                $leaseExpiresField = $context->leaseKind === 'resolution'
                    ? 'resolution_lease_expires_at'
                    : 'execution_lease_expires_at';
                $minimumLeaseExpiry = now()->addSeconds($this->modelCallLeaseSeconds());
                $run->forceFill([
                    'resolved_ai_model_id' => (int) $currentModel->getKey(),
                    'resolved_model_source' => $source,
                    'model_resolved_at' => now(),
                    $leaseExpiresField => $run->{$leaseExpiresField}?->greaterThan($minimumLeaseExpiry)
                        ? $run->{$leaseExpiresField}
                        : $minimumLeaseExpiry,
                ])->save();
            }

            return [
                $currentModel,
                new AiWorkspaceModelExecutionReceipt(
                    modelId: (int) $currentModel->getKey(),
                    modelSource: $source,
                    configurationDigest: $this->modelConfigurationDigest($currentModel),
                    requestId: $context->requestId,
                ),
            ];
        }, 3);
    }

    public function assertReceiptCurrent(
        AiWorkspaceExecutionContext $context,
        AiWorkspaceModelExecutionReceipt $receipt,
    ): Admin {
        if (! hash_equals($context->requestId, $receipt->requestId)) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        $admin = $this->assertCurrent($context);
        $model = $this->accessGuard->assertModelForPersistedAdminSnapshot(
            $context->toSafeArray(),
            $receipt->modelId,
            currentAdmin: $admin,
        );
        $expectedSource = (int) $model->owner_admin_id === (int) $admin->getKey() ? 'personal' : 'shared';
        if (! hash_equals($expectedSource, $receipt->modelSource)
            || ! hash_equals($this->modelConfigurationDigest($model), $receipt->configurationDigest)) {
            throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
        }

        return $admin;
    }

    public function recordResolvedModel(AiWorkspaceExecutionContext $context, AiModel $model): void
    {
        if ($context->runId === null) {
            $this->assertCurrent($context, $model);

            return;
        }

        DB::transaction(function () use ($context, $model): void {
            $admin = $this->assertCurrent($context, $model);
            $run = AiWorkspaceRun::query()->whereKey($context->runId)->lockForUpdate()->firstOrFail();
            $run->forceFill([
                'resolved_ai_model_id' => $model->getKey(),
                'resolved_model_source' => (int) $model->owner_admin_id === (int) $admin->getKey()
                    ? 'personal'
                    : 'shared',
                'model_resolved_at' => now(),
            ])->save();
        }, 3);
    }

    /** @param array<string,mixed> $snapshot */
    private function assertSnapshot(array $snapshot): Admin
    {
        return $this->accessGuard->assertPersistedAdminSnapshot($snapshot);
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }

    /** @param Collection<int,AiModel> $candidates */
    private function preferredRequestedCandidate(Admin $admin, Collection $candidates): ?AiModel
    {
        $personal = $candidates->filter(
            static fn (AiModel $model): bool => (int) $model->owner_admin_id === (int) $admin->getKey(),
        )->values();
        $configuredId = (int) (AdminAiSetting::query()
            ->where('admin_id', $admin->getKey())
            ->value('default_chat_model_id') ?? 0);
        $configured = $configuredId > 0
            ? $candidates->first(static fn (AiModel $model): bool => (int) $model->getKey() === $configuredId)
            : null;

        if ($configured instanceof AiModel
            && (int) $configured->owner_admin_id === (int) $admin->getKey()) {
            return $configured;
        }

        return $personal->first() ?? ($configured instanceof AiModel ? $configured : $candidates->first());
    }

    private function modelConfigurationDigest(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'version' => trim((string) $model->version),
            'model_id' => trim((string) $model->model_id),
            'model_type' => trim((string) $model->model_type),
            'api_url' => trim((string) $model->api_url),
            'api_key' => (string) $model->getRawOriginal('api_key'),
            'status' => trim((string) $model->status),
            'archived_at' => $model->archived_at?->toISOString(),
            'max_tokens' => $model->max_tokens,
        ], JSON_THROW_ON_ERROR));
    }

    private function modelCallLeaseSeconds(): int
    {
        return max(
            60,
            (int) config('ai-workspace.model_total_timeout_seconds', 90) + 30,
            (int) config('ai-workspace.model_attempt_timeout_seconds', 30) + 30,
        );
    }
}
