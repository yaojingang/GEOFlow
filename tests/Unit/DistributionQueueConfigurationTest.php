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

    public function test_compose_init_services_scope_the_fresh_install_confirmation(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.yml', 'docker-compose.prod.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $this->assertStringContainsString(
                'GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED: "true"',
                $contents,
                $composeFile.' must scope fresh-install intent to its one-shot init service.'
            );
        }
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

    public function test_production_init_uses_first_install_command_instead_of_auto_seed(): void
    {
        $root = dirname(__DIR__, 2);
        $compose = file_get_contents($root.'/docker-compose.prod.yml');
        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');

        $this->assertIsString($compose);
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('- ./.env.prod', $compose);
        $this->assertStringNotContainsString('AUTO_SEED', $compose);
        $this->assertStringNotContainsString('AUTO_SEED_CLASS:', $compose);
        $this->assertStringNotContainsString('php artisan db:seed', $entrypoint);
        $this->assertStringContainsString('php artisan geoflow:install', $entrypoint);
    }
}
