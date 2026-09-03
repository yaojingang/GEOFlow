<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class AiModelUsageAccessSnapshot
{
    private function __construct(
        public string $requestId,
        public string $requestPayloadDigest,
        public int $aiModelId,
        public int $configOwnerAdminId,
        public ?int $executionAdminId,
        public int $aiConfigAccessVersion,
        public string $executionScope,
        public string $modelSource,
    ) {}

    public static function capture(
        AiModel $model,
        ?Admin $executionAdmin,
        string $executionScope,
        string $modelSource,
        int $aiConfigAccessVersion,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        Validator::make([
            'request_id' => $requestId,
            'request_payload_digest' => $requestPayloadDigest,
            'ai_config_access_version' => $aiConfigAccessVersion,
            'execution_scope' => $executionScope,
            'model_source' => $modelSource,
        ], [
            'request_id' => [
                'required',
                'string',
                'max:36',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || (! Str::isUuid($value) && ! Str::isUlid($value))) {
                        $fail('ai_usage_request_id_invalid');
                    }
                },
            ],
            'request_payload_digest' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'ai_config_access_version' => ['required', 'integer', 'min:0'],
            'execution_scope' => ['required', Rule::in([
                AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
                AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            ])],
            'model_source' => ['required', Rule::in([
                AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
                AiModelUsageEvent::MODEL_SOURCE_SHARED,
                AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            ])],
        ])->validate();

        $currentModel = AiModel::query()->find($model->getKey(), [
            'id',
            'owner_admin_id',
            'access_scope',
            'status',
            'archived_at',
        ]);
        if (! $currentModel instanceof AiModel
            || (string) $currentModel->status !== 'active'
            || $currentModel->archived_at !== null
            || $currentModel->owner_admin_id === null) {
            self::fail('ai_model_id', 'ai_model_unavailable');
        }

        $owner = Admin::query()->find($currentModel->owner_admin_id, [
            'id',
            'role',
            'status',
        ]);
        if (! $owner instanceof Admin || (string) $owner->status !== 'active') {
            self::fail('config_owner_admin_id', 'ai_config_owner_inactive');
        }

        $executor = $executionAdmin instanceof Admin
            ? Admin::query()->find($executionAdmin->getKey(), [
                'id',
                'role',
                'status',
                'shared_ai_config_owner_id',
                'ai_config_access_version',
            ])
            : null;

        return match ($modelSource) {
            AiModelUsageEvent::MODEL_SOURCE_PERSONAL => self::personal(
                $currentModel,
                $owner,
                $executor,
                $executionScope,
                $aiConfigAccessVersion,
                $requestId,
                $requestPayloadDigest,
            ),
            AiModelUsageEvent::MODEL_SOURCE_SHARED => self::shared(
                $currentModel,
                $owner,
                $executor,
                $executionScope,
                $aiConfigAccessVersion,
                $requestId,
                $requestPayloadDigest,
            ),
            AiModelUsageEvent::MODEL_SOURCE_SYSTEM => self::system(
                $currentModel,
                $owner,
                $executor,
                $executionScope,
                $aiConfigAccessVersion,
                $requestId,
                $requestPayloadDigest,
            ),
        };
    }

    public static function captureForGovernanceTest(
        AiModel $model,
        AdminAiModelTestSnapshot $testSnapshot,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        self::validateCommonInput(
            requestId: $requestId,
            requestPayloadDigest: $requestPayloadDigest,
            accessVersion: $testSnapshot->adminAccessVersion,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: $testSnapshot->accessScope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY
                ? AiModelUsageEvent::MODEL_SOURCE_SYSTEM
                : AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
        );

        $actor = Admin::query()->find($testSnapshot->adminId, [
            'id',
            'role',
            'status',
            'ai_config_access_version',
        ]);
        $currentModel = AiModel::query()->find($model->getKey());
        if (! $actor instanceof Admin
            || (string) $actor->status !== 'active'
            || (int) $actor->ai_config_access_version !== $testSnapshot->adminAccessVersion
            || ! $currentModel instanceof AiModel
            || (int) $currentModel->getKey() !== $testSnapshot->modelId
            || (int) $currentModel->owner_admin_id !== (int) $actor->getKey()
            || (int) $currentModel->owner_admin_id !== $testSnapshot->ownerAdminId
            || $currentModel->archived_at !== null
            || (string) $currentModel->access_scope !== $testSnapshot->accessScope
            || (string) $currentModel->status !== $testSnapshot->status
            || ! hash_equals(
                $testSnapshot->configurationDigest,
                AiModelTestConfigurationDigest::forModel($currentModel),
            )) {
            self::fail('model_source', 'ai_usage_governance_attribution_invalid');
        }

        $modelSource = match ((string) $currentModel->access_scope) {
            AiModel::ACCESS_SCOPE_USER_CONTENT => AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
            AiModel::ACCESS_SCOPE_SYSTEM_ONLY => $actor->isSuperAdmin()
                ? AiModelUsageEvent::MODEL_SOURCE_SYSTEM
                : self::fail('model_source', 'ai_usage_governance_attribution_invalid'),
            default => self::fail('model_source', 'ai_usage_governance_attribution_invalid'),
        };

        return self::make(
            model: $currentModel,
            owner: $actor,
            executor: $actor,
            accessVersion: $testSnapshot->adminAccessVersion,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: $modelSource,
            requestId: $requestId,
            requestPayloadDigest: $requestPayloadDigest,
        );
    }

    /** @return array<string, int|string|null> */
    public function toUsageAttributes(): array
    {
        return [
            'request_id' => $this->requestId,
            'request_payload_digest' => $this->requestPayloadDigest,
            'ai_model_id' => $this->aiModelId,
            'config_owner_admin_id' => $this->configOwnerAdminId,
            'execution_admin_id' => $this->executionAdminId,
            'ai_config_access_version' => $this->aiConfigAccessVersion,
            'execution_scope' => $this->executionScope,
            'model_source' => $this->modelSource,
        ];
    }

    private static function personal(
        AiModel $model,
        Admin $owner,
        ?Admin $executor,
        string $executionScope,
        int $accessVersion,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        self::assertAdminScope($executionScope);
        if ((string) $model->access_scope !== AiModel::ACCESS_SCOPE_USER_CONTENT
            || ! $executor instanceof Admin
            || (string) $executor->status !== 'active'
            || (int) $executor->getKey() !== (int) $owner->getKey()
            || $accessVersion < 1
            || (int) $executor->ai_config_access_version !== $accessVersion) {
            self::fail('model_source', 'ai_usage_personal_attribution_invalid');
        }

        return self::make(
            $model,
            $owner,
            $executor,
            $accessVersion,
            $executionScope,
            AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
            $requestId,
            $requestPayloadDigest,
        );
    }

    private static function shared(
        AiModel $model,
        Admin $owner,
        ?Admin $executor,
        string $executionScope,
        int $accessVersion,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        self::assertAdminScope($executionScope);
        if ((string) $model->access_scope !== AiModel::ACCESS_SCOPE_USER_CONTENT
            || ! $owner->isSuperAdmin()
            || ! $executor instanceof Admin
            || (string) $executor->status !== 'active'
            || $executor->isSuperAdmin()
            || (int) $executor->shared_ai_config_owner_id !== (int) $owner->getKey()
            || $accessVersion < 1
            || (int) $executor->ai_config_access_version !== $accessVersion) {
            self::fail('model_source', 'ai_usage_shared_attribution_invalid');
        }

        return self::make(
            $model,
            $owner,
            $executor,
            $accessVersion,
            $executionScope,
            AiModelUsageEvent::MODEL_SOURCE_SHARED,
            $requestId,
            $requestPayloadDigest,
        );
    }

    private static function system(
        AiModel $model,
        Admin $owner,
        ?Admin $executor,
        string $executionScope,
        int $accessVersion,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        if ($executionScope !== AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM
            || $accessVersion !== 0
            || $executor instanceof Admin
            || ! $owner->isSuperAdmin()
            || (string) $model->access_scope !== AiModel::ACCESS_SCOPE_SYSTEM_ONLY) {
            self::fail('model_source', 'ai_usage_system_attribution_invalid');
        }

        return self::make(
            $model,
            $owner,
            null,
            0,
            AiModelUsageEvent::EXECUTION_SCOPE_SYSTEM,
            AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            $requestId,
            $requestPayloadDigest,
        );
    }

    private static function assertAdminScope(string $executionScope): void
    {
        if (! in_array($executionScope, [
            AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
        ], true)) {
            self::fail('execution_scope', 'ai_usage_execution_scope_invalid');
        }
    }

    private static function make(
        AiModel $model,
        Admin $owner,
        ?Admin $executor,
        int $accessVersion,
        string $executionScope,
        string $modelSource,
        string $requestId,
        string $requestPayloadDigest,
    ): self {
        return new self(
            requestId: $requestId,
            requestPayloadDigest: $requestPayloadDigest,
            aiModelId: (int) $model->getKey(),
            configOwnerAdminId: (int) $owner->getKey(),
            executionAdminId: $executor instanceof Admin ? (int) $executor->getKey() : null,
            aiConfigAccessVersion: $accessVersion,
            executionScope: $executionScope,
            modelSource: $modelSource,
        );
    }

    private static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => [$code]]);
    }

    private static function validateCommonInput(
        string $requestId,
        string $requestPayloadDigest,
        int $accessVersion,
        string $executionScope,
        string $modelSource,
    ): void {
        Validator::make([
            'request_id' => $requestId,
            'request_payload_digest' => $requestPayloadDigest,
            'ai_config_access_version' => $accessVersion,
            'execution_scope' => $executionScope,
            'model_source' => $modelSource,
        ], [
            'request_id' => [
                'required',
                'string',
                'max:36',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || (! Str::isUuid($value) && ! Str::isUlid($value))) {
                        $fail('ai_usage_request_id_invalid');
                    }
                },
            ],
            'request_payload_digest' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'ai_config_access_version' => ['required', 'integer', 'min:1'],
            'execution_scope' => ['required', Rule::in([
                AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            ])],
            'model_source' => ['required', Rule::in([
                AiModelUsageEvent::MODEL_SOURCE_PERSONAL,
                AiModelUsageEvent::MODEL_SOURCE_SYSTEM,
            ])],
        ])->validate();
    }
}
