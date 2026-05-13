<?php

namespace App\Support\GeoFlow;

use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * OpenAI 兼容 Chat/Embedding 客户端所需的 base URL 规范化与运行时 provider 注册。
 */
final class OpenAiRuntimeProvider
{
    /**
     * 将历史或自定义 api_url 规范为 Chat Completions 可用的 base（根路径时补全 /v1）。
     */
    public static function resolveChatBaseUrl(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');
        if (preg_match('#/v1/chat/completions$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/chat/completions'));
        }
        if (preg_match('#/chat/completions$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/chat/completions'));
        }

        $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return $normalized.'/v1';
        }

        return $normalized;
    }

    /**
     * 将历史或自定义 api_url 规范为 Embeddings 可用的 base（根路径时补全 /v1）。
     */
    public static function resolveEmbeddingBaseUrl(string $apiUrl): string
    {
        $normalized = trim($apiUrl);
        if ($normalized === '') {
            return '';
        }

        $normalized = rtrim($normalized, '/');
        if (preg_match('#/v1/embeddings$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/embeddings'));
        }
        if (preg_match('#/embeddings$#', $normalized) === 1) {
            return substr($normalized, 0, -strlen('/embeddings'));
        }

        $path = (string) (parse_url($normalized, PHP_URL_PATH) ?? '');
        if ($path === '' || $path === '/') {
            return $normalized.'/v1';
        }

        return $normalized;
    }

    /**
     * Laravel AI 的 openai driver 默认走 Responses API；多数第三方兼容接口仍只支持 Chat Completions。
     */
    public static function resolveChatDriver(string $apiUrl, string $modelId = ''): string
    {
        $normalized = strtolower(trim($apiUrl));
        $model = strtolower(trim($modelId));
        $host = strtolower((string) (parse_url($normalized, PHP_URL_HOST) ?? ''));

        if ($host === 'api.openai.com') {
            return 'openai';
        }

        if (str_contains($host, 'openrouter.ai')) {
            return 'openrouter';
        }

        if (str_contains($host, 'api.deepseek.com') || str_starts_with($model, 'deepseek')) {
            return 'deepseek';
        }

        // 通用 Chat Completions 兼容接口：复用 DeepSeek driver 的 chat/completions 请求形态。
        return 'deepseek';
    }

    /**
     * 向 config('ai.providers') 注入单条运行时配置并返回 provider 名称。
     *
     * @param  string  $registrySlot  调用场景标识，避免同名覆盖（如 worker、title_ai、embedding）
     * @param  string  $driver         Laravel AI 驱动名（如 openai）
     */
    public static function registerProvider(string $registrySlot, string $driver, string $providerUrl, string $apiKey): string
    {
        $providerName = 'runtime_'.$registrySlot.'_'.md5($driver.'|'.$providerUrl.'|'.$apiKey);
        Config::set('ai.providers.'.$providerName, [
            'driver' => $driver,
            'key' => $apiKey,
            'url' => $providerUrl,
        ]);

        return $providerName;
    }

    /**
     * 将底层 AI SDK 的非 JSON/HTML 响应异常转换为面向配置排查的提示。
     */
    public static function normalizeApiException(Throwable $exception, string $providerUrl = ''): string
    {
        $message = trim($exception->getMessage());
        $lowerMessage = mb_strtolower($message, 'UTF-8');

        if (self::looksLikeNonJsonResponse($lowerMessage)) {
            $hint = 'AI 接口返回了非 JSON 响应（可能是 HTML 页面）。请检查 AI 模型的 API Base URL 是否填写为接口 Base URL，而不是官网、控制台、代理页或网页地址。';
            $endpoint = self::chatCompletionsEndpointHint($providerUrl);

            return $endpoint !== '' ? $hint.' 当前请求地址约为：'.$endpoint : $hint;
        }

        return $message !== '' ? $message : $exception::class;
    }

    /**
     * 兼容部分 OpenAI 兼容网关把 SSE chunk 原文透传到 text 字段的情况。
     */
    public static function normalizeGeneratedText(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || ! self::looksLikeSseCompletionPayload($trimmed)) {
            return $trimmed;
        }

        $segments = [];
        foreach (preg_split('/\R/u', $trimmed) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || ! str_starts_with($line, 'data:')) {
                continue;
            }

            $payload = trim(substr($line, strlen('data:')));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }

            $data = json_decode($payload, true);
            if (! is_array($data)) {
                continue;
            }

            if (($data['type'] ?? null) === 'response.output_text.delta' && isset($data['delta'])) {
                $segments[] = self::stringifyContentPart($data['delta']);
                continue;
            }

            $choices = $data['choices'] ?? [];
            if (! is_array($choices)) {
                continue;
            }

            foreach ($choices as $choice) {
                if (! is_array($choice)) {
                    continue;
                }

                $delta = $choice['delta'] ?? [];
                if (is_array($delta) && array_key_exists('content', $delta)) {
                    $segments[] = self::stringifyContentPart($delta['content']);
                }

                $message = $choice['message'] ?? [];
                if (is_array($message) && array_key_exists('content', $message)) {
                    $segments[] = self::stringifyContentPart($message['content']);
                }

                if (array_key_exists('text', $choice)) {
                    $segments[] = self::stringifyContentPart($choice['text']);
                }
            }
        }

        return trim(implode('', array_filter($segments, static fn (string $segment): bool => $segment !== '')));
    }

    public static function looksLikeSseCompletionPayload(string $content): bool
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return false;
        }

        $lines = array_values(array_filter(
            preg_split('/\R/u', $trimmed) ?: [],
            static fn (string $line): bool => trim($line) !== ''
        ));

        if ($lines === [] || ! str_starts_with(trim($lines[0]), 'data:')) {
            return false;
        }

        return str_contains($trimmed, 'data: [DONE]')
            || str_contains($trimmed, 'chat.completion.chunk')
            || str_contains($trimmed, 'response.output_text.delta');
    }

    private static function looksLikeNonJsonResponse(string $lowerMessage): bool
    {
        return str_contains($lowerMessage, '<!doctype')
            || str_contains($lowerMessage, '<html')
            || str_contains($lowerMessage, 'api响应格式错误')
            || str_contains($lowerMessage, 'non-json')
            || str_contains($lowerMessage, 'unexpected token <')
            || (str_contains($lowerMessage, 'must be of type array') && str_contains($lowerMessage, 'null given'));
    }

    private static function chatCompletionsEndpointHint(string $providerUrl): string
    {
        $providerUrl = trim($providerUrl);
        if ($providerUrl === '') {
            return '';
        }

        return rtrim($providerUrl, '/').'/chat/completions';
    }

    private static function stringifyContentPart(mixed $content): string
    {
        if (is_string($content) || is_numeric($content)) {
            return (string) $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $text = '';
        foreach ($content as $part) {
            if (is_string($part) || is_numeric($part)) {
                $text .= (string) $part;
                continue;
            }

            if (is_array($part)) {
                $text .= self::stringifyContentPart($part['text'] ?? $part['content'] ?? '');
            }
        }

        return $text;
    }
}
