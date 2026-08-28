<?php

namespace App\Ai\Workspace;

use Illuminate\Support\Str;

final class AiWorkspaceUrlSanitizer
{
    private const SENSITIVE_QUERY_KEYS = [
        'api_key', 'apikey', 'access_token', 'token', 'secret', 'password',
        'signature', 'credential', 'authorization',
    ];

    public static function clean(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $url = trim($value);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        if (Str::startsWith($url, '/')) {
            if (Str::startsWith($url, '//') || isset($parts['host'])) {
                return '';
            }
            $safe = (string) ($parts['path'] ?? '');
        } else {
            $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
            $host = (string) ($parts['host'] ?? '');
            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
                return '';
            }
            $safe = $scheme.'://'.$host;
            if (isset($parts['port'])) {
                $safe .= ':'.(int) $parts['port'];
            }
            $safe .= (string) ($parts['path'] ?? '');
        }

        $query = self::safeQuery((string) ($parts['query'] ?? ''));

        return $query === '' ? $safe : $safe.'?'.$query;
    }

    private static function safeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        return collect(explode('&', $query))
            ->filter(static function (string $pair): bool {
                $key = rawurldecode(explode('=', $pair, 2)[0]);
                $key = Str::lower(str_replace(['-', '.'], '_', $key));

                return ! collect(self::SENSITIVE_QUERY_KEYS)
                    ->contains(static fn (string $sensitive): bool => Str::contains($key, $sensitive));
            })
            ->implode('&');
    }
}
