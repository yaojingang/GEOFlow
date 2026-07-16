<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ManagedImageFileService;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManagedImageFileServiceTest extends TestCase
{
    #[DataProvider('invalidManagedPaths')]
    public function test_rejects_untrusted_path_shapes(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ManagedImageFileService::class)->canonicalizeExistingPath($path);
    }

    /**
     * @return array<string,array{string}>
     */
    public static function invalidManagedPaths(): array
    {
        return [
            'empty' => [''],
            'absolute' => ['/tmp/image.png'],
            'drive absolute' => ['C:/tmp/image.png'],
            'UNC' => ['//server/share/image.png'],
            'parent segment' => ['storage/uploads/images/../escape.png'],
            'dot segment' => ['storage/uploads/images/./escape.png'],
            'backslash' => ['storage\\uploads\\images\\escape.png'],
            'percent encoded' => ['storage/uploads/images/%2e%2e/escape.png'],
            'NUL' => ["storage/uploads/images/\0escape.png"],
            'control character' => ["storage/uploads/images/escape\x1F.png"],
            'duplicate separator' => ['storage/uploads/images//escape.png'],
            'URI scheme' => ['file://storage/uploads/images/escape.png'],
            'current prefix lookalike' => ['storage/uploads/images-escape/image.png'],
            'legacy prefix lookalike' => ['uploads/images-escape/image.png'],
        ];
    }

    public function test_accepts_an_existing_current_managed_file(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/images/2026/07/current.png', 'image');

        $path = app(ManagedImageFileService::class)
            ->canonicalizeExistingPath('storage/uploads/images/2026/07/current.png');

        $this->assertSame('storage/uploads/images/2026/07/current.png', $path);
    }

    public function test_rejects_any_percent_character_in_an_existing_managed_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('uploads/images/2026/07/a%zz.png', 'image');

        $this->expectException(InvalidArgumentException::class);

        app(ManagedImageFileService::class)
            ->canonicalizeExistingPath('storage/uploads/images/2026/07/a%zz.png');
    }

    public function test_accepts_an_existing_legacy_managed_file(): void
    {
        $relativePath = 'uploads/images/'.uniqid('legacy-', true).'.png';
        $absolutePath = public_path($relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0755, true);
        }
        file_put_contents($absolutePath, 'image');

        try {
            $path = app(ManagedImageFileService::class)->canonicalizeExistingPath($relativePath);

            $this->assertSame($relativePath, $path);
        } finally {
            if (is_file($absolutePath)) {
                unlink($absolutePath);
            }
        }
    }

    public function test_rejects_a_symlink_in_an_existing_segment(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported.');
        }

        $outsideDirectory = sys_get_temp_dir().'/geoflow-image-outside-'.bin2hex(random_bytes(4));
        mkdir($outsideDirectory);
        file_put_contents($outsideDirectory.'/escape.png', 'sentinel');
        $linkPath = public_path('uploads/images/link-'.bin2hex(random_bytes(4)));
        if (! is_dir(dirname($linkPath))) {
            mkdir(dirname($linkPath), 0755, true);
        }
        symlink($outsideDirectory, $linkPath);

        try {
            $this->expectException(InvalidArgumentException::class);
            app(ManagedImageFileService::class)->canonicalizeExistingPath(
                'uploads/images/'.basename($linkPath).'/escape.png'
            );
        } finally {
            if (is_link($linkPath)) {
                unlink($linkPath);
            }
            if (is_file($outsideDirectory.'/escape.png')) {
                unlink($outsideDirectory.'/escape.png');
            }
            if (is_dir($outsideDirectory)) {
                rmdir($outsideDirectory);
            }
        }
    }

    public function test_rejects_a_symlink_in_the_managed_root_segments(): void
    {
        if (! function_exists('symlink')) {
            $this->markTestSkipped('Symlinks are not supported.');
        }

        Storage::fake('public');
        $outsideDirectory = sys_get_temp_dir().'/geoflow-root-outside-'.bin2hex(random_bytes(4));
        mkdir($outsideDirectory.'/images', 0755, true);
        file_put_contents($outsideDirectory.'/images/escape.png', 'sentinel');
        $uploadsLink = Storage::disk('public')->path('uploads');
        symlink($outsideDirectory, $uploadsLink);

        try {
            $this->expectException(InvalidArgumentException::class);
            app(ManagedImageFileService::class)
                ->canonicalizeExistingPath('storage/uploads/images/escape.png');
        } finally {
            if (is_link($uploadsLink)) {
                unlink($uploadsLink);
            }
            if (is_file($outsideDirectory.'/images/escape.png')) {
                unlink($outsideDirectory.'/images/escape.png');
            }
            if (is_dir($outsideDirectory.'/images')) {
                rmdir($outsideDirectory.'/images');
            }
            if (is_dir($outsideDirectory)) {
                rmdir($outsideDirectory);
            }
        }
    }

    public function test_rejects_a_non_regular_file(): void
    {
        $directoryPath = public_path('uploads/images/directory-'.bin2hex(random_bytes(4)));
        mkdir($directoryPath, 0755, true);

        try {
            $this->expectException(InvalidArgumentException::class);
            app(ManagedImageFileService::class)->canonicalizeExistingPath(
                'uploads/images/'.basename($directoryPath)
            );
        } finally {
            if (is_dir($directoryPath)) {
                rmdir($directoryPath);
            }
        }
    }
}
