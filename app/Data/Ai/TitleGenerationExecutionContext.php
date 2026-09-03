<?php

namespace App\Data\Ai;

use App\Models\TitleGenerationRun;
use InvalidArgumentException;

final readonly class TitleGenerationExecutionContext
{
    private function __construct(
        public int $runId,
        public int $batchSequence,
        public int $batchAttemptCount,
        public int $modelAccessAdminId,
        public string $modelAccessAdminRole,
        public int $aiConfigAccessVersion,
        public int $requestedModelId,
        public int $resolverPolicyVersion,
        private string $leaseToken,
    ) {
        if ($this->runId <= 0
            || $this->batchSequence < 0
            || $this->batchAttemptCount <= 0
            || $this->modelAccessAdminId <= 0
            || ! in_array($this->modelAccessAdminRole, ['admin', 'super_admin'], true)
            || $this->aiConfigAccessVersion <= 0
            || $this->requestedModelId <= 0
            || $this->resolverPolicyVersion <= 0
            || $this->leaseToken === '') {
            throw new InvalidArgumentException('Persisted title generation AI identity is incomplete.');
        }
    }

    public static function fromClaimedRun(TitleGenerationRun $run): self
    {
        return new self(
            runId: (int) $run->getKey(),
            batchSequence: (int) $run->batch_sequence,
            batchAttemptCount: (int) $run->batch_attempt_count,
            modelAccessAdminId: (int) $run->model_access_admin_id,
            modelAccessAdminRole: (string) $run->model_access_admin_role,
            aiConfigAccessVersion: (int) $run->ai_config_access_version,
            requestedModelId: (int) $run->requested_ai_model_id,
            resolverPolicyVersion: (int) $run->resolver_policy_version,
            leaseToken: trim((string) $run->lease_token),
        );
    }

    public function leaseToken(): string
    {
        return $this->leaseToken;
    }

    /** @return array<string,int|string> */
    public function toSafeArray(): array
    {
        return [
            'execution_scope' => AiExecutionContext::EXECUTION_SCOPE_PERSISTED_ADMIN,
            'model_access_admin_id' => $this->modelAccessAdminId,
            'model_access_admin_role' => $this->modelAccessAdminRole,
            'ai_config_access_version' => $this->aiConfigAccessVersion,
            'requested_model_id' => $this->requestedModelId,
            'required_capability' => AiExecutionContext::CAPABILITY_CHAT,
            'source_type' => 'title_generation_run',
            'source_id' => $this->runId,
            'resolver_policy_version' => $this->resolverPolicyVersion,
            'request_id' => $this->leaseToken,
        ];
    }
}
