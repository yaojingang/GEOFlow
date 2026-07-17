<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemUpdateDeploymentDiagnosticsService
{
    /**
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>
     */
    public function build(array $deployment): array
    {
        $items = [
            $this->appUrlItem(),
            $this->appKeyItem(),
            $this->databaseItem(),
            $this->writableItem('storage_writable', storage_path('app'), __('admin.system_updates.diagnostics.storage_writable')),
            $this->writableItem('bootstrap_cache_writable', base_path('bootstrap/cache'), __('admin.system_updates.diagnostics.bootstrap_cache_writable')),
        ];

        $counts = [
            'pass' => $this->countStatus($items, 'pass'),
            'warn' => $this->countStatus($items, 'warn'),
            'fail' => $this->countStatus($items, 'fail'),
            'info' => $this->countStatus($items, 'info'),
        ];

        return [
            'status' => $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'pass'),
            ...$counts,
            'items' => $items,
            'facts' => $this->facts(),
            'commands' => $this->commands($deployment),
            'log' => $this->logSummary(),
            'docs' => [
                'url' => 'https://github.com/yaojingang/GEOFlow/blob/main/docs/deployment/docker-prod-init-troubleshooting.md',
            ],
        ];
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function appUrlItem(): array
    {
        $appUrl = trim((string) config('app.url', ''));
        $isPlaceholder = $appUrl === '' || str_contains($appUrl, 'your-domain.com');
        $isLocalhostInProduction = app()->environment('production')
            && (str_contains($appUrl, 'localhost') || str_contains($appUrl, '127.0.0.1'));

        if ($isPlaceholder) {
            return $this->item(
                'app_url',
                'fail',
                __('admin.system_updates.diagnostics.app_url'),
                __('admin.system_updates.diagnostics.app_url_placeholder')
            );
        }

        if ($isLocalhostInProduction) {
            return $this->item(
                'app_url',
                'warn',
                __('admin.system_updates.diagnostics.app_url'),
                __('admin.system_updates.diagnostics.app_url_localhost')
            );
        }

        return $this->item(
            'app_url',
            'pass',
            __('admin.system_updates.diagnostics.app_url'),
            __('admin.system_updates.diagnostics.app_url_pass')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function appKeyItem(): array
    {
        $valid = $this->hasValidAppKey();

        return $this->item(
            'app_key',
            $valid ? 'pass' : 'fail',
            __('admin.system_updates.diagnostics.app_key'),
            $valid
                ? __('admin.system_updates.diagnostics.app_key_pass')
                : __('admin.system_updates.diagnostics.app_key_fail')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function databaseItem(): array
    {
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            return $this->item(
                'database',
                'fail',
                __('admin.system_updates.diagnostics.database'),
                __('admin.system_updates.diagnostics.database_fail', ['message' => $this->shorten($e->getMessage(), 160)])
            );
        }

        try {
            $hasMigrationsTable = Schema::hasTable('migrations');
        } catch (\Throwable $e) {
            return $this->item(
                'database',
                'fail',
                __('admin.system_updates.diagnostics.database'),
                __('admin.system_updates.diagnostics.database_fail', ['message' => $this->shorten($e->getMessage(), 160)])
            );
        }

        if (! $hasMigrationsTable) {
            return $this->item(
                'database',
                'warn',
                __('admin.system_updates.diagnostics.database'),
                __('admin.system_updates.diagnostics.database_migrations_missing')
            );
        }

        return $this->item(
            'database',
            'pass',
            __('admin.system_updates.diagnostics.database'),
            __('admin.system_updates.diagnostics.database_pass')
        );
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function writableItem(string $key, string $path, string $title): array
    {
        if (! is_dir($path)) {
            return $this->item(
                $key,
                'fail',
                $title,
                __('admin.system_updates.diagnostics.directory_missing', ['path' => $path])
            );
        }

        return $this->item(
            $key,
            is_writable($path) ? 'pass' : 'fail',
            $title,
            is_writable($path)
                ? __('admin.system_updates.diagnostics.directory_writable', ['path' => $path])
                : __('admin.system_updates.diagnostics.directory_not_writable', ['path' => $path])
        );
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function facts(): array
    {
        $dbConnection = (string) config('database.default', '');
        $redisConnection = (string) config('database.redis.client', 'phpredis');
        $queueConnection = (string) config('queue.default', '');
        $cacheStore = (string) config('cache.default', config('cache.driver', ''));
        $sessionDriver = (string) config('session.driver', '');

        return [
            ['label' => __('admin.system_updates.diagnostics.fact_app_env'), 'value' => app()->environment()],
            ['label' => __('admin.system_updates.diagnostics.fact_app_url'), 'value' => (string) config('app.url', '')],
            ['label' => __('admin.system_updates.diagnostics.fact_admin_path'), 'value' => (string) config('geoflow.admin_base_path', '/geo_admin')],
            ['label' => __('admin.system_updates.diagnostics.fact_db'), 'value' => $dbConnection.' / '.$this->configValue("database.connections.{$dbConnection}.host")],
            ['label' => __('admin.system_updates.diagnostics.fact_redis'), 'value' => $redisConnection.' / '.$this->configValue('database.redis.default.host')],
            ['label' => __('admin.system_updates.diagnostics.fact_queue'), 'value' => $queueConnection],
            ['label' => __('admin.system_updates.diagnostics.fact_cache'), 'value' => $cacheStore],
            ['label' => __('admin.system_updates.diagnostics.fact_session'), 'value' => $sessionDriver],
        ];
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @return array<int, array{title: string, description: string, commands: array<int, string>}>
     */
    private function commands(array $deployment): array
    {
        $mode = (string) ($deployment['mode'] ?? '');
        $isDocker = str_contains($mode, 'docker') || file_exists('/.dockerenv') || getenv('container') !== false;

        if ($isDocker) {
            $commands = [
                [
                    'title' => __('admin.system_updates.diagnostics.command_define_prefix'),
                    'description' => __('admin.system_updates.diagnostics.command_define_prefix_desc'),
                    'commands' => [
                        "export COMPOSE_PROD='docker compose --env-file .env.prod -f docker-compose.prod.yml'",
                        "export COMPOSE_PROD='sudo docker compose --env-file .env.prod -f docker-compose.prod.yml'",
                    ],
                ],
                [
                    'title' => __('admin.system_updates.diagnostics.command_env_check'),
                    'description' => __('admin.system_updates.diagnostics.command_env_check_desc'),
                    'commands' => [
                        '$COMPOSE_PROD config | grep -E \'APP_URL|DB_HOST|DB_DATABASE|DB_USERNAME|WEB_PORT|ADMIN_BASE_PATH\'',
                        '$COMPOSE_PROD up -d --force-recreate app web queue scheduler',
                    ],
                ],
            ];

            if (! $this->hasValidAppKey()) {
                $commands[] = [
                    'title' => __('admin.system_updates.diagnostics.command_key_generate'),
                    'description' => __('admin.system_updates.diagnostics.command_key_generate_desc'),
                    'commands' => [
                        '$COMPOSE_PROD run --rm app php artisan key:generate --force',
                    ],
                ];
            }

            return [
                ...$commands,
                [
                    'title' => __('admin.system_updates.diagnostics.command_init'),
                    'description' => __('admin.system_updates.diagnostics.command_init_desc'),
                    'commands' => [
                        '$COMPOSE_PROD run --rm app php artisan migrate --force',
                        '$COMPOSE_PROD run --rm app php artisan geoflow:install',
                        '$COMPOSE_PROD run --rm app php artisan storage:link --force',
                        '$COMPOSE_PROD run --rm app php artisan optimize',
                    ],
                ],
                [
                    'title' => __('admin.system_updates.diagnostics.command_logs'),
                    'description' => __('admin.system_updates.diagnostics.command_logs_desc'),
                    'commands' => [
                        '$COMPOSE_PROD logs --tail=200 app',
                        '$COMPOSE_PROD exec app sh -lc \'tail -n 200 storage/logs/laravel.log\'',
                        '$COMPOSE_PROD logs --tail=200 init',
                    ],
                ],
            ];
        }

        return [
            [
                'title' => __('admin.system_updates.diagnostics.command_non_docker_init'),
                'description' => __('admin.system_updates.diagnostics.command_non_docker_init_desc'),
                'commands' => [
                    'php artisan key:generate --force',
                    'php artisan migrate --force',
                    'php artisan geoflow:install',
                    'php artisan storage:link --force',
                    'php artisan optimize',
                ],
            ],
            [
                'title' => __('admin.system_updates.diagnostics.command_non_docker_logs'),
                'description' => __('admin.system_updates.diagnostics.command_non_docker_logs_desc'),
                'commands' => [
                    'tail -n 200 storage/logs/laravel.log',
                    'php artisan about',
                ],
            ],
        ];
    }

    /**
     * @return array{status: string, path: string, lines: array<int, string>}
     */
    private function logSummary(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path) || ! is_readable($path)) {
            return [
                'status' => 'info',
                'path' => $path,
                'lines' => [],
            ];
        }

        $recent = $this->tailLines($path, 200);
        $errorLines = array_values(array_filter($recent, static function (string $line): bool {
            $line = strtolower($line);

            return str_contains($line, '.error')
                || str_contains($line, 'exception')
                || str_contains($line, 'sqlstate')
                || str_contains($line, 'server error')
                || str_contains($line, ' 500 ');
        }));

        return [
            'status' => $errorLines === [] ? 'pass' : 'warn',
            'path' => $path,
            'lines' => array_map(fn (string $line): string => $this->shorten($line, 260), array_slice($errorLines, -8)),
        ];
    }

    /** @return list<string> */
    private function tailLines(string $path, int $limit): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $position = 0;
        $buffer = '';
        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                return [];
            }
            $end = ftell($handle);
            if (! is_int($end)) {
                return [];
            }
            $position = $end;
            $bytesRead = 0;
            $maxBytes = 2 * 1024 * 1024;

            while ($position > 0 && substr_count($buffer, "\n") <= $limit && $bytesRead < $maxBytes) {
                $length = min(8192, $position, $maxBytes - $bytesRead);
                $position -= $length;
                if (fseek($handle, $position) !== 0) {
                    break;
                }
                $chunk = fread($handle, $length);
                if (! is_string($chunk) || $chunk === '') {
                    break;
                }
                $buffer = $chunk.$buffer;
                $bytesRead += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        if ($position > 0) {
            $firstLineBreak = strpos($buffer, "\n");
            if ($firstLineBreak === false) {
                return [];
            }
            $buffer = substr($buffer, $firstLineBreak + 1);
        }

        $lines = preg_split('/\r\n|\n|\r/', $buffer) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values(array_slice($lines, -max(1, $limit)));
    }

    /**
     * @param  array<int, array{status: string}>  $items
     */
    private function countStatus(array $items, string $status): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === $status));
    }

    /**
     * @return array{key: string, status: string, title: string, message: string}
     */
    private function item(string $key, string $status, string $title, string $message): array
    {
        return compact('key', 'status', 'title', 'message');
    }

    private function configValue(string $key): string
    {
        $value = config($key);

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : __('admin.common.none');
    }

    private function hasValidAppKey(): bool
    {
        $appKey = trim((string) config('app.key', ''));

        return str_starts_with($appKey, 'base64:') && strlen($appKey) >= 40;
    }

    private function shorten(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?: $value);
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 3).'...';
    }
}
