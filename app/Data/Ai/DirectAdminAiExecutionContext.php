<?php

namespace App\Data\Ai;

final readonly class DirectAdminAiExecutionContext
{
    public function __construct(
        public int $adminId,
        public string $adminRole,
        public int $accessVersion,
        public int $policyVersion,
        public string $requestId,
        public string $source,
        public int $sourceId,
        public string $capability,
        public ?int $requestedModelId,
    ) {}

    /** @return array{model_access_admin_id:int,model_access_admin_role:string,ai_config_access_version:int,resolver_policy_version:int} */
    public function persistedAdminSnapshot(): array
    {
        return [
            'model_access_admin_id' => $this->adminId,
            'model_access_admin_role' => $this->adminRole,
            'ai_config_access_version' => $this->accessVersion,
            'resolver_policy_version' => $this->policyVersion,
        ];
    }

    /** @return array<string,int|string|null> */
    public function toSafeArray(): array
    {
        return [
            'admin_id' => $this->adminId,
            'admin_role' => $this->adminRole,
            'access_version' => $this->accessVersion,
            'policy_version' => $this->policyVersion,
            'request_id' => $this->requestId,
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'capability' => $this->capability,
            'requested_model_id' => $this->requestedModelId,
        ];
    }
}
