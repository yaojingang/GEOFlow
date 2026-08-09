<?php

namespace App\Console\GeoFlowCli;

class BaseUrlPolicy
{
    public static function validate(string $value, bool $allowInsecureHttp): string
    {
        $hasForbiddenCharacters = preg_match('/[\s\x00-\x1F\x7F]/u', $value);
        if ($value === '' || $hasForbiddenCharacters !== 0) {
            throw new CliException('base_url 包含空白字符、控制字符、无效编码或为空');
        }
        $value = trim($value);

        if (! str_contains($value, '://')) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            throw new CliException('base_url 格式无效');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new CliException('base_url 必须使用 http 或 https，并包含主机名');
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $forbiddenPart) {
            if (array_key_exists($forbiddenPart, $parts)) {
                throw new CliException('base_url 不能包含用户信息、查询参数或片段');
            }
        }

        if ($scheme === 'http' && ! $allowInsecureHttp && ! self::isLoopbackHost($host)) {
            throw new CliException('远程 HTTP 地址需要显式传入 --allow-insecure-http');
        }

        return rtrim($value, '/');
    }

    public static function requiresInsecureHttpOverride(string $value): bool
    {
        $parts = parse_url($value);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'http') {
            return false;
        }

        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        return $host !== '' && ! self::isLoopbackHost($host);
    }

    private static function isLoopbackHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        $octets = explode('.', $host);

        return (int) $octets[0] === 127;
    }
}
