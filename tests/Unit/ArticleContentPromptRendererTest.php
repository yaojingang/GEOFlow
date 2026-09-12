<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleContentPromptRenderer;
use Tests\TestCase;

class ArticleContentPromptRendererTest extends TestCase
{
    private const IMAGE_CONTEXT = '- 门店实拍 | /storage/uploads/storefront.jpg'."\n".'- 产品特写 | /storage/uploads/product.jpg';

    public function test_image_context_is_appended_to_custom_prompt_without_variables(): void
    {
        $prompt = $this->renderer()->renderForWorker(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇专业、可信的文章。',
            '这是来自知识库的参考资料。',
            self::IMAGE_CONTEXT
        );

        $this->assertStringContainsString('- 可用配图（备注 | URL）：', $prompt);
        $this->assertStringContainsString('配图使用规则', $prompt);
        $this->assertStringContainsString('- 门店实拍 | /storage/uploads/storefront.jpg', $prompt);
        $this->assertStringContainsString('- 产品特写 | /storage/uploads/product.jpg', $prompt);
        $this->assertStringContainsString('不要编造列表之外的图片地址', $prompt);
    }

    public function test_explicit_images_variable_is_replaced_without_duplicate_append(): void
    {
        $prompt = $this->renderer()->renderForWorker(
            'AI CRM 到底是什么？',
            'AI CRM',
            "标题：{{title}}\n配图候选：\n{{images}}",
            '',
            self::IMAGE_CONTEXT
        );

        $this->assertStringContainsString('标题：AI CRM 到底是什么？', $prompt);
        $this->assertStringContainsString('配图候选：'."\n".self::IMAGE_CONTEXT, $prompt);
        $this->assertSame(1, substr_count((string) $prompt, '/storage/uploads/storefront.jpg'));
        $this->assertStringNotContainsString('【可用配图】', $prompt);
    }

    public function test_if_images_block_renders_only_when_context_present(): void
    {
        $template = '{{#if images}}配图候选：{{images}}{{/if}}'."\n".'标题：{{title}}';

        $withImages = $this->renderer()->renderForWorker('标题一', 'kw', $template, '', self::IMAGE_CONTEXT);
        $withoutImages = $this->renderer()->renderForWorker('标题一', 'kw', $template, '', '');

        $this->assertStringContainsString('配图候选：'.self::IMAGE_CONTEXT, $withImages);
        $this->assertStringNotContainsString('配图候选：', $withoutImages);
        $this->assertStringContainsString('标题：标题一', $withoutImages);
    }

    public function test_empty_image_context_adds_no_image_section(): void
    {
        $prompt = $this->renderer()->renderForWorker(
            'AI CRM 到底是什么？',
            'AI CRM',
            '请写一篇文章。',
            '参考资料。',
            ''
        );

        $this->assertStringNotContainsString('可用配图', $prompt);
        $this->assertStringNotContainsString('配图使用规则', $prompt);
    }

    public function test_english_prompt_receives_english_image_instruction(): void
    {
        $prompt = $this->renderer()->renderForWorker(
            'What is AI CRM?',
            'AI CRM',
            'Write a practical long-form article for business readers.',
            'Reference knowledge.',
            self::IMAGE_CONTEXT
        );

        $this->assertStringContainsString('Available images', $prompt);
        $this->assertStringContainsString('Image usage rule', $prompt);
        $this->assertStringContainsString('- 门店实拍 | /storage/uploads/storefront.jpg', $prompt);
        $this->assertStringNotContainsString('可用配图', $prompt);
    }

    private function renderer(): ArticleContentPromptRenderer
    {
        return app(ArticleContentPromptRenderer::class);
    }
}
