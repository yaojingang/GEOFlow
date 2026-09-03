<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Ai\Agents\LegacyArticleQualityReviewerAgent;
use App\Contracts\ProviderAttemptAwareArticleAiQualityReviewer;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\AiModel;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class LaravelArticleAiQualityReviewer implements ProviderAttemptAwareArticleAiQualityReviewer
{
    public function __construct(
        private ApiKeyCrypto $apiKeyCrypto,
        private AiUsageQuotaService $usageQuota,
        private ArticleAiQualityProviderCircuitBreaker $circuitBreaker,
        private ArticleAiQualityReadinessRecorder $readinessRecorder,
    ) {}

    public function review(AiModel $model, string $instructions): array
    {
        return $this->reviewWithin(
            $model,
            $instructions,
            (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
        );
    }

    public function reviewWithin(AiModel $model, string $instructions, int $timeoutSeconds): array
    {
        return $this->reviewWithinVersion($model, $instructions, $timeoutSeconds, 'fast_v2');
    }

    public function reviewWithinVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        $this->circuitBreaker->beforeRequest($model);
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            $exception = new ArticleAiQualityRuntimeException('provider_quota_exhausted');
            $this->circuitBreaker->recordFailure($model, $exception);

            throw $exception;
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
        );
    }

    public function reviewWithinVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        ArticleAiQualityProviderUsageSession $usageSession,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        $this->circuitBreaker->beforeRequest($model);
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            $exception = new ArticleAiQualityRuntimeException('provider_quota_exhausted');
            $this->circuitBreaker->recordFailure($model, $exception);

            throw $exception;
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
            $usageSession,
        );
    }

    public function reviewWithinReservedVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        ArticleAiQualityProviderUsageSession $usageSession,
        bool $readinessConfirmed = false,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            $this->usageQuota->releaseModel($reservation);

            throw new RuntimeException('ai_quality_execution_version_invalid');
        }
        if (! $readinessConfirmed) {
            try {
                $this->circuitBreaker->beforeRequest($model);
            } catch (Throwable $exception) {
                $this->usageQuota->releaseModel($reservation);

                throw $exception;
            }
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
            $usageSession,
        );
    }

    public function reviewWithinReservedVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        bool $readinessConfirmed = false,
    ): array {
        if (! in_array($executionVersion, ['legacy', 'fast_v2'], true)) {
            $this->usageQuota->releaseModel($reservation);

            throw new RuntimeException('ai_quality_execution_version_invalid');
        }

        if (! $readinessConfirmed) {
            try {
                $this->circuitBreaker->beforeRequest($model);
            } catch (Throwable $exception) {
                $this->usageQuota->releaseModel($reservation);

                throw $exception;
            }
        }

        return $this->reviewWithReservation(
            $model,
            $instructions,
            $timeoutSeconds,
            $executionVersion,
            $reservation,
        );
    }

    /** @return array<string,mixed> */
    private function reviewWithReservation(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        ?ArticleAiQualityProviderUsageSession $usageSession = null,
    ): array {
        $externalRequestAttempted = false;

        try {
            [$provider, $driver, $baseUrl] = $this->runtimeProvider($model);
            $configuredMaxTokens = (int) config('geoflow.ai_quality_max_output_tokens', 2048);
            $modelMaxTokens = (int) ($model->max_tokens ?: $configuredMaxTokens);
            $maxTokens = max(512, min($configuredMaxTokens, $modelMaxTokens));
            $timeout = max(1, min(
                (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
                $timeoutSeconds,
            ));
            $mode = $this->readinessRecorder->prefersJson($model) ? 'json_fallback' : 'structured';
            $usesV2Schema = $executionVersion === 'fast_v2';
            $attemptStartedAt = hrtime(true);

            if ($mode === 'json_fallback') {
                $providerUsageAttempt = $usageSession?->begin($mode);
                $providerResponseReturned = false;
                try {
                    $externalRequestAttempted = true;
                    $response = (new ArticleQualityJsonReviewerAgent($instructions, $maxTokens))->prompt(
                        '请执行本分段质检，只返回 JSON。',
                        [],
                        $provider,
                        (string) $model->model_id,
                        $timeout,
                    );
                    $providerResponseReturned = true;
                    $result = $this->decodeJson((string) $response->text);
                    if ($providerUsageAttempt !== null) {
                        $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                    }
                } catch (Throwable $jsonException) {
                    $typed = $this->typedProviderException($jsonException, $baseUrl);
                    if ($providerUsageAttempt !== null) {
                        $providerResponseReturned
                            ? $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null)
                            : $usageSession?->providerFailed($providerUsageAttempt, $typed->safeCode());
                    }
                    $this->recordReadinessAttempt($model, $mode, false, $attemptStartedAt, $typed->safeCode());

                    throw $typed;
                }
            } else {
                $providerUsageAttempt = $usageSession?->begin('structured');
                try {
                    $externalRequestAttempted = true;
                    $agent = $usesV2Schema
                        ? new ArticleQualityReviewerAgent($instructions, $maxTokens)
                        : new LegacyArticleQualityReviewerAgent($instructions, $maxTokens);
                    $response = $agent->prompt(
                        '请执行本分段质检并返回完整结构化结果。',
                        [],
                        $provider,
                        (string) $model->model_id,
                        $timeout,
                    );
                    $result = $response->structured;
                    if ($providerUsageAttempt !== null) {
                        $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                    }
                } catch (Throwable $structuredException) {
                    $structuredTyped = $this->typedProviderException($structuredException, $baseUrl);
                    if ($providerUsageAttempt !== null) {
                        $usageSession?->providerFailed($providerUsageAttempt, $structuredTyped->safeCode());
                    }
                    $this->recordReadinessAttempt($model, 'structured', false, $attemptStartedAt, $structuredTyped->safeCode());
                    $remainingSeconds = $timeout - ((hrtime(true) - $attemptStartedAt) / 1_000_000_000);
                    if ($remainingSeconds < 1 || ! in_array($structuredTyped->safeCode(), [
                        'structured_output_unsupported', 'invalid_model_output',
                    ], true)) {
                        throw $structuredTyped;
                    }
                    $mode = 'json_fallback';
                    $this->usageQuota->recordModelAttempt($reservation);
                    $fallbackReservation = $this->usageQuota->reserveModel($model);
                    if ($fallbackReservation === null) {
                        throw new ArticleAiQualityRuntimeException('provider_quota_exhausted', false, $structuredException);
                    }
                    $reservation = $fallbackReservation;
                    $externalRequestAttempted = false;
                    $attemptStartedAt = hrtime(true);
                    $providerUsageAttempt = $usageSession?->begin($mode);
                    $providerResponseReturned = false;
                    try {
                        $externalRequestAttempted = true;
                        $response = (new ArticleQualityJsonReviewerAgent($instructions, $maxTokens))->prompt(
                            '请执行本分段质检，只返回 JSON。',
                            [],
                            $provider,
                            (string) $model->model_id,
                            max(1, (int) floor($remainingSeconds)),
                        );
                        $providerResponseReturned = true;
                        $result = $this->decodeJson((string) $response->text);
                        if ($providerUsageAttempt !== null) {
                            $usageSession?->providerReturned($providerUsageAttempt, $response->usage);
                        }
                    } catch (Throwable $fallbackException) {
                        $typed = $this->typedProviderException($fallbackException, $baseUrl, $structuredException);
                        if ($providerUsageAttempt !== null) {
                            $providerResponseReturned
                                ? $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null)
                                : $usageSession?->providerFailed($providerUsageAttempt, $typed->safeCode());
                        }
                        $this->recordReadinessAttempt($model, $mode, false, $attemptStartedAt, $typed->safeCode());

                        throw $typed;
                    }
                }
            }

            if (! is_array($result) || $result === []) {
                if ($providerUsageAttempt !== null) {
                    $usageSession?->providerResultDiscarded($providerUsageAttempt, $response->usage ?? null, 'invalid_model_output');
                }
                $this->recordReadinessAttempt($model, $mode, false, $attemptStartedAt, 'invalid_model_output');
                throw new ArticleAiQualityRuntimeException('invalid_model_output');
            }

            $this->usageQuota->recordModelSuccess($reservation);
            $this->circuitBreaker->recordSuccess($model);
            $this->recordReadinessAttempt($model, $mode, true, $attemptStartedAt, null);

            return [
                'result' => $result,
                'usage' => $response->usage->toArray(),
                'model' => [
                    'id' => (int) $model->id,
                    'name' => (string) $model->name,
                    'model_id' => (string) $model->model_id,
                    'provider_driver' => $driver,
                ],
                'mode' => $mode,
            ];
        } catch (Throwable $exception) {
            if ($externalRequestAttempted) {
                $this->usageQuota->recordModelAttempt($reservation);
            } else {
                $this->usageQuota->releaseModel($reservation);
            }

            $typedException = $exception instanceof ArticleAiQualityRuntimeException
                ? $exception
                : $this->typedProviderException($exception, isset($baseUrl) ? $baseUrl : '');
            $this->circuitBreaker->recordFailure($model, $typedException);

            throw $typedException;
        }
    }

    /** @return array{0:string,1:string,2:string} */
    private function runtimeProvider(AiModel $model): array
    {
        $baseUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($baseUrl === '' || $apiKey === '' || trim((string) $model->model_id) === '') {
            throw new RuntimeException('ai_quality_model_configuration_incomplete');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) $model->model_id);
        $provider = OpenAiRuntimeProvider::registerProvider('article_quality', $driver, $baseUrl, $apiKey);

        return [$provider, $driver, $baseUrl];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $text): array
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $trimmed) ?? $trimmed;

        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $startsAsJson = str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[');
            $endsAsJson = str_ends_with($trimmed, '}') || str_ends_with($trimmed, ']');

            throw new ArticleAiQualityRuntimeException(
                $startsAsJson && ! $endsAsJson ? 'model_output_truncated' : 'invalid_model_output',
                true,
                $exception,
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function typedProviderException(
        Throwable $exception,
        string $baseUrl,
        ?Throwable $previous = null,
    ): ArticleAiQualityRuntimeException {
        if ($exception instanceof ArticleAiQualityRuntimeException) {
            return $exception;
        }

        [$status, $providerCode, $normalized, $types] = $this->providerFailureContext($exception, $baseUrl);
        $safeCode = match (true) {
            $status === 402, str_contains($types, 'providercategory:quota_exhausted') => 'provider_quota_exhausted',
            $status === 401, $status === 403, str_contains($normalized, 'auth'), str_contains($normalized, 'api key') => 'provider_authentication_failed',
            $status === 429, str_contains($types, 'transportcategory:rate_limited'), str_contains($normalized, 'rate limit') => 'provider_rate_limited',
            str_contains($types, 'transportcategory:timeout'), str_contains($types, 'timeoutexception'), str_contains($normalized, 'timeout'), str_contains($normalized, 'timed out') => 'provider_timeout',
            in_array($status, [502, 503, 504], true),
            str_contains($types, 'transportcategory:gateway'),
            str_contains($types, 'transportcategory:connection'),
            str_contains($types, 'transportcategory:dns'),
            str_contains($types, 'transportcategory:tls'),
            str_contains($types, 'connectexception'),
            str_contains($normalized, 'gateway') => 'provider_gateway_error',
            str_contains($normalized, 'quota'), str_contains($normalized, 'credit') => 'provider_quota_exhausted',
            str_contains($normalized, 'maximum output token'),
            str_contains($normalized, 'max output token'),
            str_contains($normalized, 'output token limit'),
            str_contains($normalized, 'output budget') => 'output_budget_exhausted',
            str_contains($normalized, 'truncated'),
            str_contains($normalized, 'finish reason: length'),
            str_contains($normalized, 'finish_reason=length') => 'model_output_truncated',
            str_contains($normalized, 'structured'), str_contains($normalized, 'schema') => 'structured_output_unsupported',
            default => 'provider_error',
        };

        return new ArticleAiQualityRuntimeException(
            $safeCode,
            in_array($safeCode, [
                'provider_timeout',
                'provider_rate_limited',
                'provider_gateway_error',
                'structured_output_unsupported',
                'model_output_truncated',
                'output_budget_exhausted',
            ], true),
            $previous ?? $exception,
            $status,
            $providerCode,
        );
    }

    /** @return array{?int,?string,string,string} */
    private function providerFailureContext(Throwable $exception, string $baseUrl): array
    {
        $status = null;
        $providerCode = null;
        $messages = [];
        $types = [];
        $current = $exception;
        for ($depth = 0; $depth < 8 && $current instanceof Throwable; $depth++) {
            $types[] = strtolower($current::class);
            if ($current instanceof OutboundRequestFailedException
                && is_string($current->causeType)) {
                $types[] = strtolower($current->causeType);
            }
            if ($current instanceof OutboundRequestFailedException) {
                $types[] = 'transportcategory:'.$current->transportCategory;
                $types[] = 'providercategory:'.$current->providerCategory;
            }
            $message = trim(OpenAiRuntimeProvider::normalizeApiException($current, $baseUrl));
            if ($message !== '') {
                $messages[] = $message;
            }
            $response = null;
            if (isset($current->response) && is_object($current->response)) {
                $response = $current->response;
            } elseif (method_exists($current, 'getResponse')) {
                $candidateResponse = $current->getResponse();
                $response = is_object($candidateResponse) ? $candidateResponse : null;
            }
            $candidateStatus = $current instanceof OutboundRequestFailedException
                ? $current->httpStatus
                : null;
            if ($status === null && is_int($candidateStatus) && $candidateStatus >= 100 && $candidateStatus <= 599) {
                $status = $candidateStatus;
            }
            if ($status === null && $current->getCode() >= 100 && $current->getCode() <= 599) {
                $status = (int) $current->getCode();
            }
            if ($status === null && $response !== null) {
                if (method_exists($response, 'status')) {
                    $candidateStatus = $response->status();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                } elseif (method_exists($response, 'getStatusCode')) {
                    $candidateStatus = $response->getStatusCode();
                    $status = is_int($candidateStatus) ? $candidateStatus : null;
                }
            }
            $candidateProviderCode = $current instanceof OutboundRequestFailedException
                ? $current->providerCode
                : null;
            if ($candidateProviderCode === null && $response !== null && method_exists($response, 'json')) {
                $payload = $response->json();
                $error = is_array($payload) && is_array($payload['error'] ?? null) ? $payload['error'] : [];
                $candidate = $error['code'] ?? $error['type'] ?? null;
                $candidateProviderCode = is_string($candidate) && preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $candidate) === 1
                    ? $candidate
                    : null;
            }
            if ($providerCode === null && is_string($candidateProviderCode) && $candidateProviderCode !== '') {
                $providerCode = $candidateProviderCode;
            }
            $current = $current->getPrevious();
        }

        return [
            $status,
            $providerCode,
            strtolower(implode("\n", $messages)),
            implode("\n", $types),
        ];
    }

    private function recordReadinessAttempt(
        AiModel $model,
        string $mode,
        bool $schemaPassed,
        int $startedAt,
        ?string $errorCode,
    ): void {
        try {
            $this->readinessRecorder->recordAttempt(
                $model,
                $mode,
                $schemaPassed,
                (int) round((hrtime(true) - $startedAt) / 1_000_000),
                $errorCode,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
