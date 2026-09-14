<?php

namespace Tests\PostgreSQL;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class PostgreSqlTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        if ((string) getenv('GEOFLOW_PG_CONCURRENCY') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_PG_CONCURRENCY=1 to run PostgreSQL concurrency tests.');
        }

        parent::setUp();
    }

    public function createApplication()
    {
        $app = parent::createApplication();

        /** @var Application $app */
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('queue.default', 'null');
        $app['config']->set('geoflow.hosted_sites.enabled', true);
        $app['config']->set('geoflow.hosted_sites.root_domains', ['sites.test']);

        return $app;
    }
}
