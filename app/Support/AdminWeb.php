<?php

namespace App\Support;

use App\Models\SiteSetting;
use Throwable;

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
        try {
            $title = SiteSetting::query()->where('setting_key', 'site_title')->value('setting_value');
            if (is_string($title) && trim($title) !== '') {
                return trim($title);
            }
        } catch (Throwable) {
            // 迁移未跑或表不存在
        }

        return (string) config('geoflow.site_name', config('app.name'));
    }

    public static function basePath(): string
    {
        return trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
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
        ];
    }
}
