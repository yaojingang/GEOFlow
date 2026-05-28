<?php

namespace App\Support\GeoFlow;

use PDO;

final class PostgresCompat
{
    public static function lastInsertId(PDO $pdo, string $table): int
    {
        return (int) $pdo->lastInsertId($table.'_id_seq');
    }

    public static function nowPlusSecondsSql(int $seconds): string
    {
        $seconds = max(1, $seconds);

        return "CURRENT_TIMESTAMP + INTERVAL '{$seconds} seconds'";
    }

    public static function nowPlusMinutesSql(int $minutes): string
    {
        $minutes = max(1, $minutes);

        return "CURRENT_TIMESTAMP + INTERVAL '{$minutes} minutes'";
    }

    public static function nowMinusSecondsSql(int $seconds): string
    {
        $seconds = max(1, $seconds);

        return "CURRENT_TIMESTAMP - INTERVAL '{$seconds} seconds'";
    }
}
