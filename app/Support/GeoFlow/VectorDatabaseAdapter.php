<?php

namespace App\Support\GeoFlow;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Database-backed vector capability and SQL dialect adapter.
 *
 * This class intentionally probes lazily. Application boot must remain safe on
 * SQLite tests and on databases where the optional vector column has not been
 * migrated yet.
 */
final class VectorDatabaseAdapter implements VectorStoreAdapter
{
    private const VECTOR_COLUMN = 'embedding_vector';

    private const CAPABILITY_CACHE_TTL_SECONDS = 60;

    private ?VectorStoreCapabilities $cachedCapabilities = null;

    private ?float $cachedAt = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $capabilityCacheTtlSeconds = self::CAPABILITY_CACHE_TTL_SECONDS,
    ) {}

    public function capabilities(): VectorStoreCapabilities
    {
        if ($this->cachedCapabilities instanceof VectorStoreCapabilities
            && $this->cachedAt !== null
            && $this->capabilityCacheTtlSeconds > 0
            && microtime(true) - $this->cachedAt < $this->capabilityCacheTtlSeconds) {
            return $this->cachedCapabilities;
        }

        $driver = $this->driver();

        $this->cachedCapabilities = match ($driver) {
            'pgsql' => $this->probePostgres($driver),
            'mysql' => $this->probeMySql($driver),
            default => VectorStoreCapabilities::unavailable($driver, 'unsupported_driver'),
        };
        $this->cachedAt = microtime(true);

        return $this->cachedCapabilities;
    }

    public function isAvailable(): bool
    {
        return $this->capabilities()->available;
    }

    public function driver(): string
    {
        try {
            return strtolower((string) $this->connection->getDriverName());
        } catch (Throwable) {
            return 'unknown';
        }
    }

    public function dimensions(): int
    {
        return max(1, $this->capabilities()->dimensions);
    }

    /**
     * @param  list<int|float|numeric-string>  $vector
     */
    public function encode(array $vector, ?int $dimensions = null): ?string
    {
        if ($vector === []) {
            return null;
        }

        $dimensions = max(1, $dimensions ?? self::DEFAULT_DIMENSIONS);
        $normalized = [];
        foreach ($vector as $value) {
            if (! is_numeric($value)) {
                return null;
            }

            $number = (float) $value;
            if (! is_finite($number)) {
                return null;
            }

            $normalized[] = $number;
        }

        $normalized = array_slice($normalized, 0, $dimensions);
        while (count($normalized) < $dimensions) {
            $normalized[] = 0.0;
        }

        try {
            return json_encode(
                $normalized,
                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function writeExpression(string $placeholder = '?'): string
    {
        $this->assertPlaceholder($placeholder);

        return $this->renderWriteExpression($placeholder);
    }

    public function writeValue(?string $vectorLiteral): string|Expression|null
    {
        if ($vectorLiteral === null || $vectorLiteral === '') {
            return null;
        }

        return match ($this->driver()) {
            'mysql' => $this->mysqlWriteExpression($vectorLiteral),
            'pgsql' => $vectorLiteral,
            default => null,
        };
    }

    public function similarityExpression(string $column = 'embedding_vector', string $placeholder = '?'): string
    {
        $column = $this->assertVectorColumn($column);
        $this->assertPlaceholder($placeholder);

        return match ($this->driver()) {
            'mysql' => 'VEC_DISTANCE_COSINE('.$column.', VEC_FROMTEXT('.$placeholder.'))',
            'pgsql' => $column.' <=> CAST('.$placeholder.' AS vector)',
            default => throw new InvalidArgumentException('Vector similarity is not supported by this database driver.'),
        };
    }

    public function limitClause(int $limit): string
    {
        return 'LIMIT '.max(1, $limit);
    }

    private function probePostgres(string $driver): VectorStoreCapabilities
    {
        try {
            $typeRow = $this->connection->selectOne(
                "SELECT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'vector') AS ok",
            );
            if (! $this->rowIsTruthy($typeRow, 'ok')) {
                return VectorStoreCapabilities::unavailable($driver, 'pgvector_type_missing');
            }

            if (! $this->hasVectorColumn($driver)) {
                return VectorStoreCapabilities::unavailable($driver, 'embedding_column_missing');
            }

            return new VectorStoreCapabilities($driver, true, self::DEFAULT_DIMENSIONS);
        } catch (Throwable) {
            return VectorStoreCapabilities::unavailable($driver, 'capability_probe_failed');
        }
    }

    private function probeMySql(string $driver): VectorStoreCapabilities
    {
        try {
            $functionRow = $this->connection->selectOne(
                'SELECT VECTOR_DIM(VEC_FROMTEXT(?)) AS vector_dimensions',
                ['[0,0]'],
            );
            if ((int) ($this->rowValue($functionRow, 'vector_dimensions') ?? 0) !== 2) {
                return VectorStoreCapabilities::unavailable($driver, 'mysql_vector_functions_missing');
            }

            $columnRow = $this->connection->selectOne(
                '
                    SELECT DATA_TYPE AS data_type, COLUMN_TYPE AS column_type
                    FROM information_schema.columns
                    WHERE table_schema = DATABASE()
                      AND table_name = ?
                      AND column_name = ?
                    LIMIT 1
                ',
                ['knowledge_chunks', self::VECTOR_COLUMN],
            );
            if ($columnRow === null || ! $this->isMySqlVectorColumn($columnRow)) {
                return VectorStoreCapabilities::unavailable($driver, 'mysql_vector_column_missing');
            }

            return new VectorStoreCapabilities(
                driver: $driver,
                available: true,
                dimensions: $this->mySqlColumnDimensions($columnRow),
            );
        } catch (Throwable) {
            return VectorStoreCapabilities::unavailable($driver, 'capability_probe_failed');
        }
    }

    private function hasVectorColumn(string $driver): bool
    {
        $row = $this->connection->selectOne(
            match ($driver) {
                'pgsql' => '
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = ?
                      AND column_name = ?
                    LIMIT 1
                ',
                default => throw new InvalidArgumentException('Unsupported vector column probe driver.'),
            },
            ['knowledge_chunks', self::VECTOR_COLUMN],
        );

        return $row !== null;
    }

    private function isMySqlVectorColumn(object|array $row): bool
    {
        $dataType = strtolower((string) ($this->rowValue($row, 'data_type') ?? ''));
        $columnType = strtolower((string) ($this->rowValue($row, 'column_type') ?? ''));

        return $dataType === 'vector' || str_starts_with($columnType, 'vector(');
    }

    private function mySqlColumnDimensions(object|array $row): int
    {
        $columnType = (string) ($this->rowValue($row, 'column_type') ?? '');
        if (preg_match('/^vector\s*\(\s*(\d+)\s*\)$/i', $columnType, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return self::DEFAULT_DIMENSIONS;
    }

    private function mysqlWriteExpression(string $vectorLiteral): Expression
    {
        try {
            $quotedLiteral = $this->connection->getPdo()->quote($vectorLiteral, PDO::PARAM_STR);
            if ($quotedLiteral === false) {
                return new Expression('NULL');
            }

            return new Expression($this->renderWriteExpression($quotedLiteral));
        } catch (Throwable) {
            return new Expression('NULL');
        }
    }

    private function renderWriteExpression(string $placeholder): string
    {
        return match ($this->driver()) {
            'mysql' => 'VEC_FROMTEXT('.$placeholder.')',
            'pgsql' => 'CAST('.$placeholder.' AS vector)',
            default => $placeholder,
        };
    }

    private function assertVectorColumn(string $column): string
    {
        if ($column !== self::VECTOR_COLUMN) {
            throw new InvalidArgumentException('Unsupported vector column.');
        }

        return $column;
    }

    private function assertPlaceholder(string $placeholder): void
    {
        if ($placeholder !== '?' && preg_match('/^:[a-zA-Z_][a-zA-Z0-9_]*$/', $placeholder) !== 1) {
            throw new InvalidArgumentException('Invalid vector query placeholder.');
        }
    }

    private function rowIsTruthy(object|array|null $row, string $key): bool
    {
        $value = $this->rowValue($row, $key);

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function rowValue(object|array|null $row, string $key): mixed
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row?->{$key} ?? null;
    }
}
