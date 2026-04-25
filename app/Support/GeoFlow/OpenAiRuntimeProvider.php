<?php

namespace App\Support\GeoFlow;

use Illuminate\Support\Facades\Config;

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
}
