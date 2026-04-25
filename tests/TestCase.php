<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * 测试基类：Feature 测试如需数据库可在用例中 use {@see RefreshDatabase}。
 */
abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $this->forceTestingDatabaseEnvironment();

        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.pgsql.url', null);

        return $app;
    }

    private function forceTestingDatabaseEnvironment(): void
    {
        $variables = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ];

        foreach ($variables as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }
}
