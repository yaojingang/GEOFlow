<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerStoragePermissionsConfigurationTest extends TestCase
{
    public function test_entrypoints_batch_storage_permission_changes(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker/entrypoint.sh', 'docker/entrypoint.prod.sh'] as $entrypointFile) {
            $entrypoint = file_get_contents($root.'/'.$entrypointFile);

            $this->assertIsString($entrypoint);
            $this->assertStringContainsString(
                'find storage bootstrap/cache -type d -exec chmod 775 {} +',
                $entrypoint,
                $entrypointFile.' must batch directory permission changes.'
            );
            $this->assertStringContainsString(
                'find storage bootstrap/cache -type f -exec chmod 664 {} +',
                $entrypoint,
                $entrypointFile.' must batch file permission changes.'
            );
            $this->assertStringNotContainsString(
                '-exec chmod 775 {} \;',
                $entrypoint,
                $entrypointFile.' must not spawn chmod once per directory.'
            );
            $this->assertStringNotContainsString(
                '-exec chmod 664 {} \;',
                $entrypoint,
                $entrypointFile.' must not spawn chmod once per file.'
            );
            $optimizePosition = strpos($entrypoint, 'if [ "${AUTO_OPTIMIZE:');
            $permissionPosition = strpos($entrypoint, 'if [ "${AUTO_FIX_STORAGE_PERMISSIONS:');
            $this->assertNotFalse($optimizePosition);
            $this->assertNotFalse($permissionPosition);
            $this->assertGreaterThan(
                $optimizePosition,
                $permissionPosition,
                $entrypointFile.' must repair permissions after initialization writes finish.'
            );
        }
    }

    public function test_only_init_can_automatically_fix_storage_permissions(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);

            $this->assertIsString($compose);
            $services = $this->serviceBlocks($compose);
            $this->assertArrayHasKey('init', $services, $composeFile.' must define the init service.');
            $this->assertStringContainsString(
                'AUTO_FIX_STORAGE_PERMISSIONS: "${AUTO_FIX_STORAGE_PERMISSIONS:-true}"',
                $services['init'],
                $composeFile.' must let the operator control the one-shot storage repair.'
            );
            $this->assertStringContainsString(
                'command: ["true"]',
                $services['init'],
                $composeFile.' must not run another Artisan command after the final permission repair.'
            );

            $runtimeServices = array_filter(
                $services,
                fn (string $block, string $service): bool => $service !== 'init'
                    && $this->usesApplicationImage($block),
                ARRAY_FILTER_USE_BOTH
            );
            $this->assertNotEmpty($runtimeServices, $composeFile.' must define application runtime services.');

            foreach ($runtimeServices as $service => $block) {
                $this->assertStringContainsString(
                    'AUTO_FIX_STORAGE_PERMISSIONS: "false"',
                    $block,
                    sprintf('%s must disable repeated permission repair in %s.', $composeFile, $service)
                );
                $this->assertStringContainsString(
                    "      init:\n        condition: service_completed_successfully",
                    $block,
                    sprintf('%s must wait for init before starting %s.', $composeFile, $service)
                );
            }

            $this->assertSame(
                1,
                substr_count($compose, 'AUTO_FIX_STORAGE_PERMISSIONS: "${AUTO_FIX_STORAGE_PERMISSIONS:-true}"')
            );
            $this->assertSame(count($runtimeServices), substr_count($compose, 'AUTO_FIX_STORAGE_PERMISSIONS: "false"'));
        }
    }

    public function test_production_image_prepares_container_local_cache_permissions(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile.prod');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString(
            'chown -R www-data:www-data storage bootstrap/cache',
            $dockerfile,
            'The production image must make its private bootstrap/cache writable before runtime scans are disabled.'
        );
        $this->assertStringContainsString(
            'ln -s ../storage/app/public public/storage',
            $dockerfile,
            'The production image must prepare the storage link before runtime services drop root privileges.'
        );
        $this->assertStringContainsString(
            'rm -f public/storage',
            $dockerfile,
            'The production image must replace an existing development storage link safely.'
        );
    }

    public function test_production_runtime_services_use_the_shared_storage_owner(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($compose);

            $services = $this->serviceBlocks($compose);
            $runtimeServices = array_filter(
                $services,
                fn (string $block, string $service): bool => $service !== 'init'
                    && $this->usesApplicationImage($block),
                ARRAY_FILTER_USE_BOTH
            );

            $this->assertStringNotContainsString('user:', $services['init']);

            foreach ($runtimeServices as $service => $block) {
                $this->assertStringContainsString(
                    'user: "www-data:www-data"',
                    $block,
                    sprintf('%s must run %s as the shared storage owner.', $composeFile, $service)
                );
            }
        }
    }

    public function test_compose_renders_the_operator_storage_permission_override_when_available(): void
    {
        $docker = new Process(['docker', 'compose', 'version']);
        $docker->run();

        if (! $docker->isSuccessful()) {
            $this->markTestSkipped('Docker Compose is required to verify rendered deployment configuration.');
        }

        $root = dirname(__DIR__, 2);
        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $compose = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($compose);

            $runtimeServices = array_filter(
                $this->serviceBlocks($compose),
                fn (string $block, string $service): bool => $service !== 'init'
                    && $this->usesApplicationImage($block),
                ARRAY_FILTER_USE_BOTH
            );

            foreach ([
                ['value' => false, 'expected' => 'true'],
                ['value' => 'false', 'expected' => 'false'],
            ] as $scenario) {
                $rendered = $this->renderCompose(
                    $root,
                    $composeFile,
                    ['AUTO_FIX_STORAGE_PERMISSIONS' => $scenario['value']]
                );

                $this->assertSame(
                    $scenario['expected'],
                    $rendered['services']['init']['environment']['AUTO_FIX_STORAGE_PERMISSIONS'] ?? null,
                    $composeFile.' must preserve the operator storage permission setting for init.'
                );

                foreach (array_keys($runtimeServices) as $service) {
                    $this->assertSame(
                        'false',
                        $rendered['services'][$service]['environment']['AUTO_FIX_STORAGE_PERMISSIONS'] ?? null,
                        sprintf('%s must keep repeated permission repair disabled in %s.', $composeFile, $service)
                    );
                }
            }
        }
    }

    /** @return array<string, string> */
    private function serviceBlocks(string $compose): array
    {
        preg_match_all(
            '/^  (?<name>[a-zA-Z0-9_-]+):\R(?<block>(?:(?!^  [a-zA-Z0-9_-]+:\R).)*)/ms',
            $compose,
            $matches,
            PREG_SET_ORDER
        );

        $services = [];
        foreach ($matches as $match) {
            $services[(string) $match['name']] = (string) $match['block'];
        }

        return $services;
    }

    private function usesApplicationImage(string $serviceBlock): bool
    {
        return str_contains($serviceBlock, 'image: geoflow-app')
            || str_contains($serviceBlock, 'image: ${GEOFLOW_APP_IMAGE');
    }

    /**
     * @param  array<string, string|false>  $environment
     * @return array<string, mixed>
     */
    private function renderCompose(string $root, string $composeFile, array $environment): array
    {
        $emptyEnvFile = tempnam(sys_get_temp_dir(), 'geoflow-compose-env-');
        $this->assertNotFalse($emptyEnvFile);

        $process = new Process(
            [
                'docker',
                'compose',
                '--env-file',
                $emptyEnvFile,
                '-f',
                $composeFile,
                'config',
                '--no-env-resolution',
                '--format',
                'json',
            ],
            $root,
            array_merge(
                [
                    'GEOFLOW_APP_IMAGE' => 'geoflow-app:test',
                    'GEOFLOW_WEB_IMAGE' => 'geoflow-web:test',
                ],
                $environment
            )
        );

        try {
            $process->run();
        } finally {
            unlink($emptyEnvFile);
        }

        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
