<?php

namespace Tests\Unit;

use Tests\TestCase;

class OpenSourceLicenseMetadataTest extends TestCase
{
    public function test_project_license_surfaces_use_agpl_three_only(): void
    {
        $license = (string) file_get_contents(base_path('LICENSE'));
        $composer = $this->jsonFile('composer.json');
        $package = $this->jsonFile('package.json');
        $packageLock = $this->jsonFile('package-lock.json');

        $this->assertStringContainsString('GNU AFFERO GENERAL PUBLIC LICENSE', $license);
        $this->assertSame('AGPL-3.0-only', $composer['license'] ?? null);
        $this->assertSame('AGPL-3.0-only', $package['license'] ?? null);
        $this->assertSame('AGPL-3.0-only', $packageLock['packages']['']['license'] ?? null);

        foreach ($this->readmePaths() as $path) {
            $readme = (string) file_get_contents(base_path($path));

            $this->assertStringContainsString('License-AGPL--3.0', $readme, $path);
            $this->assertStringContainsString('Contributor License Agreement v1.0', $readme, $path);
        }
    }

    public function test_historical_apache_notice_and_contributor_relicensing_terms_are_retained(): void
    {
        $notice = (string) file_get_contents(base_path('NOTICE'));
        $apacheLicense = (string) file_get_contents(base_path('docs/licenses/Apache-2.0.txt'));
        $cla = (string) file_get_contents(base_path('CLA.md'));
        $pullRequestTemplate = (string) file_get_contents(base_path('.github/pull_request_template.md'));

        $this->assertStringContainsString('docs/licenses/Apache-2.0.txt', $notice);
        $this->assertStringContainsString('Apache License', $apacheLicense);
        $this->assertStringContainsString('right to sublicense through multiple tiers', $cla);
        $this->assertStringContainsString('commercial, or proprietary licenses', $cla);
        $this->assertStringContainsString('GEOFlow CLA v1.0', $pullRequestTemplate);
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        return json_decode(
            (string) file_get_contents(base_path($path)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }

    /** @return list<string> */
    private function readmePaths(): array
    {
        return [
            'README.md',
            'docs/readme/README_en.md',
            'docs/readme/README_es.md',
            'docs/readme/README_ja.md',
            'docs/readme/README_pt_BR.md',
            'docs/readme/README_ru.md',
        ];
    }
}
