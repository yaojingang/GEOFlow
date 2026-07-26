<?php

namespace Tests\Unit;

use Tests\TestCase;

class ReleaseVersionMappingTest extends TestCase
{
    public function test_manifest_version_is_semver_and_application_config_tracks_it(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('version.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertMatchesRegularExpression(
            '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D',
            $manifest['version'],
        );
        $this->assertSame($manifest['version'], config('geoflow.app_version'));
    }

    public function test_config_uses_a_development_version_when_the_manifest_is_unavailable(): void
    {
        $configSource = (string) file_get_contents(config_path('geoflow.php'));

        $this->assertStringContainsString(
            "\$appVersion = \$appVersion !== '' ? \$appVersion : '0.0.0-dev';",
            $configSource,
        );
    }

    public function test_release_metadata_is_derived_from_the_manifest_tag(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(base_path('version.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('tag', $manifest);

        $version = $manifest['version'];
        $tag = $manifest['tag'];
        $payload = $manifest['payload'];

        $this->assertSame("v{$version}", $tag);
        $this->assertSame(
            "https://github.com/yaojingang/GEOFlow/archive/refs/tags/{$tag}.zip",
            $manifest['archive_url'],
        );
        $this->assertSame(
            "https://github.com/yaojingang/GEOFlow/releases/tag/{$tag}",
            $payload['release_url'],
        );
        $this->assertSame(
            "https://github.com/yaojingang/GEOFlow/blob/{$tag}/docs/CHANGELOG.md",
            $payload['changelog_url_zh'],
        );
        $this->assertSame(
            "https://github.com/yaojingang/GEOFlow/blob/{$tag}/docs/CHANGELOG_en.md",
            $payload['changelog_url_en'],
        );
        $this->assertSame("GEOFlow v{$version}", $payload['title_zh']);
        $this->assertSame("GEOFlow v{$version}", $payload['title_en']);
    }
}
