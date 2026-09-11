<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InfrastructureGatewayConfigurationTest extends TestCase
{
    public function test_local_compose_exposes_only_the_nginx_gateway_for_http_and_reverb(): void
    {
        $compose = $this->read('docker-compose.yml');
        $init = $this->serviceBlock($compose, 'init', 'app');
        $app = $this->serviceBlock($compose, 'app', 'web');
        $web = $this->serviceBlock($compose, 'web', 'queue');
        $reverb = $this->serviceBlock($compose, 'reverb', null);

        self::assertStringContainsString('TRUSTED_PROXIES: "${TRUSTED_PROXIES:-REMOTE_ADDR}"', $init);
        self::assertStringContainsString('TRUSTED_PROXIES: "${TRUSTED_PROXIES:-REMOTE_ADDR}"', $app);
        self::assertStringNotContainsString("\n    ports:", $app);
        self::assertStringContainsString('"--no-reload"', $app);
        self::assertStringContainsString('"127.0.0.1:${APP_PORT:-18080}:80"', $web);
        self::assertStringContainsString('GEOFLOW_FORWARDED_PREFIX: "${GEOFLOW_FORWARDED_PREFIX:-}"', $web);
        self::assertStringContainsString('./docker/nginx/local.conf:/etc/nginx/templates/default.conf.template:ro', $web);
        self::assertStringNotContainsString("\n    ports:", $reverb);
        self::assertStringContainsString("\n    expose:\n      - \"8080\"", $reverb);
        self::assertStringNotContainsString("\n  horizon:\n", $compose);
    }

    public function test_fresh_checkout_configuration_matches_the_documented_local_gateway(): void
    {
        $localEnvironment = $this->read('.env.example');
        $uiV3Environment = $this->read('.env.ui-v3.example');
        $productionEnvironment = $this->read('.env.prod.example');
        $deploymentGuide = $this->read('docs/deployment/DEPLOYMENT.md');
        $readme = $this->read('README.md');

        self::assertStringContainsString('APP_URL=http://localhost:18080', $localEnvironment);
        self::assertStringContainsString('SITE_URL="${APP_URL}"', $localEnvironment);
        self::assertStringContainsString('APP_PORT=18080', $localEnvironment);
        self::assertStringContainsString('ADMIN_BASE_PATH=geo_admin', $localEnvironment);
        self::assertStringContainsString('GEOFLOW_PRIMARY_HOSTS=localhost', $localEnvironment);
        self::assertStringContainsString('REVERB_HOST=localhost', $localEnvironment);
        self::assertStringContainsString('REVERB_PORT=18080', $localEnvironment);
        self::assertStringContainsString('REVERB_SCHEME=http', $localEnvironment);
        self::assertStringContainsString('REVERB_ALLOWED_ORIGINS=localhost', $localEnvironment);
        self::assertStringContainsString("\nGEOFLOW_FORWARDED_PREFIX=\n", $localEnvironment);
        self::assertStringContainsString("\nGEOFLOW_FORWARDED_PREFIX=\n", $uiV3Environment);
        self::assertStringContainsString('GEOFLOW_FORWARDED_PREFIX=/wiki', $deploymentGuide);
        self::assertStringContainsString('升级后应先补充此变量，再重建 `web` 容器', $deploymentGuide);
        self::assertStringContainsString('http://localhost:18080', $readme);
        self::assertStringContainsString('http://localhost:18080/geo_admin/login', $readme);

        self::assertStringContainsString('APP_URL=https://your-domain.com', $productionEnvironment);
        self::assertStringContainsString(
            'GEOFLOW_PRIMARY_HOSTS=your-domain.com,www.your-domain.com',
            $productionEnvironment,
        );
    }

    public function test_ui_v3_compose_uses_the_local_nginx_template_mount(): void
    {
        $compose = $this->read('docker-compose.ui-v3.yml');
        $web = $this->serviceBlock($compose, 'web', 'queue');

        self::assertStringContainsString('./docker/nginx/local.conf:/etc/nginx/templates/default.conf.template:ro', $web);
        self::assertStringNotContainsString('/etc/nginx/conf.d/default.conf', $web);
    }

    public function test_local_nginx_serves_fingerprinted_assets_and_same_origin_reverb(): void
    {
        $nginx = $this->read('docker/nginx/local.conf');

        self::assertStringContainsString('location ^~ /build/assets/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        $this->assertPwaAssetsRemainRevalidatable($nginx);
        self::assertStringContainsString('location ~ ^/reverb/(app|apps)/', $nginx);
        self::assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $nginx);
        self::assertStringContainsString('set $geoflow_reverb reverb:8080;', $nginx);
        self::assertStringContainsString('add_header Cache-Control "no-store" always;', $nginx);
        self::assertStringContainsString(<<<'NGINX'
map $http_x_forwarded_proto $geoflow_forwarded_proto {
    default $scheme;
    ~*^http$ http;
    ~*^https$ https;
}
NGINX, $nginx);
        self::assertStringContainsString('map $http_x_forwarded_port $geoflow_forwarded_port_header {', $nginx);
        self::assertStringContainsString(<<<'NGINX'
map "$geoflow_forwarded_port_header:$geoflow_host_port:$geoflow_forwarded_proto" $geoflow_forwarded_port {
    ~^([0-9]+): $1;
    ~^:([0-9]+): $1;
    ~^::https$ 443;
    default $server_port;
}
NGINX, $nginx);
        $proxyLocations = [
            $this->nginxLocationBlock($nginx, 'location ~ ^/reverb/(app|apps)/ {'),
            $this->nginxLocationBlock($nginx, 'location @geoflow_app {'),
        ];

        foreach ($proxyLocations as $location) {
            self::assertSame(1, substr_count($location, 'proxy_set_header X-Forwarded-Proto $geoflow_forwarded_proto;'));
            self::assertSame(1, substr_count($location, 'proxy_set_header X-Forwarded-Port $geoflow_forwarded_port;'));
            self::assertSame(1, substr_count($location, 'proxy_set_header X-Forwarded-Prefix $geoflow_forwarded_prefix;'));
            self::assertStringNotContainsString('proxy_set_header X-Forwarded-Proto $scheme;', $location);
        }
    }

    public function test_local_nginx_accepts_only_valid_forwarded_ports(): void
    {
        $nginx = $this->read('docker/nginx/local.conf');
        $forwardedPortPattern = $this->nginxMapPattern(
            $nginx,
            'map $http_x_forwarded_port $geoflow_forwarded_port_header {',
            '$http_x_forwarded_port',
        );
        $hostPortPattern = $this->nginxMapPattern(
            $nginx,
            'map $http_host $geoflow_host_port {',
            '$1',
        );

        foreach ([
            '1', '80', '443', '8443',
            '9999', '10000', '59999', '60000',
            '64999', '65000', '65499', '65500',
            '65529', '65530', '65535',
        ] as $port) {
            self::assertSame(1, preg_match($forwardedPortPattern, $port), "Forwarded port {$port} should be accepted.");
            self::assertSame(1, preg_match($hostPortPattern, "geo.example.com:{$port}"), "Host port {$port} should be accepted.");
        }

        foreach (['', '0', '00080', '65536', '99999', '443, 80', 'invalid'] as $port) {
            self::assertSame(0, preg_match($forwardedPortPattern, $port), "Forwarded port {$port} should be rejected.");
            self::assertSame(0, preg_match($hostPortPattern, "geo.example.com:{$port}"), "Host port {$port} should be rejected.");
        }
    }

    public function test_local_nginx_accepts_only_bounded_single_segment_forwarded_prefixes(): void
    {
        $nginx = $this->read('docker/nginx/local.conf');
        $forwardedPrefixPattern = $this->nginxMapPattern(
            $nginx,
            'map "${GEOFLOW_FORWARDED_PREFIX}" $geoflow_forwarded_prefix {',
            '"${GEOFLOW_FORWARDED_PREFIX}"',
        );

        self::assertStringNotContainsString('$http_x_forwarded_prefix', $nginx);

        foreach (['/docs', '/wiki-1', '/site_v2', '/a.b~c', '/'.str_repeat('a', 64)] as $prefix) {
            self::assertSame(1, preg_match($forwardedPrefixPattern, $prefix), "Forwarded prefix {$prefix} should be accepted.");
        }

        foreach (['', '/', '/docs/', '/docs/admin', '/../', '//evil', 'https://evil', '/中文', '/'.str_repeat('a', 65)] as $prefix) {
            self::assertSame(0, preg_match($forwardedPrefixPattern, $prefix), "Forwarded prefix {$prefix} should be rejected.");
        }
    }

    public function test_fresh_checkout_uses_an_inert_broadcast_default_until_the_environment_enables_reverb(): void
    {
        $broadcasting = $this->read('config/broadcasting.php');
        $localEnvironment = $this->read('.env.example');
        $productionEnvironment = $this->read('.env.prod.example');

        self::assertStringContainsString("env('BROADCAST_CONNECTION', 'null')", $broadcasting);
        self::assertStringContainsString('BROADCAST_CONNECTION=reverb', $localEnvironment);
        self::assertStringContainsString('BROADCAST_CONNECTION=reverb', $productionEnvironment);
    }

    public function test_production_nginx_does_not_mark_mutable_assets_as_immutable(): void
    {
        $nginx = $this->read('docker/nginx/default.conf.template');

        self::assertStringContainsString('location ^~ /build/assets/', $nginx);
        self::assertStringContainsString('max-age=31536000, immutable', $nginx);
        self::assertStringContainsString('max-age=300', $nginx);
        $this->assertPwaAssetsRemainRevalidatable($nginx);

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

    private function nginxMapPattern(string $nginx, string $mapDeclaration, string $result): string
    {
        $mapStart = strpos($nginx, $mapDeclaration);
        self::assertNotFalse($mapStart);
        $mapEnd = strpos($nginx, "\n}", $mapStart);
        self::assertNotFalse($mapEnd);
        $map = substr($nginx, $mapStart, $mapEnd - $mapStart);
        self::assertIsString($map);
        self::assertStringContainsString('default "";', $map);

        $matched = preg_match('/"~(?<pattern>[^"]+)"\\s+'.preg_quote($result, '/').';/', $map, $matches);
        self::assertSame(1, $matched);

        return '~'.str_replace('~', '\\~', $matches['pattern']).'~D';
    }

    private function nginxLocationBlock(string $nginx, string $declaration): string
    {
        $locationStart = strpos($nginx, "    {$declaration}\n");
        self::assertNotFalse($locationStart, "Missing {$declaration} location.");
        $locationEnd = strpos($nginx, "\n    }", $locationStart);
        self::assertNotFalse($locationEnd, "Missing closing brace for {$declaration} location.");

        return substr($nginx, $locationStart, $locationEnd - $locationStart + strlen("\n    }"));
    }

    private function assertPwaAssetsRemainRevalidatable(string $nginx): void
    {
        self::assertStringContainsString('location = /manifest.webmanifest', $nginx);
        self::assertStringContainsString('location = /service-worker.js', $nginx);
        self::assertStringContainsString('default_type application/manifest+json;', $nginx);
        self::assertStringContainsString('add_header Service-Worker-Allowed "/" always;', $nginx);
        self::assertGreaterThanOrEqual(2, substr_count($nginx, 'no-cache, max-age=0, must-revalidate'));
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
