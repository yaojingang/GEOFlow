<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminWelcomeIntroCopyTest extends TestCase
{
    public function test_intro_copy_presents_version_three_positioning_capabilities_and_use_cases(): void
    {
        /** @var array<string, array<string, mixed>> $copy */
        $copy = require app_path('Support/AdminWelcome/intro_copy.php');

        $zhBlocks = $this->flattenCopy($copy['zh-CN']['letter']['blocks']);
        $enBlocks = $this->flattenCopy($copy['en']['letter']['blocks']);

        $this->assertSame('欢迎使用 GEOFlow 3.0', $copy['zh-CN']['letter']['title']);
        $this->assertSame('Welcome to GEOFlow 3.0', $copy['en']['letter']['title']);
        $this->assertStringContainsString('企业官网、行业信源平台和内部内容运营', $copy['zh-CN']['letter']['subtitle']);
        $this->assertStringContainsString('corporate websites, vertical source platforms, and internal content operations', $copy['en']['letter']['subtitle']);
        $this->assertStringContainsString('内部内容管理系统', $zhBlocks);
        $this->assertStringContainsString('内部知识库系统', $zhBlocks);
        $this->assertStringContainsString('内容生成管理系统', $zhBlocks);
        $this->assertStringContainsString('Internal content management systems', $enBlocks);
        $this->assertStringContainsString('Internal knowledge-base systems', $enBlocks);
        $this->assertStringContainsString('Content generation and operations', $enBlocks);
        $this->assertStringContainsString('Admin UI V3', $zhBlocks);
        $this->assertStringContainsString('文章质检', $zhBlocks);
        $this->assertStringContainsString('Chrome 运营助手', $zhBlocks);
        $this->assertStringContainsString('PWA', $enBlocks);
        $this->assertStringContainsString('article AI quality inspection', $enBlocks);
        $this->assertSame('GEOFlow 3.0', $copy['zh-CN']['meta']['badge']);
        $this->assertSame('GEOFlow 3.0', $copy['en']['meta']['badge']);
        $this->assertSame(array_keys($copy['zh-CN']['meta']), array_keys($copy['en']['meta']));
        $this->assertSame(
            array_column($copy['zh-CN']['letter']['blocks'], 'type'),
            array_column($copy['en']['letter']['blocks'], 'type')
        );
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
        $expected = 'GEOFLOW_WELCOME_INTRO_VERSION=3.0';
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
