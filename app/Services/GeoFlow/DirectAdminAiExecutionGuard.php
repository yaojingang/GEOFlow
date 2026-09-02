<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Services\Admin\AdminAiModelAccessResolver;
use Illuminate\Support\Str;

final readonly class DirectAdminAiExecutionGuard
{
    public function __construct(
        private AiExecutionAccessGuard $accessGuard,
        private AdminAiModelAccessResolver $modelResolver,
        private AiExecutionContextFactory $contextFactory,
    ) {}

    public function freeze(
        Admin $admin,
        string $source,
        int $sourceId,
        string $capability = AiExecutionContext::CAPABILITY_CHAT,
        ?int $requestedModelId = null,
    ): DirectAdminAiExecutionContext {
        $context = new DirectAdminAiExecutionContext(
            adminId: (int) $admin->getKey(),
            adminRole: $this->contextFactory->normalizedRole($admin),
            accessVersion: max(1, (int) $admin->ai_config_access_version),
            policyVersion: AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            requestId: (string) Str::uuid(),
            source: $source,
            sourceId: max(1, $sourceId),
            capability: $capability,
            requestedModelId: $requestedModelId,
        );
        $this->assertCurrent($context);

        return $context;
    }

    public function assertCurrent(DirectAdminAiExecutionContext $context): Admin
    {
        return $this->accessGuard->assertPersistedAdminSnapshot($context->persistedAdminSnapshot());
    }

    public function assertModelCurrent(
        DirectAdminAiExecutionContext $context,
        AiModel|int $model,
        ?Admin $admin = null,
    ): AiModel {
        $admin ??= $this->assertCurrent($context);
        $currentModel = $this->accessGuard->assertModelForPersistedAdminSnapshot(
            $context->persistedAdminSnapshot(),
            $model,
            currentAdmin: $admin,
        );
        $modelType = trim((string) $currentModel->model_type);
        $compatible = $context->capability === AiExecutionContext::CAPABILITY_CHAT
            ? in_array($modelType, ['', AiExecutionContext::CAPABILITY_CHAT], true)
            : $modelType === $context->capability;
        if (! $compatible) {
            throw AiModelAccessException::modelNotAccessible($admin, $currentModel);
        }

        return $currentModel;
    }

    /** @return array{model:AiModel,source:string} */
    public function resolveModel(DirectAdminAiExecutionContext $context): array
    {
        $admin = $this->assertCurrent($context);
        $model = $context->requestedModelId === null
            ? $this->modelResolver->resolveCandidates($admin, $context->capability)->first()
            : $this->assertModelCurrent($context, $context->requestedModelId, $admin);
        if (! $model instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($admin);
        }
        $model = $this->assertModelCurrent($context, $model, $admin);

        return [
            'model' => $model,
            'source' => (int) $model->owner_admin_id === (int) $admin->getKey() ? 'personal' : 'shared',
        ];
    }
}
