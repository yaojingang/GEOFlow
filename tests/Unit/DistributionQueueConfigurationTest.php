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
            $this->assertStringContainsString('--queue=knowledge', $contents, basename($composeFile));
            $this->assertStringContainsString('--queue=system-updates', $contents, basename($composeFile));
        }
    }

    public function test_horizon_supervisor_listens_to_distribution_queue(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(
            ['geoflow', 'distribution', 'theme-replication', 'default'],
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

    public function test_production_init_services_preserve_the_operator_migration_gate(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['docker-compose.prod.yml', 'docker-compose.prebuilt.yml'] as $composeFile) {
            $contents = file_get_contents($root.'/'.$composeFile);
            $this->assertIsString($contents);
            $initStart = strpos($contents, "\n  init:\n");
            $appStart = strpos($contents, "\n  app:\n", $initStart === false ? 0 : $initStart);
            $this->assertNotFalse($initStart, $composeFile.' must define an init service.');
            $this->assertNotFalse($appStart, $composeFile.' must define an app service after init.');
            $initBlock = substr($contents, (int) $initStart, (int) $appStart - (int) $initStart);

            $this->assertStringNotContainsString(
                'AUTO_MIGRATE: "true"',
                $initBlock,
                $composeFile.' must not override the operator-controlled migration gate.'
            );
        }

        $entrypoint = file_get_contents($root.'/docker/entrypoint.prod.sh');
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('${AUTO_MIGRATE:-false}', $entrypoint);
    }

    public function test_deployment_healthcheck_rejects_pending_migrations(): void
    {
        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');

        $this->assertIsString($healthcheck);
        $this->assertStringContainsString(
            'php artisan migrate:status --pending=1 --no-interaction',
            $healthcheck
        );
        $this->assertStringContainsString(
            'fail "Laravel cannot read migration status or still has pending migrations.',
            $healthcheck
        );
    }

    public function test_production_lifecycle_includes_dedicated_long_running_workers(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'README.md',
            'docs/deployment/DEPLOYMENT.md',
            'deploy-scripts/geoflow-docker-deploy.sh',
            'deploy-scripts/geoflow-healthcheck.sh',
        ] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringContainsString('knowledge-queue', $contents, $file);
            $this->assertStringContainsString('system-update-queue', $contents, $file);
        }
    }

    public function test_queue_timeouts_preserve_retry_ordering(): void
    {
        $root = dirname(__DIR__, 2);
        $horizon = require $root.'/config/horizon.php';
        $queue = require $root.'/config/queue.php';

        $this->assertSame(210, $horizon['defaults']['supervisor-knowledge']['timeout']);
        $this->assertSame(930, $horizon['defaults']['supervisor-system-updates']['timeout']);
        $this->assertGreaterThan(930, $queue['connections']['redis']['retry_after']);
        $this->assertGreaterThan(930, $queue['connections']['database']['retry_after']);
    }

    public function test_deploy_script_matches_secure_cookie_to_public_protocol(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-docker-deploy.sh');

        $this->assertIsString($script);
        $this->assertStringContainsString('https://*) session_secure_cookie=true', $script);
        $this->assertStringContainsString('*) session_secure_cookie=false', $script);
        $this->assertStringContainsString('SESSION_SECURE_COOKIE "$session_secure_cookie"', $script);
        $this->assertStringContainsString('GEOFLOW_TRUSTED_PROXIES:-}', $script);
        $this->assertStringNotContainsString('GEOFLOW_TRUSTED_PROXIES:-*}', $script);
    }

    public function test_nginx_forwards_client_ip_chain_to_laravel_rate_limiters(): void
    {
        $nginx = file_get_contents(dirname(__DIR__, 2).'/docker/nginx/default.conf');

        $this->assertIsString($nginx);
        $this->assertStringContainsString(
            'fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;',
            $nginx
        );
        $this->assertStringContainsString(
            'fastcgi_param HTTP_X_REAL_IP $remote_addr;',
            $nginx
        );
    }

    public function test_php_fpm_concurrency_fits_the_application_memory_envelope(): void
    {
        $pool = file_get_contents(dirname(__DIR__, 2).'/docker/php-fpm/www.conf');

        $this->assertIsString($pool);
        $this->assertStringContainsString('pm.max_children = 5', $pool);
        $this->assertStringContainsString('php_admin_value[memory_limit] = 128M', $pool);
    }
}
