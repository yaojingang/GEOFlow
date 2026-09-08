<?php

namespace Tests\Support;

use App\Services\Admin\SiteThemePackageGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

final class ThemePackageFixture
{
    public static function files(string $id = 'fixture-theme'): array
    {
        return [
            'resources/views/theme/'.$id.'/manifest.json' => json_encode([
                'id' => $id, 'name' => 'Fixture theme', 'version' => '1.2.3',
                'description' => 'Synthetic package fixture.', 'visibility' => 'private',
                'distribution' => ['visibility' => 'private', 'owner' => 'Fixture owner'],
                'private_metadata' => ['can_redistribute' => false],
            ], JSON_THROW_ON_ERROR),
            'resources/views/theme/'.$id.'/home.blade.php' => "@extends('theme.$id.layout')\n@section('content')\nFixture home\n@endsection\n",
            'resources/views/theme/'.$id.'/layout.blade.php' => "<link rel=\"stylesheet\" href=\"{{ asset('themes/$id/theme.css') }}\">\n@yield('content')\n",
            'resources/views/theme/'.$id.'/assets/theme.css' => 'body{color:#123}',
            'public/themes/'.$id.'/theme.css' => 'body{color:#123}',
        ];
    }

    public static function package(array $files, string $id = 'fixture-theme', array $overrides = []): array
    {
        $records = [];
        foreach ($files as $path => $contents) {
            $records[] = ['path' => $path, 'bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        $guard = app(SiteThemePackageGuard::class);

        return array_replace([
            'format' => SiteThemePackageGuard::FORMAT, 'format_version' => 1,
            'theme' => ['id' => $id, 'name' => 'Fixture theme', 'version' => '1.2.3'],
            'created_at' => now()->toIso8601String(),
            'exported_with' => ['geoflow' => config('geoflow.app_version'), 'php' => PHP_VERSION, 'laravel' => app()->version(), 'contracts' => SiteThemePackageGuard::CONTRACTS],
            'requires' => $guard->defaultRequirements(),
            'distribution' => ['visibility' => 'private', 'owner' => 'Fixture owner'],
            'pages' => $guard->pages($records, $id), 'files' => $records,
            'content_sha256' => $guard->contentHash($records),
        ], $overrides);
    }

    public static function archive(array $files, ?array $package = null): UploadedFile
    {
        $path = Storage::disk('local')->path('fixture-'.Str::random(20).'.zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL);
        $zip->addFromString('package.json', json_encode($package ?? self::package($files), JSON_THROW_ON_ERROR));
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile($path, 'theme.zip', 'application/zip', null, true);
    }
}
