<?php

namespace App\Data\Ai;

use App\Models\TaskRun;
use InvalidArgumentException;

final readonly class AiExecutionContext
{
    public const EXECUTION_SCOPE_PERSISTED_ADMIN = 'persisted_admin';

    public const CAPABILITY_CHAT = 'chat';

    public const CAPABILITY_EMBEDDING = 'embedding';

    public const CURRENT_RESOLVER_POLICY_VERSION = 1;

    private function __construct(
        public string $executionScope,
        public int $modelAccessAdminId,
        public string $modelAccessAdminRole,
        public int $aiConfigAccessVersion,
        public ?int $requestedModelId,
        public string $requiredCapability,
        public string $sourceType,
        public int $sourceId,
        public int $resolverPolicyVersion,
        public string $requestId,
        private string $executionLeaseToken,
        public ?int $taskRunId = null,
    ) {
        if ($this->executionScope !== self::EXECUTION_SCOPE_PERSISTED_ADMIN) {
            throw new InvalidArgumentException('Unsupported AI execution scope.');
        }
        if ($this->modelAccessAdminId <= 0 || $this->aiConfigAccessVersion <= 0) {
            throw new InvalidArgumentException('Persisted AI execution identity is incomplete.');
        }
        if (! in_array($this->modelAccessAdminRole, ['admin', 'super_admin'], true)) {
            throw new InvalidArgumentException('Persisted AI execution role is invalid.');
        }
        if (! in_array($this->requiredCapability, [self::CAPABILITY_CHAT, self::CAPABILITY_EMBEDDING], true)) {
            throw new InvalidArgumentException('AI execution capability is invalid.');
        }
        if ($this->sourceType === ''
            || $this->sourceId <= 0
            || $this->resolverPolicyVersion <= 0
            || $this->requestId === ''
            || $this->executionLeaseToken === ''
            || $this->taskRunId === null
            || $this->taskRunId <= 0) {
            throw new InvalidArgumentException('Persisted AI execution source is incomplete.');
        }
    }

    public static function fromPersistedTaskRun(TaskRun $run): self
    {
        $taskId = (int) $run->task_id;
        $runId = (int) $run->getKey();

        return new self(
            executionScope: self::EXECUTION_SCOPE_PERSISTED_ADMIN,
            modelAccessAdminId: (int) $run->model_access_admin_id,
            modelAccessAdminRole: (string) $run->model_access_admin_role,
            aiConfigAccessVersion: (int) $run->ai_config_access_version,
            requestedModelId: $run->requested_ai_model_id === null ? null : (int) $run->requested_ai_model_id,
            requiredCapability: self::CAPABILITY_CHAT,
            sourceType: 'task',
            sourceId: $taskId,
            resolverPolicyVersion: (int) $run->resolver_policy_version,
            requestId: 'task-run:'.$runId,
            executionLeaseToken: trim((string) $run->execution_lease_token),
            taskRunId: $runId,
        );
    }

    /** @return array<string, int|string|null> */
    public function toSafeArray(): array
    {
        return [
            'execution_scope' => $this->executionScope,
            'model_access_admin_id' => $this->modelAccessAdminId,
            'model_access_admin_role' => $this->modelAccessAdminRole,
            'ai_config_access_version' => $this->aiConfigAccessVersion,
            'requested_model_id' => $this->requestedModelId,
            'required_capability' => $this->requiredCapability,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'resolver_policy_version' => $this->resolverPolicyVersion,
            'request_id' => $this->requestId,
            'task_run_id' => $this->taskRunId,
        ];
    }

    public function executionLeaseToken(): string
    {
        return $this->executionLeaseToken;
    }
}
