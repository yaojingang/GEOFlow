<?php

namespace App\Data\Ai;

use App\Models\KnowledgeFactGenerationRun;
use InvalidArgumentException;

final readonly class KnowledgeFactGenerationExecutionContext
{
    private function __construct(
        public int $runId,
        public int $executionAttempt,
        public int $batchSequence,
        public int $batchAttempt,
        public string $inputHash,
        public int $modelAccessAdminId,
        public string $modelAccessAdminRole,
        public int $aiConfigAccessVersion,
        public int $requestedModelId,
        public int $resolverPolicyVersion,
        private string $leaseToken,
    ) {
        if ($this->runId <= 0
            || $this->executionAttempt <= 0
            || $this->batchSequence <= 0
            || $this->batchAttempt <= 0
            || $this->inputHash === ''
            || $this->modelAccessAdminId <= 0
            || ! in_array($this->modelAccessAdminRole, ['admin', 'super_admin'], true)
            || $this->aiConfigAccessVersion <= 0
            || $this->requestedModelId <= 0
            || $this->resolverPolicyVersion <= 0
            || $this->leaseToken === '') {
            throw new InvalidArgumentException('Persisted knowledge fact generation AI identity is incomplete.');
        }
    }

    public static function fromClaimedRun(
        KnowledgeFactGenerationRun $run,
        int $batchSequence,
        string $inputHash,
        string $leaseToken,
    ): self {
        $claim = (array) data_get($run->batch_claims_json, (string) $batchSequence, []);

        return new self(
            runId: (int) $run->getKey(),
            executionAttempt: (int) $run->execution_attempt,
            batchSequence: $batchSequence,
            batchAttempt: (int) ($claim['attempt_count'] ?? 0),
            inputHash: $inputHash,
            modelAccessAdminId: (int) $run->model_access_admin_id,
            modelAccessAdminRole: (string) $run->model_access_admin_role,
            aiConfigAccessVersion: (int) $run->ai_config_access_version,
            requestedModelId: (int) $run->requested_ai_model_id,
            resolverPolicyVersion: (int) $run->resolver_policy_version,
            leaseToken: $leaseToken,
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
            'required_capability' => AiExecutionContext::CAPABILITY_CHAT,
            'source_type' => 'knowledge_fact_generation_run',
            'source_id' => $this->runId,
            'request_id' => 'knowledge-fact-generation-run:'.$this->runId.':'.$this->executionAttempt.':'.$this->batchSequence,
        ];
    }
}
