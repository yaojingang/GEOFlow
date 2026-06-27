<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminWelcomeIntroCopyTest extends TestCase
{
    public function test_intro_copy_reads_as_project_letter_with_geo_basics(): void
    {
        /** @var array<string, array<string, mixed>> $copy */
        $copy = require app_path('Support/AdminWelcome/intro_copy.php');

        $this->assertSame('写给 GEOFlow 使用者的一封信', $copy['zh-CN']['letter']['title']);
        $this->assertSame('A Letter to GEOFlow Users', $copy['en']['letter']['title']);
        $this->assertStringContainsString('提高被理解、引用和推荐的概率', $copy['zh-CN']['letter']['subtitle']);
        $this->assertStringContainsString('不承诺排名', $copy['zh-CN']['letter']['subtitle']);
        $this->assertStringContainsString('GEO 优化的是答案引擎采纳事实、观点和页面的概率', $this->flattenCopy($copy['zh-CN']['letter']['blocks']));
        $this->assertStringContainsString('观测归因', $this->flattenCopy($copy['zh-CN']['letter']['blocks']));
        $this->assertStringContainsString('WordPress', $this->flattenCopy($copy['en']['letter']['blocks']));
        $this->assertStringContainsString('GEO optimizes the probability', $this->flattenCopy($copy['en']['letter']['blocks']));
        $this->assertStringContainsString('Project Intro', $copy['en']['meta']['badge']);
        $this->assertStringContainsString('项目说明', $copy['zh-CN']['meta']['badge']);
    }

    public function test_welcome_modal_uses_compact_white_kami_document_layout(): void
    {
        $html = view('admin.partials.welcome-modal', [
            'adminWelcomeModalPayload' => [
                'copy' => [],
                'state' => [
                    'shouldAutoOpen' => false,
                ],
            ],
        ])->render();

        $this->assertStringContainsString('data-kami-document', $html);
        $this->assertStringContainsString('admin-welcome-document', $html);
        $this->assertStringContainsString('bg-white', $html);
        $this->assertStringContainsString('--admin-welcome-title-size: 24px;', $html);
        $this->assertStringContainsString('--admin-welcome-body-size: 14px;', $html);
        $this->assertStringContainsString('--admin-welcome-body-leading: 1.52;', $html);
        $this->assertStringContainsString('border-left: 3px solid #1B365D;', $html);
        $this->assertStringContainsString('admin-welcome-document-body', $html);
        $this->assertStringNotContainsString('text-4xl', $html);
        $this->assertStringNotContainsString('sm:text-5xl', $html);
        $this->assertStringNotContainsString('text-[28px]', $html);
        $this->assertStringNotContainsString('text-[17px]', $html);
        $this->assertStringNotContainsString('text-[15px]', $html);
        $this->assertStringNotContainsString('bg-[#f5f4ed]', $html);
        $this->assertStringNotContainsString('bg-[#faf9f5]', $html);
    }

    public function test_deployment_env_examples_use_current_intro_version(): void
    {
        $expected = 'GEOFLOW_WELCOME_INTRO_VERSION=2.1';
        $devEnv = file_get_contents(base_path('.env.example'));
        $prodEnv = file_get_contents(base_path('.env.prod.example'));

        $this->assertStringContainsString($expected, $devEnv);
        $this->assertStringContainsString($expected, $prodEnv);
        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $devEnv);
        $this->assertStringNotContainsString('GEOFLOW_APP_VERSION=', $prodEnv);
    }

    public function test_app_version_defaults_to_local_version_manifest(): void
    {
        $manifest = json_decode((string) file_get_contents(base_path('version.json')), true);

        $this->assertIsArray($manifest);
        $this->assertSame($manifest['version'], config('geoflow.app_version'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private function flattenCopy(array $blocks): string
    {
        $text = [];

        foreach ($blocks as $block) {
            if (isset($block['content'])) {
                $text[] = (string) $block['content'];
            }

            foreach (($block['items'] ?? []) as $item) {
                $text[] = (string) $item;
            }
        }

        return implode("\n", $text);
    }
}
