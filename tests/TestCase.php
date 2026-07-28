<?php

namespace Tests;

use App\Contracts\Outbound\HostResolver;
use App\Models\Admin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeHostResolver;

/**
 * 测试基类：Feature 测试如需数据库可在用例中 use {@see RefreshDatabase}。
 */
abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        if ($guard === 'admin' && $user instanceof Admin) {
            $this->app['session']->put(
                Admin::AUTH_VERSION_SESSION_KEY,
                (int) $user->auth_version
            );
        }

        return $this;
    }

    public function createApplication()
    {
        $this->forceTestingDatabaseEnvironment();

        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.pgsql.url', null);
        $app->singleton(HostResolver::class, FakeHostResolver::class);

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
