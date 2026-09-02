<?php

namespace App\Data\Ai;

use App\Models\Admin;
use App\Models\AiWorkspaceRun;
use InvalidArgumentException;

final readonly class AiWorkspaceExecutionContext
{
    private function __construct(
        public int $modelAccessAdminId,
        public string $modelAccessAdminRole,
        public int $aiConfigAccessVersion,
        public ?int $requestedModelId,
        public int $resolverPolicyVersion,
        public string $requestId,
        public ?string $runId,
        private ?string $leaseToken,
        public string $leaseKind,
    ) {
        if ($this->modelAccessAdminId <= 0
            || ! in_array($this->modelAccessAdminRole, ['admin', 'super_admin'], true)
            || $this->aiConfigAccessVersion <= 0
            || $this->resolverPolicyVersion !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION
            || trim($this->requestId) === ''
            || ! in_array($this->leaseKind, ['direct', 'resolution', 'execution'], true)
            || ($this->leaseKind !== 'direct' && (trim((string) $this->runId) === '' || trim((string) $this->leaseToken) === ''))) {
            throw new InvalidArgumentException('AI Workspace execution identity is incomplete.');
        }
    }

    public static function forDirectAdmin(Admin $admin, ?int $requestedModelId = null, ?string $requestId = null): self
    {
        return new self(
            modelAccessAdminId: (int) $admin->getKey(),
            modelAccessAdminRole: self::normalizedRole($admin),
            aiConfigAccessVersion: max(1, (int) $admin->ai_config_access_version),
            requestedModelId: $requestedModelId,
            resolverPolicyVersion: AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
            requestId: $requestId ?? 'ai-workspace-direct:'.(int) $admin->getKey(),
            runId: null,
            leaseToken: null,
            leaseKind: 'direct',
        );
    }

    public static function fromResolutionRun(AiWorkspaceRun $run, string $leaseToken): self
    {
        return self::fromRun($run, $leaseToken, 'resolution');
    }

    public static function fromExecutionRun(AiWorkspaceRun $run, string $leaseToken): self
    {
        return self::fromRun($run, $leaseToken, 'execution');
    }

    private static function fromRun(AiWorkspaceRun $run, string $leaseToken, string $leaseKind): self
    {
        return new self(
            modelAccessAdminId: (int) $run->model_access_admin_id,
            modelAccessAdminRole: (string) $run->model_access_admin_role,
            aiConfigAccessVersion: (int) $run->ai_config_access_version,
            requestedModelId: $run->requested_ai_model_id === null ? null : (int) $run->requested_ai_model_id,
            resolverPolicyVersion: (int) $run->resolver_policy_version,
            requestId: 'ai-workspace-run:'.(string) $run->getKey(),
            runId: (string) $run->getKey(),
            leaseToken: trim($leaseToken),
            leaseKind: $leaseKind,
        );
    }

    public function leaseToken(): ?string
    {
        return $this->leaseToken;
    }

    /** @return array<string,int|string|null> */
    public function toSafeArray(): array
    {
        return [
            'execution_scope' => AiExecutionContext::EXECUTION_SCOPE_PERSISTED_ADMIN,
            'model_access_admin_id' => $this->modelAccessAdminId,
            'model_access_admin_role' => $this->modelAccessAdminRole,
            'ai_config_access_version' => $this->aiConfigAccessVersion,
            'requested_model_id' => $this->requestedModelId,
            'required_capability' => AiExecutionContext::CAPABILITY_CHAT,
            'source_type' => $this->runId === null ? 'ai_workspace_direct' : 'ai_workspace_run',
            'source_id' => $this->runId ?? $this->modelAccessAdminId,
            'resolver_policy_version' => $this->resolverPolicyVersion,
            'request_id' => $this->requestId,
        ];
    }

    public static function normalizedRole(Admin $admin): string
    {
        return $admin->isSuperAdmin() ? 'super_admin' : 'admin';
    }
}
