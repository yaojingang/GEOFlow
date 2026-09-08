<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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
    }

    public function test_production_entrypoint_runs_as_uid_33_with_mounted_public_storage(): void
    {
        if (getenv('GEOFLOW_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_DOCKER_TEST=1 to run the isolated UID 33 container check.');
        }
        $root = dirname(__DIR__, 2);
        $dockerfile = (string) file_get_contents($root.'/docker/Dockerfile.prod');
        preg_match_all('/^RUN ((?:[^\n]*\\\\\n)*[^\n]*)/m', $dockerfile, $instructions);
        $preparation = array_values(array_filter($instructions[1], static fn (string $instruction): bool => str_contains($instruction, 'chown -R www-data:www-data storage bootstrap/cache')));
        $this->assertCount(1, $preparation);
        $container = 'geoflow-storage-review-'.bin2hex(random_bytes(6));
        $script = <<<'SH'
set -eu
cd /var/www/html
mkdir -p public bootstrap/cache storage/app/public /tmp/review-bin
chmod 755 public
printf 'APP_KEY=base64:test\n' > .env
chmod 600 .env
printf '#!/bin/sh\nexit 97\n' > /tmp/review-bin/php
chmod 755 /tmp/review-bin/php
SH;
        $script .= "\n".$preparation[0]."\n";
        $script .= <<<'SH'
printf 'mounted storage' > storage/app/public/readiness.txt
exec su -s /bin/sh www-data -c 'PATH=/tmp/review-bin:$PATH AUTO_OPTIMIZE=false AUTO_FIX_STORAGE_PERMISSIONS=false AUTO_WAIT_FOR_DB=false /bin/sh /tmp/entrypoint.prod.sh /bin/sh -c '\''test "$(id -u)" = 33 && test ! -r .env && test ! -w public && test -L public/storage && test -e public/storage && test -w bootstrap/cache && test "$(cat public/storage/readiness.txt)" = "mounted storage" && touch public/storage/worker-write.txt && printf "uid-33-storage-ready\n"'\'''
SH;
        $process = new Process([
            'docker', 'run', '--rm', '--name', $container, '--network', 'none',
            '--env', 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32)),
            '--mount', 'type=tmpfs,destination=/var/www/html/storage',
            '--mount', 'type=bind,source='.$root.'/docker/entrypoint.prod.sh,destination=/tmp/entrypoint.prod.sh,readonly',
            '--entrypoint', 'sh', 'php:8.4-fpm-bookworm', '-c', $script,
        ], null, null, null, 30);
        try {
            $process->mustRun();
            $this->assertStringContainsString('uid-33-storage-ready', $process->getOutput());
            $this->assertStringNotContainsString('storage:link', $process->getOutput());
        } finally {
            (new Process(['docker', 'rm', '-f', $container], null, null, null, 10))->run();
        }
    }

    public function test_production_image_retries_composer_downloads_with_a_shared_bounded_cache(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile.prod');

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('COMPOSER_MAX_PARALLEL_HTTP=4', $dockerfile);
        $this->assertStringContainsString(
            '--mount=type=cache,id=geoflow-composer-dist,target=/tmp/composer-cache,sharing=locked',
            $dockerfile,
        );
        $this->assertStringContainsString('for attempt in 1 2 3; do', $dockerfile);
        $this->assertStringContainsString('sleep "$((attempt * 15))"', $dockerfile);
    }

    #[DataProvider('applicationKeySources')]
    public function test_entrypoint_key_precedence_preserves_generation_and_invalid_environment_cleanup(string $entrypoint, string $environmentKey, bool $fileKey, bool $privateFile, bool $generate): void
    {
        if (getenv('GEOFLOW_DOCKER_TEST') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_DOCKER_TEST=1 to verify application key precedence as UID 33.');
        }
        $root = dirname(__DIR__, 2);
        $container = 'geoflow-key-review-'.bin2hex(random_bytes(6));
        $validKey = 'base64:'.base64_encode(str_repeat('k', 32));
        $environmentKey = $environmentKey === 'valid' ? $validKey : $environmentKey;
        $script = <<<'SH'
set -eu
cd /var/www/html
mkdir -p public bootstrap/cache storage/app/public vendor /tmp/review-bin
touch vendor/autoload.php
ln -sT /var/www/html/storage/app/public public/storage
chown -R www-data:www-data storage bootstrap/cache
printf 'APP_KEY=%s\n' "$REVIEW_FILE_KEY" > .env
if [ "$REVIEW_PRIVATE_FILE" = true ]; then chmod 600 .env; fi
if [ "$REVIEW_GENERATE" = true ]; then chown www-data:www-data .env; fi
cat > /tmp/review-bin/php <<'STUB'
#!/bin/sh
set -eu
test "$*" = 'artisan key:generate --force --no-interaction'
test -z "${APP_KEY+x}"
printf 'APP_KEY=%s\n' "$REVIEW_GENERATED_KEY" > .env
touch storage/key-generated
STUB
chmod 755 /tmp/review-bin/php
exec su -s /bin/sh www-data -c 'PATH=/tmp/review-bin:$PATH COMPOSER_ON_START=false AUTO_OPTIMIZE=false AUTO_MIGRATE=false AUTO_INSTALL_ONCE=false AUTO_INIT_ONCE=false AUTO_FIX_STORAGE_PERMISSIONS=false AUTO_WAIT_FOR_DB=false DB_CONNECTION= /bin/sh /tmp/entrypoint.sh /bin/sh -c '\''test "$(id -u)" = 33
if [ "$REVIEW_ENVIRONMENT_VALID" = true ]; then
    test "$APP_KEY" = "$REVIEW_GENERATED_KEY"
else
    test -z "${APP_KEY+x}"
fi
if [ "$REVIEW_GENERATE" = true ]; then
    test -f storage/key-generated
    test "$(cat .env)" = "APP_KEY=$REVIEW_GENERATED_KEY"
else
    test ! -f storage/key-generated
fi
printf "key-precedence-ready\n"'\'''
SH;
        $process = new Process([
            'docker', 'run', '--rm', '--name', $container, '--network', 'none',
            '--env', 'APP_KEY='.$environmentKey,
            '--env', 'REVIEW_FILE_KEY='.($fileKey ? $validKey : ''),
            '--env', 'REVIEW_PRIVATE_FILE='.($privateFile ? 'true' : 'false'),
            '--env', 'REVIEW_GENERATE='.($generate ? 'true' : 'false'),
            '--env', 'REVIEW_ENVIRONMENT_VALID='.($environmentKey === $validKey ? 'true' : 'false'),
            '--env', 'REVIEW_GENERATED_KEY='.$validKey,
            '--mount', 'type=bind,source='.$root.'/'.$entrypoint.',destination=/tmp/entrypoint.sh,readonly',
            '--entrypoint', 'sh', 'php:8.4-fpm-bookworm', '-c', $script,
        ], null, null, null, 30);
        try {
            $process->mustRun();
            $this->assertStringContainsString('key-precedence-ready', $process->getOutput());
            $this->assertSame($generate, str_contains($process->getOutput(), 'php artisan key:generate'));
        } finally {
            (new Process(['docker', 'rm', '-f', $container], null, null, null, 10))->run();
        }
    }

    public static function applicationKeySources(): array
    {
        $cases = [];
        foreach (['docker/entrypoint.prod.sh', 'docker/entrypoint.sh'] as $entrypoint) {
            foreach ([
                'environment with private file' => ['valid', true, true, false],
                'environment with empty private file' => ['valid', false, true, false],
                'file fallback without environment' => ['', true, false, false],
                'invalid environment falls back to file' => ['invalid-key', true, false, false],
                'missing key is generated' => ['', false, false, true],
                'invalid environment is cleared before generation' => ['invalid-key', false, false, true],
            ] as $name => $case) {
                $cases[$entrypoint.' '.$name] = [$entrypoint, ...$case];
            }
        }

        return $cases;
    }

    public function test_production_compose_renders_distinct_project_resources(): void
    {
        $docker = new Process(['docker', 'compose', 'version']);
        $docker->run();

        if (! $docker->isSuccessful()) {
            $this->markTestSkipped('Docker Compose is required to verify rendered deployment configuration.');
        }

        $root = dirname(__DIR__, 2);
        $first = $this->renderCompose($root, 'docker-compose.prod.yml', [
            'GEOFLOW_APP_IMAGE' => false,
            'GEOFLOW_WEB_IMAGE' => false,
            'DOCKER_NETWORK_NAME' => 'geoflow-a-net',
            'DOCKER_NETWORK_SUBNET' => '10.89.0.0/16',
            'DOCKER_NETWORK_GATEWAY' => '10.89.0.1',
            'WEB_PORT' => '18081',
            'POSTGRES_DATA_DIR' => './docker-data/prod-a/postgres',
        ], 'geoflow-a');
        $second = $this->renderCompose($root, 'docker-compose.prod.yml', [
            'GEOFLOW_APP_IMAGE' => false,
            'GEOFLOW_WEB_IMAGE' => false,
            'DOCKER_NETWORK_NAME' => 'geoflow-b-net',
            'DOCKER_NETWORK_SUBNET' => '10.90.0.0/16',
            'DOCKER_NETWORK_GATEWAY' => '10.90.0.1',
            'WEB_PORT' => '18082',
            'POSTGRES_DATA_DIR' => './docker-data/prod-b/postgres',
        ], 'geoflow-b');

        $this->assertSame('geoflow-a', $first['name'] ?? null);
        $this->assertSame('geoflow-b', $second['name'] ?? null);
        $this->assertSame('geoflow-a-web', $first['services']['web']['image'] ?? null);
        $this->assertSame('geoflow-b-web', $second['services']['web']['image'] ?? null);
        $this->assertSame('geoflow-a-net', $first['networks']['default']['name'] ?? null);
        $this->assertSame('geoflow-b-net', $second['networks']['default']['name'] ?? null);
        $this->assertSame('10.89.0.0/16', $first['networks']['default']['ipam']['config'][0]['subnet'] ?? null);
        $this->assertSame('10.90.0.0/16', $second['networks']['default']['ipam']['config'][0]['subnet'] ?? null);
        $this->assertSame('18081', $first['services']['web']['ports'][0]['published'] ?? null);
        $this->assertSame('18082', $second['services']['web']['ports'][0]['published'] ?? null);
        $this->assertStringEndsWith(
            '/docker-data/prod-a/postgres',
            $first['services']['postgres']['volumes'][0]['source'] ?? ''
        );
        $this->assertStringEndsWith(
            '/docker-data/prod-b/postgres',
            $second['services']['postgres']['volumes'][0]['source'] ?? ''
        );

        foreach ([['project' => 'geoflow-a', 'rendered' => $first], ['project' => 'geoflow-b', 'rendered' => $second]] as $scenario) {
            $rendered = $scenario['rendered'];
            foreach ($rendered['services'] ?? [] as $service => $configuration) {
                $this->assertArrayNotHasKey(
                    'container_name',
                    $configuration,
                    sprintf('Production service %s must use the Compose project prefix.', $service)
                );

                if (($configuration['build']['dockerfile'] ?? null) === 'docker/Dockerfile.prod') {
                    $this->assertSame(
                        $scenario['project'].'-app',
                        $configuration['image'] ?? null,
                        sprintf('Production service %s must use the project-scoped application image.', $service)
                    );
                }
            }
        }

        $this->assertSame(
            'wget -q --header="Host: $${GEOFLOW_NGINX_PRIMARY_HOST}" -O /dev/null http://127.0.0.1/up || exit 1',
            $first['services']['web']['healthcheck']['test'][1] ?? null
        );
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
            || str_contains($serviceBlock, 'image: ${GEOFLOW_APP_IMAGE')
            || str_contains($serviceBlock, 'image: ${COMPOSE_PROJECT_NAME');
    }

    /**
     * @param  array<string, string|false>  $environment
     * @return array<string, mixed>
     */
    private function renderCompose(
        string $root,
        string $composeFile,
        array $environment,
        ?string $projectName = null
    ): array {
        $emptyEnvFile = tempnam(sys_get_temp_dir(), 'geoflow-compose-env-');
        $this->assertNotFalse($emptyEnvFile);

        $runtimeEnvFile = $root.'/.env.prod';
        $createdRuntimeEnvFile = false;
        if (! file_exists($runtimeEnvFile) && ! is_link($runtimeEnvFile)) {
            $this->assertNotFalse(file_put_contents($runtimeEnvFile, ''));
            $createdRuntimeEnvFile = true;
        }

        $command = ['docker', 'compose'];
        if ($projectName !== null) {
            array_push($command, '-p', $projectName);
        }
        array_push(
            $command,
            '--env-file',
            $emptyEnvFile,
            '-f',
            $composeFile,
            'config',
            '--no-env-resolution',
            '--format',
            'json'
        );

        $process = new Process(
            $command,
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
            if ($createdRuntimeEnvFile) {
                unlink($runtimeEnvFile);
            }
        }

        $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()));

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
