<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminWelcomeIntroCopyTest extends TestCase
{
    public function test_intro_copy_is_updated_for_version_2_first_deployment_guide(): void
    {
        /** @var array<string, array<string, mixed>> $copy */
        $copy = require app_path('Support/AdminWelcome/intro_copy.php');

        $this->assertSame('GEOFlow 2.0 首次部署说明', $copy['zh-CN']['letter']['title']);
        $this->assertSame('GEOFlow 2.0 First Deployment Guide', $copy['en']['letter']['title']);
        $this->assertStringContainsString('分发管理', $this->flattenCopy($copy['zh-CN']['letter']['blocks']));
        $this->assertStringContainsString('数据分析', $this->flattenCopy($copy['zh-CN']['letter']['blocks']));
        $this->assertStringContainsString('Distribution management', $this->flattenCopy($copy['en']['letter']['blocks']));
        $this->assertStringContainsString('Analytics', $this->flattenCopy($copy['en']['letter']['blocks']));
        $this->assertStringContainsString('首次部署说明', $copy['zh-CN']['meta']['badge']);
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
