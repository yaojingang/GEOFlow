<?php

namespace Tests\Unit;

use App\Support\GeoFlow\VectorDatabaseAdapter;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseVectorAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    #[Test]
    public function mysql_capability_probe_requires_vector_functions_and_a_native_vector_column(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->twice()->andReturn(
            (object) ['vector_dimensions' => 2],
            (object) ['data_type' => 'VECTOR', 'column_type' => 'vector(3072)'],
        );

        $capabilities = (new VectorDatabaseAdapter($connection))->capabilities();

        self::assertTrue($capabilities->available);
        self::assertSame('mysql', $capabilities->driver);
        self::assertSame(3072, $capabilities->dimensions);
    }

    #[Test]
    public function capability_cache_can_be_disabled_for_recovery_probes(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
        $connection->shouldReceive('selectOne')->times(4)->andReturn(
            (object) ['vector_dimensions' => 2],
            (object) ['data_type' => 'VECTOR', 'column_type' => 'vector(3072)'],
            (object) ['vector_dimensions' => 2],
            (object) ['data_type' => 'VECTOR', 'column_type' => 'vector(3072)'],
        );

        $adapter = new VectorDatabaseAdapter($connection, 0);

        self::assertTrue($adapter->isAvailable());
        self::assertTrue($adapter->isAvailable());
    }

    #[Test]
    public function postgres_capability_probe_keeps_pgvector_path(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn('pgsql');
        $connection->shouldReceive('selectOne')->twice()->andReturn(
            (object) ['ok' => true],
            (object) ['exists' => true],
        );

        $adapter = new VectorDatabaseAdapter($connection);

        self::assertTrue($adapter->isAvailable());
        self::assertSame('embedding_vector <=> CAST(? AS vector)', $adapter->similarityExpression());
        self::assertSame('[1.0,2.0]', $adapter->writeValue('[1.0,2.0]'));
    }

    #[Test]
    public function mysql_uses_native_functions_for_write_and_cosine_search(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn('mysql');
        $connection->shouldReceive('getPdo')->once()->andReturn(new PDO('sqlite::memory:'));

        $adapter = new VectorDatabaseAdapter($connection);
        $writeValue = $adapter->writeValue('[1.0,2.0]');

        self::assertInstanceOf(Expression::class, $writeValue);
        self::assertSame("VEC_FROMTEXT('[1.0,2.0]')", $writeValue->getValue(new MySqlGrammar($connection)));
        self::assertSame('VEC_FROMTEXT(?)', $adapter->writeExpression());
        self::assertSame(
            'VEC_DISTANCE_COSINE(embedding_vector, VEC_FROMTEXT(?))',
            $adapter->similarityExpression(),
        );
        self::assertSame('LIMIT 16', $adapter->limitClause(16));
        self::assertSame('LIMIT 1', $adapter->limitClause(0));
    }

    #[Test]
    public function unsupported_database_reports_unavailable_and_preserves_fallback_contract(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn('sqlite');

        $adapter = new VectorDatabaseAdapter($connection);
        $capabilities = $adapter->capabilities();

        self::assertFalse($capabilities->available);
        self::assertSame('unsupported_driver', $capabilities->reason);
        self::assertNull($adapter->writeValue('[1.0,2.0]'));
        self::assertSame('[1.0,2.0,0.0]', $adapter->encode([1, 2], 3));
        self::assertNull($adapter->encode([1, 'not-a-number'], 3));
    }

    #[Test]
    public function vector_column_name_is_allowlisted(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->zeroOrMoreTimes()->andReturn('mysql');

        $adapter = new VectorDatabaseAdapter($connection);

        $this->expectException(\InvalidArgumentException::class);
        $adapter->similarityExpression('embedding_vector, users.password');
    }
}
