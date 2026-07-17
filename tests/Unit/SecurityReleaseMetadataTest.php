<?php

namespace Tests\Unit;

use Tests\TestCase;

class SecurityReleaseMetadataTest extends TestCase
{
    public function test_v211_manifest_uses_immutable_release_urls_and_security_upgrade_guidance(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('version.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload = $manifest['payload'];

        $this->assertSame('2.1.1', $manifest['version']);
        $this->assertSame('2026-07-17', $manifest['release_date']);
        $this->assertSame('patch', $manifest['release_type']);
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/archive/refs/tags/v2.1.1.zip',
            $manifest['archive_url'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/releases/tag/v2.1.1',
            $payload['release_url'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v2.1.1/docs/CHANGELOG.md',
            $payload['changelog_url_zh'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v2.1.1/docs/CHANGELOG_en.md',
            $payload['changelog_url_en'],
        );

        $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/main.zip', $encoded);
        $this->assertStringNotContainsString('/blob/main/', $encoded);
        foreach (['migrate', 'security-audit', 'queue'] as $requiredText) {
            $this->assertStringContainsString($requiredText, strtolower($payload['upgrade_tip_en']));
        }
        foreach (['删除', '排空', 'readiness'] as $requiredText) {
            $this->assertStringContainsString($requiredText, $payload['upgrade_tip_zh']);
        }
    }

    public function test_security_changelogs_are_synchronized_and_revoke_the_v210_live_editor_claim(): void
    {
        $zh = (string) file_get_contents(base_path('docs/CHANGELOG.md'));
        $en = (string) file_get_contents(base_path('docs/CHANGELOG_en.md'));

        foreach (['v2.1.1', 'JSON-LD', 'managed_path_hash', 'SSRF', 'package-only', 'geoflow:security-audit'] as $term) {
            $this->assertStringContainsString($term, $zh);
            $this->assertStringContainsString($term, $en);
        }
        $this->assertStringContainsString('v2.1.0', $zh);
        $this->assertStringContainsString('在线主题编辑能力已在 v2.1.1 中关闭', $zh);
        $this->assertStringContainsString('live theme editing is disabled in v2.1.1', strtolower($en));
        $this->assertStringNotContainsString('v2.1.1 已发布', $zh);
        $this->assertStringNotContainsString('v2.1.1 has been released', strtolower($en));
    }

    public function test_config_fallback_tracks_v211_without_environment_version_lock(): void
    {
        $config = (string) file_get_contents(config_path('geoflow.php'));
        $envExample = (string) file_get_contents(base_path('.env.example'));
        $productionExample = (string) file_get_contents(base_path('.env.prod.example'));

        $this->assertStringContainsString("\$appVersion !== '' ? \$appVersion : '2.1.1'", $config);
        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $envExample);
        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $productionExample);
    }
}
