<?php

namespace App\Services\Admin;

use App\Models\AiModelUsageEvent;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiModelUsageAttempt
{
    private bool $finalized = false;

    /**
     * @param  array{call_key:string,operation:string,business_source:string,source_type:?string,source_id:int|string|null}  $identity
     */
    public function __construct(
        private readonly ?AiModelUsageAccessSnapshot $snapshot,
        private readonly AiModelUsageRecorder $recorder,
        private readonly array $identity,
    ) {}

    public function succeeded(mixed $usage = null): void
    {
        $this->finalize(AiModelUsageEvent::STATUS_SUCCEEDED, null, $usage);
    }

    public function failed(string $errorCode = 'ai_provider_request_failed', mixed $usage = null): void
    {
        $this->finalize(AiModelUsageEvent::STATUS_FAILED, $errorCode, $usage);
    }

    public function discarded(string $errorCode = 'ai_result_discarded', mixed $usage = null): void
    {
        $this->finalize(AiModelUsageEvent::STATUS_DISCARDED, $errorCode, $usage);
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked', mixed $usage = null): void
    {
        $this->finalize(AiModelUsageEvent::STATUS_REVOKED, $errorCode, $usage);
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    private function finalize(string $status, ?string $errorCode, mixed $usage): void
    {
        if ($this->finalized) {
            return;
        }
        $this->finalized = true;
        if (! $this->snapshot instanceof AiModelUsageAccessSnapshot) {
            return;
        }

        try {
            $this->recorder->record($this->snapshot, [
                ...$this->identity,
                'status' => $status,
                'error_code' => $status === AiModelUsageEvent::STATUS_SUCCEEDED
                    ? null
                    : $this->safeErrorCode($errorCode),
                ...$this->tokens($usage),
                'estimated_cost' => null,
            ]);
        } catch (Throwable $exception) {
            $this->warnSafely($exception);
        }
    }

    private function safeErrorCode(?string $errorCode): string
    {
        $errorCode = strtolower(trim((string) $errorCode));

        return preg_match('/\A[a-z0-9_.:-]{1,100}\z/', $errorCode) === 1
            ? $errorCode
            : 'ai_usage_terminal_failure';
    }

    /** @return array{input_tokens:?int,output_tokens:?int,total_tokens:?int} */
    private function tokens(mixed $usage): array
    {
        if ($usage instanceof Arrayable) {
            $usage = $usage->toArray();
        } elseif (is_object($usage) && method_exists($usage, 'toArray')) {
            $usage = $usage->toArray();
        } elseif (is_object($usage)) {
            $usage = get_object_vars($usage);
        }
        $usage = is_array($usage) ? $usage : [];
        $input = $this->nonNegativeInt($usage['input_tokens'] ?? $usage['prompt_tokens'] ?? $usage['promptTokens'] ?? null);
        $output = $this->nonNegativeInt($usage['output_tokens'] ?? $usage['completion_tokens'] ?? $usage['completionTokens'] ?? null);
        $total = $this->nonNegativeInt($usage['total_tokens'] ?? $usage['totalTokens'] ?? null);
        if ($total === null && ($input !== null || $output !== null)) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ];
    }

    private function nonNegativeInt(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function warnSafely(Throwable $exception): void
    {
        try {
            Log::warning('AI usage telemetry recording failed safely.', [
                'stage' => 'terminal',
                'exception_type' => $exception::class,
            ]);
        } catch (Throwable) {
            // Telemetry and its alert path must remain best effort.
        }
    }
}
