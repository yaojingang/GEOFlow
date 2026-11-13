<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Services\Outbound\OutboundRequestFailedException;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

final class ArticleGenerationDiagnostics
{
    /** @param array{task_run_id?:int,task_id?:int,request_id?:string} $context */
    public function response(AiModel $model, AgentResponse $response, string $rawText, string $content, int $maxTokens, int $timeout, int $startedAt, array $context): void
    {
        try {
            $raw = $response->raw;
            $finishReason = $raw?->json('choices.0.finish_reason')
                ?? $response->steps->last()?->finishReason?->value;
            $finishReason = in_array($finishReason, ['stop', 'length', 'tool_calls', 'content_filter', 'sensitive', 'network_error', 'model_context_window_exceeded'], true)
                ? $finishReason : 'unknown';
            $providerContent = $raw?->json('choices.0.message.content');
            $providerReasoning = $raw?->json('choices.0.message.reasoning_content');
            $reasoningTokens = $raw?->json('usage.completion_tokens_details.reasoning_tokens');
            $budgetReached = $finishReason === 'length' || $response->usage->completionTokens >= $maxTokens;
            $cleanLength = mb_strlen(trim($content));
            $reasoningOnly = $cleanLength === 0 && ($providerContent === null || (is_string($providerContent) && trim($providerContent) === ''))
                && is_string($providerReasoning) && trim($providerReasoning) !== '';

            Log::channel('article_generation')->info('GeoFlow article generation response.', [
                ...$this->context($model, $maxTokens, $timeout, $startedAt, $context),
                ...$this->timings($raw?->handlerStats() ?? []),
                'http_status' => $raw?->status(),
                'provider_request_id' => $this->safeId($raw?->json('id')),
                'finish_reason' => $finishReason,
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'reasoning_tokens' => is_int($reasoningTokens) && $reasoningTokens >= 0 ? $reasoningTokens : null,
                'provider_content_chars' => is_string($providerContent) ? mb_strlen($providerContent) : null,
                'provider_reasoning_chars' => is_string($providerReasoning) ? mb_strlen($providerReasoning) : null,
                'sdk_content_chars' => mb_strlen($rawText),
                'clean_content_chars' => $cleanLength,
                'output_budget_reached' => $budgetReached,
                'reasoning_budget_exhausted' => $budgetReached && $reasoningOnly,
            ]);
        } catch (Throwable) {
            // Diagnostic collection must preserve the generation result and quota accounting.
        }
    }

    /** @param array{task_run_id?:int,task_id?:int,request_id?:string} $context */
    public function failure(AiModel $model, Throwable $exception, int $maxTokens, int $timeout, int $startedAt, array $context): void
    {
        try {
            $failure = null;
            for ($current = $exception, $depth = 0; $current !== null && $depth < 8; $current = $current->getPrevious(), $depth++) {
                if ($current instanceof OutboundRequestFailedException) {
                    $failure = $current;
                    break;
                }
            }
            $failure ??= new OutboundRequestFailedException($exception);
            Log::channel('article_generation')->warning('GeoFlow article generation transport failed.', [
                ...$this->context($model, $maxTokens, $timeout, $startedAt, $context),
                ...$this->timings($failure?->transferStats ?? []),
                'exception_type' => $exception::class,
                'cause_type' => $failure?->causeType,
                'transport_category' => $failure?->transportCategory ?? 'unknown',
                'http_status' => $failure?->httpStatus,
                'curl_errno' => $failure?->curlErrno,
            ]);
        } catch (Throwable) {
            // Transport messages and response bodies can contain credentials or customer content.
        }
    }

    /**
     * @param  array{task_run_id?:int,task_id?:int,request_id?:string}  $context
     * @return array<string,int|string|null>
     */
    private function context(AiModel $model, int $maxTokens, int $timeout, int $startedAt, array $context): array
    {
        return [
            'ai_model_id' => (int) $model->id,
            'task_run_id' => isset($context['task_run_id']) ? (int) $context['task_run_id'] : null,
            'task_id' => isset($context['task_id']) ? (int) $context['task_id'] : null,
            'request_id' => $this->safeId($context['request_id'] ?? null),
            'worker_id' => gethostname().':queue:'.getmypid(),
            'provider_host' => parse_url((string) $model->api_url, PHP_URL_HOST) ?: null,
            'configured_timeout_seconds' => $timeout,
            'max_output_tokens' => $maxTokens,
            'provider_elapsed_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }

    /** @param array<string,mixed> $stats @return array<string,int|null> */
    private function timings(array $stats): array
    {
        $result = [];
        foreach (['total_time' => 'http_total_ms', 'connect_time' => 'http_connect_ms', 'starttransfer_time' => 'http_starttransfer_ms'] as $source => $key) {
            $value = $stats[$source] ?? null;
            $result[$key] = is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 86400
                ? (int) round((float) $value * 1000) : null;
        }

        return $result;
    }

    private function safeId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/', $value) === 1 ? $value : null;
    }
}
