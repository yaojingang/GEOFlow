<?php

namespace Tests\Unit;

use Tests\TestCase;

class InstalledThemeDeploymentConfigurationTest extends TestCase
{
    public function test_production_theme_assets_fall_through_to_application_while_builtin_assets_stay_static(): void
    {
        $nginx = file_get_contents(base_path('docker/nginx/default.conf.template'));
        $start = strpos($nginx, 'location ^~ /themes/');
        $end = strpos($nginx, 'location ~*', $start);
        $themes = substr($nginx, $start, $end - $start);
        $this->assertStringContainsString('try_files $uri /index.php?$query_string;', $themes);
        $this->assertStringContainsString('return 404;', $themes);
        $this->assertStringContainsString('X-Content-Type-Options nosniff', $themes);
        $this->assertStringNotContainsString('application/zip', $themes);
        $compose = file_get_contents(base_path('docker-compose.prod.yml'));
        $this->assertStringContainsString('./storage:/var/www/html/storage', $compose);
        $this->assertStringNotContainsString('geoflow-site-themes:/var/www/html/public', $compose);
    }
}
