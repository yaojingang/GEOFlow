<?php

if (!function_exists('db_driver')) {
    function db_driver(): string {
        $driver = strtolower((string) getenv('DB_DRIVER'));
        return $driver !== '' ? $driver : 'pgsql';
    }
}

if (!function_exists('db_is_pgsql')) {
    function db_is_pgsql(): bool {
        return db_driver() === 'pgsql';
    }
}

if (!function_exists('db_is_sqlite')) {
    function db_is_sqlite(): bool {
        return db_driver() === 'sqlite';
    }
}

if (!function_exists('db_get_sqlite_path')) {
    function db_get_sqlite_path(): string {
        return __DIR__ . '/../data/db/blog.db';
    }
}

if (!function_exists('db_username')) {
    function db_username(): ?string {
        if (db_is_sqlite()) {
            return null;
        }

        $value = getenv('DB_USER');
        return $value === false ? 'geo_user' : $value;
    }
}

if (!function_exists('db_password')) {
    function db_password(): ?string {
        if (db_is_sqlite()) {
            return null;
        }

        $value = getenv('DB_PASSWORD');
        return $value === false ? 'geo_password' : $value;
    }
}

if (!function_exists('db_timezone')) {
    function db_timezone(): string {
        $value = getenv('TZ');
        $timezone = is_string($value) && trim($value) !== '' ? trim($value) : 'Asia/Shanghai';

        if (!preg_match('/^[A-Za-z0-9_+\\/-]+$/', $timezone)) {
            return 'Asia/Shanghai';
        }

        return $timezone;
    }
}

if (!function_exists('db_create_pgsql_pdo')) {
    function db_create_pgsql_pdo(): PDO {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $host = getenv('DB_HOST') ?: 'postgres';
        $port = getenv('DB_PORT') ?: '5432';
        $dbname = getenv('DB_NAME') ?: 'geo_system';
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        $pdo = new PDO($dsn, db_username(), db_password(), $options);
        $pdo->exec("SET NAMES 'UTF8'");
        $pdo->exec("SET TIME ZONE '" . db_timezone() . "'");
        return $pdo;
    }
}

if (!function_exists('db_create_sqlite_pdo')) {
    function db_create_sqlite_pdo(?string $path = null): PDO {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        $pdo = new PDO('sqlite:' . ($path ?: db_get_sqlite_path()), null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = FULL');
        return $pdo;
    }
}

if (!function_exists('db_create_runtime_pdo')) {
    function db_create_runtime_pdo(): PDO {
        if (!db_is_pgsql()) {
            throw new RuntimeException('运行时数据库仅支持 PostgreSQL，请设置 DB_DRIVER=pgsql');
        }

        return db_create_pgsql_pdo();
    }
}

if (!function_exists('db_now_plus_seconds_sql')) {
    function db_now_plus_seconds_sql(int $seconds): string {
        $seconds = max(1, $seconds);
        return "CURRENT_TIMESTAMP + INTERVAL '{$seconds} seconds'";
    }
}

if (!function_exists('db_now_minus_seconds_sql')) {
    function db_now_minus_seconds_sql(int $seconds): string {
        $seconds = max(1, $seconds);
        return "CURRENT_TIMESTAMP - INTERVAL '{$seconds} seconds'";
    }
}

if (!function_exists('db_now_plus_minutes_sql')) {
    function db_now_plus_minutes_sql(int $minutes): string {
        $minutes = max(1, $minutes);
        return "CURRENT_TIMESTAMP + INTERVAL '{$minutes} minutes'";
    }
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = ?
              AND column_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table, $column]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('db_last_insert_id')) {
    function db_last_insert_id(PDO $pdo, string $table): int {
        return (int) $pdo->lastInsertId($table . '_id_seq');
    }
}

if (!function_exists('db_normalize_content_asset_paths')) {
    function db_normalize_content_asset_paths(PDO $pdo): void {
        if (!db_is_pgsql()) {
            return;
        }

        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, '](uploads/', '](/uploads/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%](uploads/%'
        ");
        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, '](assets/', '](/assets/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%](assets/%'
        ");
        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, 'src=\"uploads/', 'src=\"/uploads/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%src=\"uploads/%'
        ");
        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, 'src=\"assets/', 'src=\"/assets/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%src=\"assets/%'
        ");
        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, 'src=''uploads/', 'src=''/uploads/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%src=''uploads/%'
        ");
        $pdo->exec("
            UPDATE articles
            SET content = REPLACE(content, 'src=''assets/', 'src=''/assets/'),
                updated_at = CURRENT_TIMESTAMP
            WHERE content LIKE '%src=''assets/%'
        ");
    }
}

if (!function_exists('db_upsert_ignore_sql')) {
    function db_upsert_ignore_sql(string $table, array $columns, array $conflictColumns = []): string {
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnList = implode(', ', $columns);
        $conflictClause = empty($conflictColumns) ? '' : ' (' . implode(', ', $conflictColumns) . ')';
        return "INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders}) ON CONFLICT{$conflictClause} DO NOTHING";
    }
}

if (!function_exists('db_health_check')) {
    function db_health_check(PDO $pdo): array {
        $pdo->query("SELECT 1");
        return [
            'ok' => true,
            'message' => '数据库连接检查: ok',
        ];
    }
}
