<?php

namespace App\Support\GeoFlow;

use Illuminate\Database\Query\Expression;

interface VectorStoreAdapter
{
    public const DEFAULT_DIMENSIONS = 3072;

    public function capabilities(): VectorStoreCapabilities;

    public function isAvailable(): bool;

    public function driver(): string;

    public function dimensions(): int;

    /**
     * Normalize a provider vector to the dimension used by the database column.
     *
     * @param  list<int|float|numeric-string>  $vector
     */
    public function encode(array $vector, ?int $dimensions = null): ?string;

    /**
     * Return a query expression for a native VECTOR column write.
     *
     * PostgreSQL keeps the existing bound string behavior. MySQL returns an
     * expression such as VEC_FROMTEXT(?) and its query-builder value helper
     * safely quotes the already-normalized vector literal into that expression.
     */
    public function writeExpression(string $placeholder = '?'): string;

    /**
     * Adapt an encoded vector for a query-builder insert/update.
     */
    public function writeValue(?string $vectorLiteral): string|Expression|null;

    /**
     * Return a database-specific cosine-distance expression.
     *
     * The column is deliberately restricted to the known vector column so a
     * future caller cannot turn this SQL fragment into an identifier injection.
     */
    public function similarityExpression(string $column = 'embedding_vector', string $placeholder = '?'): string;

    /**
     * Return a literal LIMIT clause. MySQL native prepared statements can reject
     * a bound LIMIT parameter, so the value is validated and inlined as an int.
     */
    public function limitClause(int $limit): string;
}
