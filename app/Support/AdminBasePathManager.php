<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * 后台入口路径管理。
 *
 * 路由前缀在 Laravel 启动时注册，不能只写入数据库；这里同步更新 .env 并清理缓存，
 * 确保下一次请求即可使用新的后台入口。
 */
final class AdminBasePathManager
{
    public const DEFAULT_PATH = 'geo_admin';

    /**
     * @return array<int, string>
     */
    public static function reservedSegments(): array
    {
        return [
            'api',
            'archive',
            'article',
            'assets',
            'build',
            'category',
            'css',
            'favicon.ico',
            'images',
            'js',
            'storage',
            'vendor',
        ];
    }

    public static function normalize(string $path): string
    {
        $normalized = trim($path);
        $normalized = trim($normalized, "/ \t\n\r\0\x0B");

        if ($normalized === '') {
            return self::DEFAULT_PATH;
        }

        if (! preg_match('/^[a-z0-9][a-z0-9_-]{1,46}[a-z0-9]$/', $normalized)) {
            throw new InvalidArgumentException('Invalid admin base path.');
        }

        if (in_array($normalized, self::reservedSegments(), true)) {
            throw new InvalidArgumentException('Reserved admin base path.');
        }

        return $normalized;
    }

    public static function persist(string $path): string
    {
        $normalized = self::normalize($path);
        self::writeEnvValue('ADMIN_BASE_PATH', $normalized);

        config(['geoflow.admin_base_path' => '/'.$normalized]);

        Artisan::call('config:clear');
        Artisan::call('route:clear');

        return $normalized;
    }

    private static function writeEnvValue(string $key, string $value): void
    {
        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            throw new RuntimeException('.env file does not exist.');
        }

        if (! is_writable($envPath)) {
            throw new RuntimeException('.env file is not writable.');
        }

        $content = (string) File::get($envPath);
        $line = $key.'='.$value;

        if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $content)) {
            $content = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $line, $content);
        } else {
            $content = rtrim($content).PHP_EOL.$line.PHP_EOL;
        }

        File::put($envPath, $content);
    }
}
