<?php

namespace App\Exceptions;

use RuntimeException;

final class AiModelRuntimeEligibilityException extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function configuration(string $message): self
    {
        return new self('configuration_invalid', $message);
    }

    public static function quota(): self
    {
        return new self('quota_exhausted', 'AI 模型不可用或已达到今日调用上限');
    }

    public static function health(): self
    {
        return new self('health_gate_open', 'AI 模型当前健康门禁暂不可用');
    }
}
