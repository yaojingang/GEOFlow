<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AdminAiModelAccessSurfaceArchitectureTest extends TestCase
{
    private const DIRECT_ACCESS_PATTERN = '/AiModel::(?:query|where|find|findOrFail|all|first|create)|new AiModel/';

    public function test_the_baseline_inventory_remains_complete_and_classified(): void
    {
        $manifest = $this->manifest();

        self::assertSame(35, $manifest['baseline']['file_count']);
        self::assertSame(83, $manifest['baseline']['call_count']);
        self::assertCount(35, $manifest['baseline']['files']);
        foreach ($manifest['baseline']['files'] as $file => $category) {
            self::assertContains($category, $this->allowedCategories(), $file);
        }
    }

    public function test_every_current_direct_ai_model_access_is_explicitly_owned_and_counted(): void
    {
        $manifest = $this->manifest()['current'];
        $actual = [];

        foreach ($this->phpFiles($this->basePath('app')) as $absolutePath) {
            $source = (string) file_get_contents($absolutePath);
            $count = preg_match_all(self::DIRECT_ACCESS_PATTERN, $source);
            if ($count > 0) {
                $actual[$this->relativePath($absolutePath)] = $count;
            }
        }
        ksort($actual);

        self::assertSame(array_keys($manifest), array_keys($actual));
        foreach ($manifest as $file => $entry) {
            self::assertContains($entry['category'], $this->allowedCategories(), $file);
            self::assertNotSame('', trim((string) $entry['owner']), $file);
            self::assertSame($entry['calls'], $actual[$file], $file);
        }
    }

    public function test_provider_boundaries_have_a_declared_usage_owner_and_no_shadow_bypass_exists(): void
    {
        $manifest = $this->manifest();
        foreach ($manifest['provider_boundaries'] as $file => $usageOwner) {
            self::assertFileExists($this->basePath($file));
            self::assertNotSame('', trim((string) $usageOwner), $file);
        }

        foreach ($this->phpFiles($this->basePath('app')) as $absolutePath) {
            $source = (string) file_get_contents($absolutePath);
            self::assertStringNotContainsString('resolveModelCandidatesForShadow', $source, $absolutePath);
            self::assertStringNotContainsString('assertModelCurrentForShadow', $source, $absolutePath);
            self::assertStringNotContainsString('class AiStructuredOutputHealthCheck', $source, $absolutePath);
        }
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return require $this->basePath('tests/Fixtures/admin_ai_model_access_surface.php');
    }

    /** @return list<string> */
    private function allowedCategories(): array
    {
        return ['user_content', 'system', 'governance', 'migration'];
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function relativePath(string $absolutePath): string
    {
        return ltrim(str_replace($this->basePath(), '', $absolutePath), DIRECTORY_SEPARATOR);
    }

    private function basePath(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);

        return $path === '' ? $base : $base.DIRECTORY_SEPARATOR.$path;
    }
}
