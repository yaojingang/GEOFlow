<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InfrastructureGatewayConfigurationTest extends TestCase
{
    public function test_local_compose_exposes_only_the_nginx_gateway_for_http_and_reverb(): void
    {
        $compose = $this->read('docker-compose.yml');
        $app = $this->serviceBlock($compose, 'app', 'web');
        $web = $this->serviceBlock($compose, 'web', 'queue');
        $reverb = $this->serviceBlock($compose, 'reverb', null);

        self::assertStringNotContainsString("\n    ports:", $app);
        self::assertStringContainsString('"--no-reload"', $app);
        self::assertStringContainsString('"127.0.0.1:${APP_PORT:-18080}:80"', $web);
        self::assertStringContainsString('./docker/nginx/local.conf:/etc/nginx/conf.d/default.conf:ro', $web);
        self::assertStringNotContainsString("\n    ports:", $reverb);
        self::assertStringContainsString("\n    expose:\n      - \"8080\"", $reverb);
        self::assertStringNotContainsString("\n  horizon:\n", $compose);
    }

    public function test_local_nginx_serves_fingerprinted_assets_and_same_origin_reverb(): void
    {
        $nginx = $this->read('docker/nginx/local.conf');

        self::assertStringContainsString('location ^~ /build/assets/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        self::assertStringContainsString('location ~ ^/reverb/(app|apps)/', $nginx);
        self::assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $nginx);
        self::assertStringContainsString('set $geoflow_reverb reverb:8080;', $nginx);
        self::assertStringContainsString('add_header Cache-Control "no-store" always;', $nginx);
    }

    public function test_production_nginx_does_not_mark_mutable_assets_as_immutable(): void
    {
        $nginx = $this->read('docker/nginx/default.conf.template');

        self::assertStringContainsString('location ^~ /build/assets/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        self::assertStringContainsString('max-age=300', $nginx);

        $mutableAssets = substr(
            $nginx,
            (int) strpos($nginx, 'location ~* \\.(?:css|js'),
            (int) strpos($nginx, 'location / {') - (int) strpos($nginx, 'location ~* \\.(?:css|js'),
        );

        self::assertStringNotContainsString('immutable', $mutableAssets);
    }

    public function test_candidate_and_production_seed_entrypoints_cannot_import_demo_content(): void
    {
        $candidateEnvironment = $this->read('.env.ui-v3.example');
        $databaseSeeder = $this->read('database/seeders/DatabaseSeeder.php');
        $installCommand = $this->read('app/Console/Commands/GeoFlowInstallCommand.php');

        self::assertStringContainsString('GEOFLOW_SEED_FRONTEND_DEMO=false', $candidateEnvironment);
        self::assertStringContainsString('GEOFLOW_SEED_FRONTEND_DEMO_OVERWRITE=false', $candidateEnvironment);
        self::assertStringNotContainsString('FrontendDemoSeeder', $databaseSeeder);
        self::assertStringNotContainsString('seed_frontend_demo', $databaseSeeder);
        self::assertStringNotContainsString('FrontendDemoSeeder', $installCommand);
        self::assertStringNotContainsString('seed_frontend_demo', $installCommand);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).'/'.$path);
        self::assertIsString($contents);

        return $contents;
    }

    private function serviceBlock(string $compose, string $service, ?string $nextService): string
    {
        $start = strpos($compose, "\n  {$service}:\n");
        self::assertNotFalse($start, "Missing {$service} service.");

        if ($nextService === null) {
            return substr($compose, (int) $start);
        }

        $end = strpos($compose, "\n  {$nextService}:\n", (int) $start + 1);
        self::assertNotFalse($end, "Missing {$nextService} service after {$service}.");

        return substr($compose, (int) $start, (int) $end - (int) $start);
    }
}
