<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DistributionQueueConfigurationTest extends TestCase
{
    /**
     * 生产 Compose 必须按项目名/镜像 tag 隔离，才能在同一台机器上跑多套实例。
     */
    public function test_production_compose_supports_side_by_side_projects(): void
    {
        $compose = file_get_contents(dirname(__DIR__, 2).'/docker-compose.prod.yml');
        $this->assertIsString($compose);
        $this->assertStringNotContainsString('container_name:', $compose);
        $this->assertStringContainsString('name: ${COMPOSE_PROJECT_NAME:-geoflow-laravel-prod}', $compose);
        $this->assertStringContainsString('image: ${GEOFLOW_APP_IMAGE:-${COMPOSE_PROJECT_NAME:-geoflow-laravel-prod}-app}', $compose);
        $this->assertStringContainsString('image: ${GEOFLOW_WEB_IMAGE:-${COMPOSE_PROJECT_NAME:-geoflow-laravel-prod}-web}', $compose);
        $this->assertStringContainsString(
            'wget -q --header=\"Host: $${GEOFLOW_NGINX_PRIMARY_HOST}\"',
            $compose
        );
    }

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
            $this->assertStringContainsString('--queue=system-updates,geoflow,distribution,theme-replication,default', $contents, basename($composeFile));
            $this->assertStringNotContainsString('--queue=ai-workspace-interactive', $contents, basename($composeFile));
            $this->assertStringNotContainsString('--queue=ai-workspace', $contents, basename($composeFile));
            $this->assertStringContainsString('--queue=knowledge', $contents, basename($composeFile));
        }
    }

    public function test_horizon_supervisor_listens_to_distribution_queue(): void
    {
        $horizon = require dirname(__DIR__, 2).'/config/horizon.php';

        $this->assertSame(
            ['system-updates', 'geoflow', 'distribution', 'theme-replication', 'default'],
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

    public function test_production_lifecycle_excludes_the_retired_application_update_worker(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['docker-compose.yml', 'docker-compose.prod.yml', 'docker-compose.prebuilt.yml', 'config/horizon.php'] as $file) {
            $contents = file_get_contents($root.'/'.$file);
            $this->assertIsString($contents);
            $this->assertStringNotContainsString('geoflow-system-update-queue-prod', $contents, $file);
        }

        $deploy = (string) file_get_contents($root.'/deploy-scripts/geoflow-docker-deploy.sh');
        $healthcheck = (string) file_get_contents($root.'/deploy-scripts/geoflow-healthcheck.sh');
        $this->assertStringContainsString('geoflow-system-update-queue-prod', $deploy);
        $this->assertStringContainsString('--remove-orphans', $deploy);
        $this->assertStringContainsString('geoflow-system-update-queue-prod', $healthcheck);
        $this->assertStringContainsString('Retired system update worker is still present', $healthcheck);
    }

    public function test_queue_timeouts_preserve_retry_ordering(): void
    {
        $root = dirname(__DIR__, 2);
        $horizon = require $root.'/config/horizon.php';
        $queue = require $root.'/config/queue.php';

        $this->assertSame(210, $horizon['defaults']['supervisor-knowledge']['timeout']);
        $this->assertArrayNotHasKey('supervisor-system-updates', $horizon['defaults']);
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
        $this->assertStringContainsString('GEOFLOW_TRUSTED_PROXIES:-REMOTE_ADDR}', $script);
        $this->assertStringNotContainsString('GEOFLOW_TRUSTED_PROXIES:-*}', $script);
    }

    public function test_deploy_script_drains_old_services_around_database_migrations(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-docker-deploy.sh');

        $this->assertIsString($script);
        $maintenanceLogAt = strpos($script, 'Entering maintenance mode and draining existing application services.');
        $maintenanceAt = strpos($script, "\n  enter_maintenance_mode\n", $maintenanceLogAt === false ? 0 : $maintenanceLogAt);
        $stopAt = strpos($script, 'stop web app queue ai-quality-queue ai-quality-backfill-queue knowledge-queue scheduler reverb');
        $migrationAt = strpos($script, '"${COMPOSE[@]}" up init');
        $resumeAt = strpos($script, 'php artisan up');
        $internalHealthAt = strpos($script, "\n  run_healthcheck 1\n");
        $resumeCallAt = strpos($script, "\n  resume_traffic\n");
        $externalHealthAt = strpos($script, "\n  run_healthcheck 0\n");
        $this->assertNotFalse($maintenanceLogAt);
        $this->assertNotFalse($maintenanceAt);
        $this->assertNotFalse($stopAt);
        $this->assertNotFalse($migrationAt);
        $this->assertNotFalse($resumeAt);
        $this->assertNotFalse($internalHealthAt);
        $this->assertNotFalse($resumeCallAt);
        $this->assertNotFalse($externalHealthAt);
        $this->assertLessThan($stopAt, $maintenanceAt);
        $this->assertLessThan($migrationAt, $stopAt);
        $this->assertLessThan($resumeAt, $migrationAt);
        $this->assertLessThan($resumeCallAt, $internalHealthAt);
        $this->assertLessThan($externalHealthAt, $resumeCallAt);
        $this->assertStringNotContainsString('ps --all --services | grep -qx app', $script);
        $this->assertStringNotContainsString('ps --status running --services | grep -qx app', $script);
        $maintenanceFunctionAt = strpos($script, 'enter_maintenance_mode()');
        $maintenanceFunctionEnd = strpos($script, "\n}\n", $maintenanceFunctionAt === false ? 0 : $maintenanceFunctionAt);
        $this->assertNotFalse($maintenanceFunctionAt);
        $this->assertNotFalse($maintenanceFunctionEnd);
        $maintenanceBlock = substr($script, (int) $maintenanceFunctionAt, (int) $maintenanceFunctionEnd - (int) $maintenanceFunctionAt);
        $this->assertStringContainsString('"${COMPOSE[@]}" run --rm --no-deps', $maintenanceBlock);
        $this->assertStringContainsString('-e AUTO_WAIT_FOR_DB=false', $maintenanceBlock);
        $this->assertStringContainsString('-e AUTO_MIGRATE=false', $maintenanceBlock);
        $this->assertStringContainsString('-e AUTO_INSTALL_ONCE=false', $maintenanceBlock);
        $this->assertStringContainsString('-e AUTO_OPTIMIZE=false', $maintenanceBlock);
        $this->assertStringContainsString('if enter_maintenance_mode; then', $script);

        $healthcheck = file_get_contents(dirname(__DIR__, 2).'/deploy-scripts/geoflow-healthcheck.sh');
        $this->assertIsString($healthcheck);
        $this->assertStringContainsString('GEOFLOW_SKIP_HTTP_CHECK', $healthcheck);
        $this->assertStringContainsString('fail "HTTP health endpoint failed:', $healthcheck);
    }

    public function test_nginx_forwards_client_ip_chain_to_laravel_rate_limiters(): void
    {
        $root = dirname(__DIR__, 2);
        $nginxTemplate = file_get_contents($root.'/docker/nginx/default.conf.template');
        $nginxApp = file_get_contents($root.'/docker/nginx/geoflow-app.conf');

        $this->assertIsString($nginxTemplate);
        $this->assertIsString($nginxApp);
        $this->assertStringContainsString('listen 80 default_server;', $nginxTemplate);
        $this->assertStringContainsString('server_name *.${GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN};', $nginxTemplate);
        $this->assertStringContainsString(
            'fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;',
            $nginxApp
        );
        $this->assertStringContainsString(
            'fastcgi_param HTTP_X_REAL_IP $remote_addr;',
            $nginxApp
        );
        $this->assertStringContainsString('GEOFLOW_NGINX_PUBLIC_PORT', $nginxTemplate);
        $this->assertStringContainsString('HTTP_X_FORWARDED_PORT $geoflow_forwarded_port', $nginxApp);
        $this->assertStringContainsString('geoflow_hosted_surface', $nginxTemplate);
        $this->assertStringContainsString('/__geoflow_host_must_resolve__', $nginxTemplate);
        $wildcardServer = substr($nginxTemplate, strpos($nginxTemplate, 'server_name *.${GEOFLOW_NGINX_HOSTED_ROOT_DOMAIN};'));
        $this->assertIsString($wildcardServer);
        $this->assertStringNotContainsString('try_files $uri', $wildcardServer);
        $this->assertStringContainsString('location / {', $wildcardServer);
    }

    public function test_php_fpm_concurrency_fits_the_application_memory_envelope(): void
    {
        $pool = file_get_contents(dirname(__DIR__, 2).'/docker/php-fpm/www.conf');

        $this->assertIsString($pool);
        $this->assertStringContainsString('pm.max_children = 5', $pool);
        $this->assertStringContainsString('php_admin_value[memory_limit] = 128M', $pool);
    }
}
