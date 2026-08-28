<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Contracts\ArticleAiQualityReviewer;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class LaravelArticleAiQualityReviewer implements ArticleAiQualityReviewer
{
    public function __construct(
        private ApiKeyCrypto $apiKeyCrypto,
        private AiUsageQuotaService $usageQuota,
    ) {}

    public function review(AiModel $model, string $instructions): array
    {
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            throw new RuntimeException('ai_quality_model_unavailable_or_quota_exceeded');
        }
        $externalRequestAttempted = false;

        try {
            [$provider, $driver, $baseUrl] = $this->runtimeProvider($model);
            $maxTokens = max(2048, min(16384, (int) ($model->max_tokens ?: 8192)));
            $timeout = (int) config('geoflow.ai_quality_request_timeout_seconds', 180);
            $mode = 'structured';
            $attemptStartedAt = hrtime(true);

            try {
                $externalRequestAttempted = true;
                $response = (new ArticleQualityReviewerAgent($instructions, $maxTokens))->prompt(
                    '请执行本分段质检并返回完整结构化结果。',
                    [],
                    $provider,
                    (string) $model->model_id,
                    $timeout,
                );
                $result = $response->structured;
            } catch (Throwable $structuredException) {
                $remainingSeconds = $timeout - ((hrtime(true) - $attemptStartedAt) / 1_000_000_000);
                if ($remainingSeconds < 1) {
                    throw new RuntimeException(
                        OpenAiRuntimeProvider::normalizeApiException($structuredException, $baseUrl),
                        0,
                        $structuredException,
                    );
                }
                $mode = 'json_fallback';
                $this->usageQuota->recordModelAttempt($reservation);
                $fallbackReservation = $this->usageQuota->reserveModel($model);
                if ($fallbackReservation === null) {
                    throw new RuntimeException('ai_quality_model_unavailable_or_quota_exceeded', 0, $structuredException);
                }
                $reservation = $fallbackReservation;
                $externalRequestAttempted = false;
                try {
                    $externalRequestAttempted = true;
                    $response = (new ArticleQualityJsonReviewerAgent($instructions, $maxTokens))->prompt(
                        '请执行本分段质检，只返回 JSON。',
                        [],
                        $provider,
                        (string) $model->model_id,
                        max(1, (int) floor($remainingSeconds)),
                    );
                    $result = $this->decodeJson((string) $response->text);
                } catch (Throwable $fallbackException) {
                    throw new RuntimeException(
                        OpenAiRuntimeProvider::normalizeApiException($fallbackException, $baseUrl),
                        0,
                        $structuredException,
                    );
                }
            }

            if (! is_array($result) || $result === []) {
                throw new RuntimeException('ai_quality_empty_or_invalid_result');
            }

            $this->usageQuota->recordModelSuccess($reservation);

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

            throw $exception;
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
            throw new RuntimeException('ai_quality_json_fallback_invalid', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
