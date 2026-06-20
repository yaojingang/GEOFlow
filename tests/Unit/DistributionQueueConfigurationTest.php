<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DistributionQueueConfigurationTest extends TestCase
{
    public function test_docker_queue_workers_listen_to_distribution_queue(): void
    {
        $root = dirname(__DIR__, 2);
        $composeFiles = [
            $root.'/docker-compose.yml',
            $root.'/docker-compose.prod.yml',
        ];

        foreach ($composeFiles as $composeFile) {
            $contents = file_get_contents($composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString('--queue=geoflow,distribution,theme-replication,default', $contents, basename($composeFile));
        }
    }

    public function test_horizon_supervisor_listens_to_distribution_queue(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(
            ['geoflow', 'distribution'],
            $horizon['defaults']['supervisor-1']['queue'] ?? null
        );
    }

    public function test_documented_production_compose_commands_use_env_file(): void
    {
        $root = dirname(__DIR__, 2);
        $docs = array_merge(
            [$root.'/README.md', $root.'/docs/deployment/DEPLOYMENT.md'],
            glob($root.'/docs/readme/README_*.md') ?: []
        );

        foreach ($docs as $doc) {
            $contents = file_get_contents($doc);
            $this->assertIsString($contents);

            foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
                if (! str_contains($line, 'docker compose') || ! str_contains($line, 'docker-compose.prod.yml')) {
                    continue;
                }

                $this->assertStringContainsString(
                    '--env-file .env.prod',
                    $line,
                    sprintf('%s:%d production compose command must load .env.prod', basename($doc), $lineNumber + 1)
                );
            }
        }
    }

    public function test_production_init_seed_is_scoped_to_default_admin_only(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');

        $this->assertIsString($compose);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString("AUTO_SEED_CLASS: 'Database\\Seeders\\AdminUserSeeder'", $compose);
        $this->assertStringContainsString('php artisan db:seed --class=${AUTO_SEED_CLASS} --force', $entrypoint);
        $this->assertStringContainsString('php artisan db:seed --class="${AUTO_SEED_CLASS}" --force --no-interaction', $entrypoint);
    }
}
