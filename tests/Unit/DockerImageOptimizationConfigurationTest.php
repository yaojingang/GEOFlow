<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DockerImageOptimizationConfigurationTest extends TestCase
{
    public function test_local_development_image_contains_only_the_runtime_toolchain(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile');

        $this->assertStringContainsString('COPY --from=composer:2 /usr/bin/composer /usr/bin/composer', $dockerfile);
        $this->assertStringContainsString(
            'COPY docker/entrypoint.sh /usr/local/bin/geoflow-entrypoint',
            $dockerfile,
        );
        $this->assertStringContainsString('ENTRYPOINT ["/usr/local/bin/geoflow-entrypoint"]', $dockerfile);
        $this->assertStringNotContainsString('COPY . .', $dockerfile);
        $this->assertStringNotContainsString('composer install', $dockerfile);
        $this->assertStringNotContainsString('composer dump-autoload', $dockerfile);
    }

    public function test_local_compose_builds_the_shared_application_image_once(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 2).'/docker-compose.yml');
        $services = $this->serviceBlocks($compose);

        $this->assertArrayHasKey('init', $services);
        $this->assertStringContainsString("    build:\n", $services['init']);
        $this->assertSame(1, substr_count($compose, "    build:\n"));

        foreach ($services as $service => $block) {
            if ($service === 'init' || ! str_contains($block, 'image: geoflow-app:latest')) {
                continue;
            }

            $this->assertStringNotContainsString(
                "    build:\n",
                $block,
                sprintf('%s must reuse the image built by init.', $service),
            );
        }
    }

    public function test_docker_build_context_excludes_mutable_and_local_only_content(): void
    {
        $dockerignore = (string) file_get_contents(dirname(__DIR__, 2).'/.dockerignore');
        $patterns = preg_split('/\R/', $dockerignore) ?: [];

        foreach (['storage', 'tests', '.agents', '.claude', '.cursor', 'browser-extension', 'public/build'] as $pattern) {
            $this->assertContains($pattern, $patterns, sprintf('%s must stay out of application images.', $pattern));
        }
    }

    public function test_production_build_recreates_ignored_runtime_directories(): void
    {
        $dockerfile = (string) file_get_contents(dirname(__DIR__, 2).'/docker/Dockerfile.prod');

        foreach ([
            'bootstrap/cache',
            'storage/app/public/uploads/images',
            'storage/app/tmp',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
        ] as $directory) {
            $this->assertStringContainsString($directory, $dockerfile);
        }

        $this->assertLessThan(
            strpos($dockerfile, 'composer dump-autoload'),
            strpos($dockerfile, 'RUN mkdir -p'),
            'Runtime directories must exist before Laravel package discovery runs.',
        );
    }

    /** @return array<string, string> */
    private function serviceBlocks(string $compose): array
    {
        preg_match_all(
            '/^  (?<name>[a-zA-Z0-9_-]+):\R(?<block>(?:(?!^  [a-zA-Z0-9_-]+:\R).)*)/ms',
            $compose,
            $matches,
            PREG_SET_ORDER,
        );

        $services = [];
        foreach ($matches as $match) {
            $services[(string) $match['name']] = (string) $match['block'];
        }

        return $services;
    }
}
