<?php

namespace App\Data\Ai;

use InvalidArgumentException;

final readonly class AiWorkspaceModelExecutionReceipt
{
    public function __construct(
        public int $modelId,
        public string $modelSource,
        public string $configurationDigest,
        public string $requestId,
    ) {
        if ($this->modelId <= 0
            || ! in_array($this->modelSource, ['personal', 'shared'], true)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $this->configurationDigest)
            || trim($this->requestId) === '') {
            throw new InvalidArgumentException('AI Workspace model execution receipt is invalid.');
        }
    }
}
