<?php

namespace Tests\Unit;

use App\Services\Admin\SiteThemeReplication\ThemeReplicationPackagePathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ThemeReplicationPackagePathGuardTest extends TestCase
{
    #[DataProvider('unsafePositiveIntegers')]
    public function test_replication_and_version_identifiers_must_be_canonical_positive_integers(mixed $value): void
    {
        $this->expectException(RuntimeException::class);

        app(ThemeReplicationPackagePathGuard::class)->positiveInteger($value);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unsafePositiveIntegers(): array
    {
        return [
            'zero integer' => [0],
            'negative integer' => [-1],
            'zero string' => ['0'],
            'negative string' => ['-1'],
            'leading zero' => ['01'],
            'decimal' => ['1.0'],
            'exponent' => ['1e2'],
            'path' => ['../1'],
            'unicode digit' => ['１'],
        ];
    }

    #[DataProvider('unsafeRelativePaths')]
    public function test_unsafe_relative_paths_are_rejected(string $path): void
    {
        $this->expectException(RuntimeException::class);

        app(ThemeReplicationPackagePathGuard::class)->assertSafeRelativePath($path);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeRelativePaths(): array
    {
        return [
            'empty' => [''],
            'absolute' => ['/absolute/file.php'],
            'windows drive' => ['C:/escape.php'],
            'nested windows drive' => ['safe/C:/escape.php'],
            'backslash' => ['safe\\escape.php'],
            'empty segment' => ['safe//escape.php'],
            'trailing empty segment' => ['safe/'],
            'dot segment' => ['safe/./escape.php'],
            'parent segment' => ['safe/../escape.php'],
            'nul' => ["safe/evil\0.php"],
            'control' => ["safe/evil\n.php"],
            'unicode confusable' => ['safe/ｅscape.php'],
        ];
    }

    public function test_archive_entry_must_be_a_child_of_its_exact_allowed_prefix(): void
    {
        $guard = app(ThemeReplicationPackagePathGuard::class);

        $guard->assertSafeArchiveEntry(
            'resources/views/theme/safe-theme/home.blade.php',
            'resources/views/theme/safe-theme/'
        );

        foreach ([
            'resources/views/theme/safe-theme',
            'resources/views/theme/safe-theme-evil/home.blade.php',
            'public/themes/safe-theme/theme.css',
        ] as $entry) {
            try {
                $guard->assertSafeArchiveEntry($entry, 'resources/views/theme/safe-theme/');
                $this->fail('Unsafe archive entry was accepted: '.$entry);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_canonical_positive_integer_is_returned_unchanged(): void
    {
        $guard = app(ThemeReplicationPackagePathGuard::class);

        $this->assertSame(1, $guard->positiveInteger(1));
        $this->assertSame(42, $guard->positiveInteger('42'));
    }
}
