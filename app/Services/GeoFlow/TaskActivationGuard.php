<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Task;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class TaskActivationGuard
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $modelAccessResolver,
        private readonly AiExecutionContextFactory $contextFactory,
    ) {}

    /** @param array<string,mixed> $overrides */
    public function assertCanActivate(
        Task $task,
        Admin|int|null $operator = null,
        array $overrides = [],
    ): void {
        $operatorAdmin = $this->resolveAdmin($operator, 'operator');
        $executionAdminId = (int) ($task->model_access_admin_id ?? 0);
        $storedRole = trim((string) ($task->model_access_admin_role ?? ''));
        $storedPolicyVersion = (int) ($task->model_access_policy_version ?? 0);

        if ($executionAdminId <= 0 || $storedRole === '' || $storedPolicyVersion <= 0) {
            if ($this->contextFactory->identityRequired() || $executionAdminId > 0 || $storedRole !== '') {
                throw new ApiException(
                    AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                    '任务 AI 执行身份已失效',
                    409,
                );
            }

            return;
        }

        $executionAdmin = $this->resolveAdmin($executionAdminId, 'execution');
        if (! $executionAdmin instanceof Admin
            || $this->contextFactory->normalizedRole($executionAdmin) !== $storedRole
            || $storedPolicyVersion !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw new ApiException(
                AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                '任务 AI 执行身份已失效',
                409,
            );
        }

        $this->assertModelsUsable($executionAdmin, $task, $overrides);
        if ($operatorAdmin instanceof Admin && (int) $operatorAdmin->id !== (int) $executionAdmin->id) {
            $this->assertModelsUsable($operatorAdmin, $task, $overrides);
        }
    }

    private function resolveAdmin(Admin|int|null $admin, string $kind): ?Admin
    {
        $adminId = $admin instanceof Admin ? (int) $admin->id : (int) ($admin ?? 0);
        if ($adminId <= 0) {
            if ($kind === 'operator' && ! $this->contextFactory->identityRequired()) {
                return null;
            }

            throw new ApiException(
                AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
                'AI 执行管理员不可用',
                409,
            );
        }

        $current = $this->lockWhenTransactional(Admin::query()->whereKey($adminId))->first();
        if (! $current instanceof Admin || (string) $current->status !== 'active') {
            throw new ApiException(
                AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
                'AI 执行管理员不可用',
                409,
            );
        }

        return $current;
    }

    /** @param array<string,mixed> $overrides */
    private function assertModelsUsable(Admin $admin, Task $task, array $overrides): void
    {
        $contentModelId = (int) ($overrides['ai_model_id'] ?? $task->ai_model_id ?? 0);
        $qualityEnabled = array_key_exists('ai_quality_enabled', $overrides)
            ? (bool) $overrides['ai_quality_enabled']
            : (bool) $task->ai_quality_enabled;
        $qualityModelId = (int) ($overrides['ai_quality_model_id'] ?? $task->ai_quality_model_id ?? 0);
        if ($qualityModelId <= 0) {
            $qualityModelId = $contentModelId;
        }

        $models = ['ai_model_id' => $contentModelId];
        if ($qualityEnabled) {
            $models['ai_quality_model_id'] = $qualityModelId;
        }
        foreach ($models as $field => $modelId) {
            $model = $modelId > 0
                ? $this->lockWhenTransactional(AiModel::query()->whereKey($modelId))->first()
                : null;
            if (! $model instanceof AiModel
                || ! in_array((string) ($model->model_type ?? ''), ['', 'chat'], true)) {
                throw $this->modelException(
                    AiModelAccessException::AI_MODEL_UNAVAILABLE,
                    $field,
                    '选择的 AI 模型当前不可用',
                    409,
                );
            }

            try {
                $this->modelAccessResolver->assertLockedUsable($admin, $model);
            } catch (AiModelAccessException $exception) {
                $notAccessible = $exception->getErrorCode() === AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE;

                throw $this->modelException(
                    $exception->getErrorCode(),
                    $field,
                    $notAccessible ? '选择的 AI 模型不可访问' : '选择的 AI 模型当前不可用',
                    $notAccessible ? 404 : 409,
                );
            }
        }
    }

    private function modelException(string $code, string $field, string $message, int $status): ApiException
    {
        return new ApiException($code, $message, $status, [
            'field_errors' => [$field => $message],
        ]);
    }

    private function lockWhenTransactional(Builder $query): Builder
    {
        return DB::transactionLevel() > 0 ? $query->lockForUpdate() : $query;
    }
}
