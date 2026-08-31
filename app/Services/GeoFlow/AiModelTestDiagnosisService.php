<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Throwable;

final class AiModelTestDiagnosisService
{
    /**
     * @return array{code: string, title: string, reason: string, steps: list<string>, severity: string}
     */
    public function forLocalFailure(string $code): array
    {
        return $this->translated($code);
    }

    /**
     * @return array{code: string, title: string, reason: string, steps: list<string>, severity: string}
     */
    public function forHttpFailure(AiModel $model, string $apiKey, int $status): array
    {
        if ($this->hasDeepSeekArkMismatch($model, $apiKey)) {
            return $this->translated('provider_configuration_mismatch');
        }

        return $this->translated($this->codeForHttpStatus($status));
    }

    /**
     * @return array{code: string, title: string, reason: string, steps: list<string>, severity: string}
     */
    public function forInvalidResponse(AiModel $model, string $apiKey): array
    {
        if ($this->hasDeepSeekArkMismatch($model, $apiKey)) {
            return $this->translated('provider_configuration_mismatch');
        }

        return $this->translated('invalid_response');
    }

    /**
     * @return array{code: string, title: string, reason: string, steps: list<string>, severity: string}
     */
    public function forException(Throwable $exception, AiModel $model, string $apiKey): array
    {
        if ($this->hasDeepSeekArkMismatch($model, $apiKey)) {
            return $this->translated('provider_configuration_mismatch');
        }

        if ($exception instanceof OutboundRequestBlockedException) {
            return $this->translated(match ($exception->reasonCode) {
                'response_too_large' => 'response_too_large',
                'dns_resolution_failed' => 'network_failed',
                'unsafe_address', 'mapped_address' => 'outbound_blocked',
                default => 'unexpected_error',
            });
        }

        if ($exception instanceof OutboundRequestFailedException) {
            if ($exception->providerCategory === 'quota_exhausted') {
                return $this->translated('provider_quota_exhausted');
            }
            if ($exception->httpStatus !== null) {
                return $this->translated($this->codeForHttpStatus($exception->httpStatus));
            }

            return $this->translated(match ($exception->transportCategory) {
                'tls' => 'tls_failed',
                'gateway' => 'upstream_unavailable',
                'rate_limited' => 'rate_limited',
                default => 'network_failed',
            });
        }

        if ($exception instanceof RateLimitedException) {
            return $this->translated('rate_limited');
        }
        if ($exception instanceof ProviderOverloadedException) {
            return $this->translated('upstream_unavailable');
        }
        if ($exception instanceof InsufficientCreditsException) {
            return $this->translated('provider_quota_exhausted');
        }
        if (($httpStatus = $this->httpStatusFromException($exception)) !== null) {
            return $this->translated($this->codeForHttpStatus($httpStatus));
        }

        $transportText = $this->exceptionTransportText($exception);
        if (preg_match('/certificate|curl error (35|51|60)|\btls\b|\bssl\b/i', $transportText) === 1) {
            return $this->translated('tls_failed');
        }
        if (preg_match('/timeout|timed out|curl error (6|7|28)|could not resolve|connection/i', $transportText) === 1) {
            return $this->translated('network_failed');
        }

        return $this->translated('unexpected_error');
    }

    public function modelIdForDisplay(string $modelId): string
    {
        return $this->looksLikeCredentialModelId($modelId)
            ? (string) __('admin.ai_models.sensitive_model_id_hidden')
            : $modelId;
    }

    private function codeForHttpStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'authentication_failed',
            $status === 402 => 'provider_quota_exhausted',
            $status === 403 => 'permission_denied',
            $status === 404 => 'endpoint_not_found',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'upstream_unavailable',
            default => 'provider_rejected',
        };
    }

    private function hasDeepSeekArkMismatch(AiModel $model, string $apiKey): bool
    {
        $host = strtolower((string) parse_url(trim((string) $model->api_url), PHP_URL_HOST));
        if ($host !== 'deepseek.com' && ! str_ends_with($host, '.deepseek.com')) {
            return false;
        }

        $modelId = strtolower(trim((string) $model->model_id));
        $normalizedApiKey = strtolower(trim($apiKey));

        return $this->looksLikeCredentialModelId($modelId) || str_starts_with($normalizedApiKey, 'ark-');
    }

    private function looksLikeCredentialModelId(string $modelId): bool
    {
        return str_starts_with(strtolower(trim($modelId)), 'api-key-');
    }

    private function httpStatusFromException(Throwable $exception): ?int
    {
        $current = $exception;

        for ($depth = 0; $depth < 5 && $current instanceof Throwable; $depth++) {
            if ($current instanceof RequestException && $current->response !== null) {
                return $current->response->status();
            }
            $current = $current->getPrevious();
        }

        return null;
    }

    private function exceptionTransportText(Throwable $exception): string
    {
        $parts = [];
        $current = $exception;

        for ($depth = 0; $depth < 5 && $current instanceof Throwable; $depth++) {
            $parts[] = $current::class;
            $parts[] = $current->getMessage();
            $current = $current->getPrevious();
        }

        return implode("\n", $parts);
    }

    /**
     * @return array{code: string, title: string, reason: string, steps: list<string>, severity: string}
     */
    private function translated(string $code): array
    {
        $copy = __('admin.ai_models.diagnosis.'.$code);
        $copy = is_array($copy) ? $copy : [];
        $steps = array_values(array_filter(
            is_array($copy['steps'] ?? null) ? $copy['steps'] : [],
            static fn (mixed $step): bool => is_string($step) && trim($step) !== '',
        ));

        return [
            'code' => $code,
            'title' => (string) ($copy['title'] ?? __('admin.ai_models.diagnosis.unexpected_error.title')),
            'reason' => (string) ($copy['reason'] ?? __('admin.ai_models.diagnosis.unexpected_error.reason')),
            'steps' => $steps,
            'severity' => 'error',
        ];
    }
}
