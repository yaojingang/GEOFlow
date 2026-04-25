<?php

namespace App\Support;

final class AdminWeb
{
    /**
     * 兼容 bak 语言占位符：同时支持 Laravel `:key` 与旧版 `{key}`。
     *
     * @param  array<string, scalar|null>  $replace
     */
    public static function trans(string $key, array $replace = []): string
    {
        $target = str_starts_with($key, 'admin.') ? $key : 'admin.'.$key;
        $text = (string) __($target, $replace);

        foreach ($replace as $name => $value) {
            $text = str_replace('{'.$name.'}', (string) $value, $text);
        }

        return $text;
    }

    public static function siteName(): string
    {
        return 'GEOFlow';
    }

    public static function basePath(): string
    {
        try {
            return AdminBasePathManager::normalize((string) config('geoflow.admin_base_path', AdminBasePathManager::DEFAULT_PATH));
        } catch (\Throwable) {
            return AdminBasePathManager::DEFAULT_PATH;
        }
    }

    public static function url(string $path = ''): string
    {
        $base = self::basePath();
        $path = ltrim($path, '/');

        return url($base.($path !== '' ? '/'.$path : ''));
    }

    public static function supportedLocales(): array
    {
        return [
            'zh_CN' => '简体中文',
            'en' => 'English',
            'ja' => '日本語',
            'es' => 'Español',
            'ru' => 'Русский',
        ];
    }

    public static function isSupportedLocale(string $locale): bool
    {
        return array_key_exists($locale, self::supportedLocales());
    }
}
