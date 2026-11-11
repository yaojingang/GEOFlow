<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminWelcomeUpdateCopyTest extends TestCase
{
    public function test_copy_reads_release_metadata_from_the_manifest_root_before_legacy_payload_fields(): void
    {
        $builder = require app_path('Support/AdminWelcome/update_welcome_copy.php');

        $copy = $builder(['update' => [
            'current_version' => '3.1.0',
            'latest_version' => '3.2.1',
            'release_date' => '2026-11-08',
            'release_type' => 'patch',
            'payload' => ['release_date' => '2000-01-01', 'release_type' => 'feature'],
        ]]);

        $this->assertContains('发布日期：2026-11-08', $copy['zh-CN']['letter']['blocks'][2]['items']);
        $this->assertContains('发布类型：问题修复', $copy['zh-CN']['letter']['blocks'][2]['items']);
        $this->assertContains('Release date: 2026-11-08', $copy['en']['letter']['blocks'][2]['items']);
    }

    public function test_missing_current_version_uses_installed_version_and_legacy_metadata_remains_supported(): void
    {
        config(['geoflow.app_version' => '3.2.1', 'geoflow.welcome_intro_version' => '2.1']);
        $builder = require app_path('Support/AdminWelcome/update_welcome_copy.php');

        $copy = $builder(['update' => [
            'latest_version' => '3.3.0',
            'payload' => ['release_date' => '2026-11-09', 'release_type' => 'security'],
        ]]);

        $this->assertSame('当前版本 v3.2.1，最新版本 v3.3.0。', $copy['zh-CN']['letter']['subtitle']);
        $this->assertContains('发布日期：2026-11-09', $copy['zh-CN']['letter']['blocks'][2]['items']);
        $this->assertContains('发布类型：安全更新', $copy['zh-CN']['letter']['blocks'][2]['items']);
    }

    #[DataProvider('releaseTypes')]
    public function test_release_types_have_consistent_labels(string $type, string $zh, string $en): void
    {
        $builder = require app_path('Support/AdminWelcome/update_welcome_copy.php');

        $copy = $builder(['update' => ['release_type' => $type]]);

        $this->assertContains('发布类型：'.$zh, $copy['zh-CN']['letter']['blocks'][2]['items']);
        $this->assertContains('Release type: '.$en, $copy['en']['letter']['blocks'][2]['items']);
    }

    public static function releaseTypes(): array
    {
        return [
            ['major', '重大更新', 'Major update'],
            ['minor', '功能更新', 'Feature update'],
            ['patch', '问题修复', 'Bug fix'],
            ['feature', '功能更新', 'Feature update'],
            ['fix', '问题修复', 'Bug fix'],
            ['security', '安全更新', 'Security update'],
            ['unknown', '版本更新', 'Release update'],
        ];
    }
}
