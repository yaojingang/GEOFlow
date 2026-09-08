<?php

namespace Tests\Feature;

use App\Services\Deployment\DeploymentReadiness;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    public function test_storage_probe_is_removed_and_wrong_version_is_rejected(): void
    {
        $temporary = sys_get_temp_dir().'/geoflow-readiness-'.bin2hex(random_bytes(8));
        $original = $this->app->storagePath();
        foreach (['framework', 'app/public', 'app/private'] as $directory) {
            mkdir($temporary.'/'.$directory, 0700, true);
        }
        $this->app->useStoragePath($temporary);
        try {
            $checks = app(DeploymentReadiness::class)->check((string) config('geoflow.app_version'), true);
            $this->assertNotContains('fail', array_column($checks, 'status'));
            $this->assertSame([], File::allFiles($temporary));
            $this->assertSame('fail', app(DeploymentReadiness::class)->check('99.0.0', false)['version_identity']['status']);
            rmdir($temporary.'/app/public');
            $this->assertSame('fail', app(DeploymentReadiness::class)->check((string) config('geoflow.app_version'), false)['storage_app_public']['status']);
        } finally {
            $this->app->useStoragePath($original);
            File::deleteDirectory($temporary);
        }
    }

    public function test_configured_redis_is_pinged_and_failure_is_not_ready(): void
    {
        config()->set('cache.default', 'redis');
        $connection = Mockery::mock();
        $connection->shouldReceive('ping')->once()->andThrow(new \RuntimeException('secret connection details'));
        Redis::shouldReceive('connection')->once()->with('cache')->andReturn($connection);
        $checks = app(DeploymentReadiness::class)->check((string) config('geoflow.app_version'), false);
        $this->assertSame('fail', $checks['redis_cache']['status']);
        $this->assertStringNotContainsString('secret', json_encode($checks));
    }
}
