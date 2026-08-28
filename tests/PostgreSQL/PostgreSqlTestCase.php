<?php

namespace Tests\PostgreSQL;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class PostgreSqlTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if ((string) getenv('GEOFLOW_PG_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_PG_CONCURRENCY=1 to run PostgreSQL concurrency tests.');
        }

        parent::setUp();
        config()->set('database.default', 'pgsql');
        config()->set('queue.default', 'null');
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }
}
