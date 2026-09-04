<?php

namespace Tests\Unit;

use Tests\TestCase;

class SecurityReleaseMetadataTest extends TestCase
{
    public function test_v300_manifest_uses_immutable_release_urls_and_major_upgrade_guidance(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('version.json')), true, flags: JSON_THROW_ON_ERROR);
        $payload = $manifest['payload'];

        $this->assertSame('3.0.0', $manifest['version']);
        $this->assertSame('2026-09-05', $manifest['release_date']);
        $this->assertSame('major', $manifest['release_type']);
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/releases/download/v3.0.0/GEOFlow-v3.0.0.zip',
            $manifest['archive_url'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/releases/tag/v3.0.0',
            $payload['release_url'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v3.0.0/docs/CHANGELOG.md',
            $payload['changelog_url_zh'],
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v3.0.0/docs/CHANGELOG_en.md',
            $payload['changelog_url_en'],
        );

        $encoded = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/main.zip', $encoded);
        $this->assertStringNotContainsString('/blob/main/', $encoded);
        foreach (['migrate', 'security-audit', 'queue', 'wildcard dns'] as $requiredText) {
            $this->assertStringContainsString($requiredText, strtolower($payload['upgrade_tip_en']));
        }
        foreach (['备份', '排空', '泛 DNS', 'readiness'] as $requiredText) {
            $this->assertStringContainsString($requiredText, $payload['upgrade_tip_zh']);
        }
    }

    public function test_major_release_changelogs_are_synchronized_and_keep_security_history(): void
    {
        $zh = (string) file_get_contents(base_path('docs/CHANGELOG.md'));
        $en = (string) file_get_contents(base_path('docs/CHANGELOG_en.md'));

        foreach (['v2.1.1', 'JSON-LD', 'managed_path_hash', 'SSRF', 'package-only', 'geoflow:security-audit'] as $term) {
            $this->assertStringContainsString($term, $zh);
            $this->assertStringContainsString($term, $en);
        }
        foreach (['v2.1.2', 'v2.2.0', 'v2.3.0', 'v3.0.0', 'Admin UI V3', 'PWA', 'CLI'] as $term) {
            $this->assertStringContainsString($term, $zh);
            $this->assertStringContainsString($term, $en);
        }
        $this->assertStringContainsString('后台帮助助手', $zh);
        $this->assertStringContainsString('admin help assistant', strtolower($en));
        $this->assertStringContainsString('托管渠道站点', $zh);
        $this->assertStringContainsString('hosted channel site', strtolower($en));
        $this->assertStringContainsString('v2.1.0', $zh);
        $this->assertStringContainsString('在线主题编辑能力已在 v2.1.1 中关闭', $zh);
        $this->assertStringContainsString('live theme editing is disabled in v2.1.1', strtolower($en));
        $this->assertStringContainsString("## 2026-09-05\n\n### v3.0.0", $zh);
        $this->assertStringContainsString("## 2026-09-05\n\n### v3.0.0", $en);
    }

    public function test_environment_examples_do_not_lock_the_application_version(): void
    {
        $envExample = (string) file_get_contents(base_path('.env.example'));
        $productionExample = (string) file_get_contents(base_path('.env.prod.example'));

        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $envExample);
        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $productionExample);
    }

    public function test_v300_release_runbook_requires_the_stable_metadata_asset_and_signed_updater_gate(): void
    {
        $runbook = (string) file_get_contents(base_path('docs/deployment/GEOFLOW_V3_RELEASE.md'));
        $notes = (string) file_get_contents(base_path('docs/deployment/GEOFLOW_V3_RELEASE_NOTES.md'));

        foreach ([
            'GEOFlow-v3.0.0.zip',
            'GEOFlow-v3.0.0.zip.sha256',
            'releases/latest/download/version.json',
            'release_sequence=2',
            'linux/amd64',
            'linux/arm64',
            '--draft',
            '--latest',
            'immutable-releases',
            'refs/tags/v*',
            'index("creation")',
            'set -euo pipefail',
            'GEOFLOW_RELEASE_SIGNER_FINGERPRINT',
            'GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256',
            'collaborators?affiliation=direct',
            'default_workflow_permissions == "read"',
            'all(.[][]; .read_only == true)',
            'contents:[[:space:]]*write',
            'git verify-tag --raw v3.0.0',
            'GEOFLOW_REMOTE_TAG_COMMIT',
            'GEOFLOW_FINAL_VERIFY_DIR',
            'curl -fsSL https://github.com/yaojingang/GEOFlow/releases/latest/download/version.json | cmp',
            'gh release verify-asset v3.0.0',
        ] as $requiredText) {
            $this->assertStringContainsString($requiredText, $runbook);
        }
        foreach (['GEOFlow Updater v0.3.0', 'AGPL-3.0-only', 'Admin UI V3'] as $requiredText) {
            $this->assertStringContainsString($requiredText, $notes);
        }
        $this->assertGreaterThanOrEqual(8, substr_count($runbook, 'set -euo pipefail'));
        $this->assertGreaterThanOrEqual(2, substr_count($runbook, '.enabled == true'));
        $this->assertGreaterThanOrEqual(2, substr_count($runbook, 'index("creation")'));
        $this->assertGreaterThanOrEqual(3, substr_count($runbook, 'GEOFLOW_RELEASE_ACCESS_EVIDENCE_SHA256'));
        $this->assertGreaterThanOrEqual(3, substr_count($runbook, 'collaborators?affiliation=direct'));
    }
}
