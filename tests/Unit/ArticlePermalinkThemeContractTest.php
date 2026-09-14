<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ArticlePermalinkThemeContractTest extends TestCase
{
    public function test_builtin_themes_do_not_bypass_the_site_url_generator(): void
    {
        $roots = [
            dirname(__DIR__, 2).'/resources/views/site',
            dirname(__DIR__, 2).'/resources/views/theme',
        ];
        $violations = [];

        foreach ($roots as $root) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $contents = file_get_contents($file->getPathname());
                if (is_string($contents)
                    && (str_contains($contents, "route('site.article'") || str_contains($contents, '/article/'))) {
                    $violations[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, '主题仍包含旧文章 URL：'.implode(', ', $violations));
    }
}
