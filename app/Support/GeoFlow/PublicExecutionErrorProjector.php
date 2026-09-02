<?php

namespace App\Support\GeoFlow;

use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;

final class PublicExecutionErrorProjector
{
    public const GENERIC_ERROR_CODE = 'task_execution_failed';

    public const GENERIC_ERROR_MESSAGE = '任务执行未完成';

    public function __construct(
        private readonly AiExecutionErrorSanitizer $sanitizer,
    ) {}

    public function stableCode(mixed $explicitCode, mixed ...$diagnostics): ?string
    {
        if (is_string($explicitCode) && $this->isKnownPublicCode($explicitCode)) {
            return trim($explicitCode);
        }

        foreach ($diagnostics as $diagnostic) {
            if (is_string($diagnostic) && $this->isKnownPublicCode($diagnostic)) {
                return trim($diagnostic);
            }
        }

        foreach ([$explicitCode, ...$diagnostics] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return self::GENERIC_ERROR_CODE;
            }
        }

        return null;
    }

    public function genericMessage(?string $errorCode): string
    {
        return $errorCode === null ? '' : self::GENERIC_ERROR_MESSAGE;
    }

    public function sanitizedDiagnostic(mixed $diagnostic): string
    {
        return $this->sanitizer->sanitize(is_string($diagnostic) ? $diagnostic : null, '');
    }

    public function sanitizedMetaText(string $value): string
    {
        $sanitized = $this->sanitizer->sanitize($value, '');

        return str_contains($sanitized, '[redacted') ? '[redacted]' : $sanitized;
    }

    private function isKnownPublicCode(string $candidate): bool
    {
        return in_array(trim($candidate), [
            AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
            AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
            AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
            AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
            AiModelAccessException::AI_MODEL_UNAVAILABLE,
            AiModelAccessException::AI_EMBEDDING_INCOMPATIBLE,
            PermanentAiProviderException::ERROR_CODE,
            self::GENERIC_ERROR_CODE,
        ], true);
    }
}
