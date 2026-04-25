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
