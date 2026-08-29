<?php

namespace Tests\Unit;

use Tests\TestCase;

class FrontendDependencySecurityBaselineTest extends TestCase
{
    public function test_lock_file_keeps_the_reviewed_frontend_security_floor(): void
    {
        $lock = json_decode(
            (string) file_get_contents(base_path('package-lock.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $packages = $lock['packages'];

        $this->assertSame('geoflow', $lock['name']);
        $this->assertVersionAtLeast($packages, 'axios', '1.18.1');
        $this->assertVersionAtLeast($packages, 'concurrently', '9.2.4');
        $this->assertVersionAtLeast($packages, 'shell-quote', '1.9.0');
        $this->assertVersionAtLeast($packages, 'vite', '7.3.4');
        $this->assertVersionAtLeast($packages, 'esbuild', '0.28.1');
        $this->assertVersionAtLeast($packages, 'postcss', '8.5.18');
        $this->assertVersionAtLeast($packages, 'form-data', '4.0.6');
        $this->assertVersionAtLeast($packages, 'pusher-js', '8.6.0');
        $this->assertArrayNotHasKey('node_modules/engine.io-client', $packages);
        $this->assertArrayNotHasKey('node_modules/ws', $packages);
    }

    /**
     * @param  array<string, array<string, mixed>>  $packages
     */
    private function assertVersionAtLeast(array $packages, string $package, string $minimum): void
    {
        $version = $packages['node_modules/'.$package]['version'] ?? null;

        $this->assertIsString($version, "Missing {$package} from package-lock.json.");
        $this->assertTrue(
            version_compare($version, $minimum, '>='),
            "{$package} {$version} must remain at or above {$minimum}.",
        );
    }
}
