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

    /**
     * Build a same-origin route path for admin JavaScript endpoints and forms.
     *
     * This keeps URLs independent from the configured APP_URL host while still
     * preserving an APP_URL subdirectory such as https://example.com/geoflow.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function routePath(string $name, array $parameters = []): string
    {
        $path = route($name, $parameters, false);
        $appPath = trim((string) (parse_url((string) config('app.url', ''), PHP_URL_PATH) ?: ''), '/');
        if ($appPath === '') {
            return $path;
        }

        $appPrefix = '/'.$appPath;
        if ($path === $appPrefix || str_starts_with($path, $appPrefix.'/')) {
            return $path;
        }

        $adminBase = trim(self::basePath(), '/');
        if ($adminBase !== '' && ($path === '/'.$adminBase || str_starts_with($path, '/'.$adminBase.'/')) && str_ends_with($appPrefix, '/'.$adminBase)) {
            $appPrefix = substr($appPrefix, 0, -strlen('/'.$adminBase));
            if ($appPrefix === '') {
                return $path;
            }
        }

        return rtrim($appPrefix, '/').(str_starts_with($path, '/') ? $path : '/'.$path);
    }

    public static function supportedLocales(): array
    {
        return [
            'zh_CN' => '简体中文',
            'en' => 'English',
            'ja' => '日本語',
            'es' => 'Español',
            'ru' => 'Русский',
            'pt_BR' => 'Português (BR)',
        ];
    }

    public static function isSupportedLocale(string $locale): bool
    {
        return array_key_exists($locale, self::supportedLocales());
    }
}
