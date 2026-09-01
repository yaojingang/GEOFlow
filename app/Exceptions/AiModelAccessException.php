<?php

namespace App\Exceptions;

use App\Models\Admin;
use App\Models\AiModel;
use RuntimeException;

final class AiModelAccessException extends RuntimeException
{
    public const AI_MODEL_NOT_ACCESSIBLE = 'ai_model_not_accessible';

    public const AI_EXECUTION_ADMIN_INACTIVE = 'ai_execution_admin_inactive';

    public const AI_CONFIG_ACCESS_REVOKED = 'ai_config_access_revoked';

    public const AI_CONFIG_OWNER_INACTIVE = 'ai_config_owner_inactive';

    public const AI_MODEL_UNAVAILABLE = 'ai_model_unavailable';

    public const AI_EMBEDDING_INCOMPATIBLE = 'ai_embedding_incompatible';

    private function __construct(
        private readonly string $errorCode,
        private readonly int $adminId,
        private readonly ?int $modelId = null,
        private readonly ?int $configOwnerAdminId = null,
    ) {
        parent::__construct($errorCode);
    }

    public static function executionAdminInactive(Admin $admin): self
    {
        return new self(self::AI_EXECUTION_ADMIN_INACTIVE, (int) $admin->getKey());
    }

    public static function executionAdminInactiveForId(int $adminId): self
    {
        return new self(self::AI_EXECUTION_ADMIN_INACTIVE, max(0, $adminId));
    }

    public static function configOwnerInactive(Admin $admin, int $configOwnerAdminId): self
    {
        return new self(
            self::AI_CONFIG_OWNER_INACTIVE,
            (int) $admin->getKey(),
            configOwnerAdminId: $configOwnerAdminId,
        );
    }

    public static function configAccessRevoked(Admin $admin, ?AiModel $model = null): self
    {
        return new self(
            self::AI_CONFIG_ACCESS_REVOKED,
            (int) $admin->getKey(),
            $model === null ? null : (int) $model->getKey(),
        );
    }

    public static function configAccessRevokedForAdminId(int $adminId): self
    {
        return new self(self::AI_CONFIG_ACCESS_REVOKED, max(0, $adminId));
    }

    public static function modelNotAccessible(Admin $admin, AiModel $model): self
    {
        return new self(
            self::AI_MODEL_NOT_ACCESSIBLE,
            (int) $admin->getKey(),
            (int) $model->getKey(),
        );
    }

    public static function modelUnavailable(Admin $admin, ?AiModel $model = null): self
    {
        return new self(
            self::AI_MODEL_UNAVAILABLE,
            (int) $admin->getKey(),
            $model === null ? null : (int) $model->getKey(),
        );
    }

    public static function embeddingIncompatible(Admin $admin, AiModel $model): self
    {
        return new self(
            self::AI_EMBEDDING_INCOMPATIBLE,
            (int) $admin->getKey(),
            (int) $model->getKey(),
        );
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array{error_code: string, admin_id: int, model_id?: int, config_owner_admin_id?: int} */
    public function context(): array
    {
        $context = [
            'error_code' => $this->errorCode,
            'admin_id' => $this->adminId,
        ];

        if ($this->modelId !== null) {
            $context['model_id'] = $this->modelId;
        }

        if ($this->configOwnerAdminId !== null) {
            $context['config_owner_admin_id'] = $this->configOwnerAdminId;
        }

        return $context;
    }
}
